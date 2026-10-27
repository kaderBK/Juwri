<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\AppInstallation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Contracts\Billing\FeatureCommerce;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\FeatureGrant;
use Juwri\Core\Models\User;
use Juwri\Core\Support\BrandContext;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Marketing\Models\AbExperiment;
use Juwri\Modules\Marketing\Models\AbImpression;

/**
 * F2 (rev/upsell-visibility): the one thing every spotlight surface asks.
 *
 * Gated features are invisible in both directions. Someone who does NOT have a
 * key sees a grey padlock with no price; someone who DOES have it is told
 * nothing, so a feature included in their plan looks identical to one they never
 * bought and never gets switched on. This class answers both questions with one
 * state machine, and — just as importantly — owns the guardrails, so no caller
 * can decide on its own to nag.
 *
 * Every surface (nav lock, teaser, ribbon, trial chip, toast) calls for() to
 * decide what to render and record() to say what happened. Nothing else may
 * write feature_spotlight_events.
 */
class FeatureSpotlight
{
    /** Surfaces that are a proactive interruption rather than page furniture. */
    public const NUDGE_SURFACES = ['toast', 'ribbon'];

    /** Surfaces and actions a spotlight event may record (Billing's FeatureSpotlightEvent keeps the same lists). */
    public const SURFACES = ['nav', 'teaser', 'ribbon', 'trial_chip', 'locked_page', 'toast', 'preview'];

    public const ACTIONS = ['impression', 'click', 'dismiss', 'convert'];

    /**
     * Surfaces that fire ONCE EVER per key: seeing it a second time is nagging.
     *
     * The ribbon is deliberately NOT one of these (WI-B1). Retiring it on the
     * first impression means scrolling past it — or a page that reloads after a
     * save — silently consumes the one moment the owner had to learn they own
     * the feature. It stays until they dismiss it, which is the click path the
     * work item specifies: visible, reload, still visible, dismiss, gone.
     */
    public const ONCE_SURFACES = ['toast'];

    /** WI-C3: the shipped experiment — locked page hero CTA wording. */
    public const EXPERIMENT_LOCKED_CTA = 'spotlight_locked_cta';

    /** Session key holding the nudge already spent this session. */
    public const SESSION_NUDGE = 'feature_spotlight.nudge';

    /**
     * A brand-new account is being walked through the first-week checklist; an
     * upsell on top of that competes with onboarding and converts worse.
     * Suppresses the UNENTITLED side only — telling a new owner what they
     * already own is onboarding, not upsell.
     */
    public const ONBOARDING_DAYS = 7;

    /**
     * WI-A3: read-only dashboards an unentitled owner may SEE, blurred, with
     * their own real (aggregated) data behind it. Key => route name.
     *
     * Teasing a glimpse of the real screen beats describing it, and these three
     * cost nothing to render because they only read. Anything that writes, or
     * that costs money per call, is deliberately absent.
     */
    public const PREVIEWABLE = [
        'crm_suite' => 'crm.rfm',
        'demand_forecast' => 'stores.deliveryInsights.index',
    ];

    /** Per-request memo — one page render asks about many keys. */
    protected static array $memo = [];

    /**
     * PERF: per-request cache of each owner's active trial installs, keyed by
     * owner id. trial() is asked about ~40 keys per render and re-ran the same
     * AppInstallation query every time; the owner's install set does not change
     * within a request. Reset with $memo on a new app instance (scopeMemo/flush).
     */
    /** @var array<int, \Illuminate\Database\Eloquent\Collection<int, AppInstallation>|null> per-owner install set; null = query failed */
    protected static array $trialInstallsMemo = [];

    /**
     * PERF: per-request map of each owner's FeatureGrant rows keyed by feature_key,
     * so for() answers the "new badge" grant lookup from memory instead of a query
     * per key across a ~40-key nav render. Reset with $memo on a new app (flush).
     *
     * @var array<int, array<string, \Juwri\Core\Models\FeatureGrant>>
     */
    protected static array $grantMemo = [];

    /** This owner's FeatureGrant for a key, from a single per-request load. */
    protected static function grantFor(User $owner, string $key): ?FeatureGrant
    {
        self::scopeMemo();

        if (! array_key_exists($owner->id, self::$grantMemo)) {
            try {
                self::$grantMemo[$owner->id] = FeatureGrant::where('user_id', $owner->id)
                    ->get()->keyBy('feature_key')->all();
            } catch (\Throwable $e) {
                self::$grantMemo[$owner->id] = [];
            }
        }

        return self::$grantMemo[$owner->id][$key] ?? null;
    }

    /**
     * The nudge claimed by THIS render, if any.
     *
     * The session cap alone is not enough: it is only spent when an impression
     * is recorded, which happens client-side after the page has already been
     * built. Without this, a page asking about thirty keys would render thirty
     * ribbons and only then discover it was meant to show one.
     */
    protected static ?string $nudgeClaimed = null;

    /** The application instance the state above belongs to. */
    protected static ?int $memoApp = null;

    /**
     * Bind the memo to the current application instance, as StoreOption does for
     * its option memo. The key is "feature key|user id", and a user id says
     * nothing about which database it came from — RefreshDatabase hands the next
     * test the same low ids, so one test's entitlements would decide what the
     * next test's unrelated owner is offered.
     */
    protected static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::flush();
        }
    }

    /**
     * The tri-state gate, extracted from juwri_nav_model()'s $featState closure
     * so the nav and the spotlight can never drift apart.
     *
     * 'off'    — kill-switch, or nothing sells it → the feature is hidden.
     * 'locked' — plan-gated, not entitled, and something sells it.
     * 'ok'     — entitled, or not gated at all.
     */
    public static function gateState(string $key, ?User $owner): string
    {
        try {
            if (! FeatureRegistry::isEnabled($key)) {
                return 'off';
            }
            if (EnsureFeatureEnabled::planGated($key)) {
                if ($owner && Entitlements::allows($owner, $key)) {
                    return 'ok';
                }

                return self::commerce()->sellable($key) ? 'locked' : 'off';
            }

            return 'ok';
        } catch (\Throwable $e) {
            return 'off';
        }
    }

    /**
     * What should this owner be told about this key?
     *
     * Returns ['state', 'copy', 'channels', 'trial'] where state is one of:
     *   locked   — they do not have it and something sells it
     *   trialing — they have it through an app trial that has not ended
     *   new      — entitlement gained within FeatureGrant::NEW_FOR_DAYS
     *   included — they have it, it is not new, and they have never been told
     *   none     — nothing to say
     *
     * Priority puts `trialing` above `new` deliberately: a trial is the only
     * state with a deadline attached, and a silent trial expiry is the most
     * expensive kind of churn. `trial` is populated whenever a trial exists,
     * whatever the state, so the NEW badge and the countdown chip can coexist.
     */
    public static function for(string $key, ?User $owner): array
    {
        self::scopeMemo();

        $memoKey = $key.'|'.($owner->id ?? 0);
        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }

        $upsell = self::commerce()->upsell($key);
        $trial = self::trial($key, $owner);
        $result = [
            'state' => 'none',
            'copy' => [
                'label' => $upsell['label'] ?? ucwords(str_replace(['_', '-'], ' ', $key)),
                'blurb' => $upsell['blurb'] ?? '',
            ],
            'channels' => $upsell['channels'] ?? [],
            'trial' => $trial,
        ];

        if (! self::allowed($owner)) {
            return self::$memo[$memoKey] = $result;
        }

        $gate = self::gateState($key, $owner);

        if ($gate === 'off') {
            return self::$memo[$memoKey] = $result;
        }

        if ($gate === 'locked') {
            // Onboarding owns the first week; do not compete with it.
            $result['state'] = self::onboarding($owner) ? 'none' : 'locked';

            return self::$memo[$memoKey] = $result;
        }

        // From here on the owner IS entitled — the half with no implementation
        // before this run. "You own this" is only worth saying about something
        // that could have been BOUGHT: a key nothing sells, and that no grant or
        // trial is attached to, is ordinary app furniture with nothing to tell.
        $grant = $owner ? self::grantFor($owner, $key) : null;

        if (! $trial && ! $grant && ! self::commerce()->sellable($key)) {
            return self::$memo[$memoKey] = $result;
        }

        if ($trial && $trial['days_left'] >= 0) {
            $result['state'] = 'trialing';

            return self::$memo[$memoKey] = $result;
        }

        if ($grant && $grant->isNew()) {
            $result['state'] = 'new';

            return self::$memo[$memoKey] = $result;
        }

        /* "Included in your plan" persists until it is dismissed. Seeing it is
           not the same as reading it (WI-B1), so only an explicit dismissal
           retires it. */
        if ($owner && ! self::commerce()->spotlightDismissed($owner->id, $key, 'ribbon')) {
            $result['state'] = 'included';
        }

        return self::$memo[$memoKey] = $result;
    }

    /**
     * May this surface render for this owner right now? Every guardrail lives
     * here rather than at the call sites, so "does this nag?" has one answer.
     */
    public static function shouldShow(string $key, ?User $owner, string $surface): bool
    {
        if (! $owner || ! self::allowed($owner)) {
            return false;
        }

        if (self::commerce()->spotlightDismissed($owner->id, $key, $surface)) {
            return false;   // a dismissed surface stays dismissed
        }

        if (in_array($surface, self::ONCE_SURFACES, true)
            && self::commerce()->spotlightSeen($owner->id, $key, $surface)) {
            return false;   // they have already had this moment
        }

        $state = self::for($key, $owner)['state'];
        if ($state === 'none') {
            return false;
        }

        // At most one proactive interruption per session. The nav lock and the
        // inline teasers are page furniture and do not spend it.
        if (in_array($surface, self::NUDGE_SURFACES, true)) {
            $slot = $key.'|'.$surface;

            $spent = self::session()?->get(self::SESSION_NUDGE);
            if ($spent && $spent !== $slot) {
                return false;
            }

            if (self::$nudgeClaimed !== null && self::$nudgeClaimed !== $slot) {
                return false;
            }
            self::$nudgeClaimed = $slot;
        }

        return true;
    }

    /**
     * Record what happened on a surface. Also spends the session nudge slot, so
     * a caller cannot show two toasts by not asking shouldShow() again.
     */
    public static function record(string $key, ?User $owner, string $surface, string $action, array $meta = []): ?Model
    {
        if (! $owner
            || ! in_array($surface, self::SURFACES, true)
            || ! in_array($action, self::ACTIONS, true)) {
            return null;
        }

        if ($action === 'impression' && in_array($surface, self::NUDGE_SURFACES, true)) {
            self::session()?->put(self::SESSION_NUDGE, $key.'|'.$surface);
        }

        return self::commerce()->recordSpotlight([
            'user_id' => $owner->id,
            'feature_key' => $key,
            'surface' => $surface,
            'action' => $action,
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * WI-A3: where a locked key's nav row should point.
     *
     * A previewable key sends them to the real screen, blurred, rather than to
     * a page describing it — that is the entire bet of preview mode. Everything
     * else goes to the upsell page as before.
     */
    public static function lockedDestination(string $key): ?string
    {
        $route = self::PREVIEWABLE[$key] ?? null;

        return $route && \Route::has($route) ? $route : null;
    }

    /**
     * WI-B2: where "set it up" should go for a key.
     *
     * A toast saying a feature is active is only useful if it can also take you
     * to it. Resolved from the nav's own destination for the key, so it cannot
     * point somewhere the sidebar disagrees with; null when the feature has no
     * single obvious front door.
     */
    public static function setupUrl(string $key): ?string
    {
        $routes = self::PREVIEWABLE + [
            'support_desk' => 'support.inbox',
            'loyalty' => 'loyalty.settings',
            'marketing_automation' => 'stores.automations.index',
            'gift_cards' => 'stores.giftcards.index',
            'print_routing' => 'printers.index',
            'pos_enabled' => 'pos.index',
            'kiosk' => 'kiosk.index',
        ];

        $route = $routes[$key] ?? null;
        if (! $route || ! \Route::has($route)) {
            return null;
        }

        try {
            return route($route);
        } catch (\Throwable $e) {
            // Route needs parameters we do not have here — no front door then.
            return null;
        }
    }

    /**
     * B4: where "Continue" goes for a key that is ON TRIAL.
     *
     * Not the cheapest channel — that is the answer to "how do I buy this?",
     * and a merchant mid-trial has already answered it. The decision they face
     * is about the app they are already using, so it belongs on that app's own
     * page. Falls back to the buy channel when the trial has no install behind
     * it (a plan-granted trial, say).
     */
    public static function trialContinueUrl(array $spot): ?string
    {
        $installId = $spot['trial']['installation_id'] ?? null;

        if ($installId) {
            try {
                $install = AppInstallation::find($installId);
                if ($install) {
                    return self::appManageUrl($install);
                }
            } catch (\Throwable $e) {
                // fall through to the buy channel
            }
        }

        return self::bestChannel($spot['channels'] ?? [])['url'] ?? null;
    }

    /** B4: the app's own page, where a trial can be continued or stopped. */
    public static function appManageUrl(AppInstallation $install): string
    {
        $slug = $install->app?->slug;

        if ($slug && \Route::has('user.store.apps.show')) {
            return route('user.store.apps.show', $slug);
        }

        return \Route::has('dashboard') ? route('dashboard') : url('/');
    }

    /**
     * B4: signed opt-out link for trial reminder mail.
     *
     * Signed rather than authenticated: it is clicked from an inbox, often on a
     * device that is not logged in, and a reminder people cannot switch off is
     * a reminder that gets marked as spam.
     */
    public static function trialUnsubscribeUrl(User $owner): string
    {
        if (! \Route::has('spotlight.trial-emails.unsubscribe')) {
            return \Route::has('dashboard') ? route('dashboard') : url('/');
        }

        return URL::signedRoute(
            'spotlight.trial-emails.unsubscribe',
            ['user' => $owner->id]
        );
    }

    /** Is this owner seeing $key as a blurred preview rather than the real thing? */
    public static function isPreview(string $key, ?User $owner): bool
    {
        return isset(self::PREVIEWABLE[$key]) && self::gateState($key, $owner) === 'locked';
    }

    /**
     * WI-C3: which variant of an experiment this OWNER is in.
     *
     * The existing ab_variant() helper keys on the session, which is right for
     * an anonymous storefront visitor and wrong here: a merchant who logs in on
     * the till and again on their laptop would see two different upsells and
     * the numbers would describe nobody. Assignment is derived from the user id,
     * so it is stable across devices and across logins, and it needs no storage.
     *
     * Impressions still land in ab_impressions so the existing Admin -> A/B
     * Experiments page counts them, keyed by a per-user surrogate rather than a
     * session id. Returns '' when the experiment is absent or stopped, which
     * every caller reads as "use the default wording".
     */
    public static function variant(string $experiment, ?User $owner): string
    {
        if (! $owner) {
            return '';
        }

        $memoKey = 'ab|'.$experiment.'|'.$owner->id;
        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }

        try {
            $exp = AbExperiment::where('name', $experiment)
                ->where('status', 'active')->first();

            if (! $exp || empty($exp->variants)) {
                return self::$memo[$memoKey] = '';
            }

            $variants = array_values((array) $exp->variants);
            $variant = $variants[abs(crc32($experiment.':'.$owner->id)) % count($variants)];

            AbImpression::firstOrCreate(
                ['experiment_id' => $exp->id, 'session_id' => self::abSubject($owner)],
                ['variant' => $variant]
            );

            return self::$memo[$memoKey] = $variant;
        } catch (\Throwable $e) {
            return self::$memo[$memoKey] = '';   // an experiment must never break a page
        }
    }

    /** WI-C3: mark this owner converted on an experiment. */
    public static function abConvert(string $experiment, ?User $owner): void
    {
        if (! $owner) {
            return;
        }

        try {
            $exp = AbExperiment::where('name', $experiment)->first();
            if (! $exp) {
                return;
            }

            AbImpression::where('experiment_id', $exp->id)
                ->where('session_id', self::abSubject($owner))
                ->whereNull('converted_at')
                ->update(['converted_at' => now()]);
        } catch (\Throwable $e) {
            // analytics must never break the grant that fired it
        }
    }

    /** Stable per-user key for the session-shaped ab_impressions column. */
    protected static function abSubject(User $owner): string
    {
        return hash('sha256', 'spotlight-user:'.$owner->id);
    }

    /** Reset the per-request memo. Tests and long-running workers need this. */
    public static function flush(): void
    {
        self::$memo = [];
        self::$nudgeClaimed = null;
        self::$trialInstallsMemo = [];
        self::$grantMemo = [];
    }

    /**
     * The channel to lead with: the cheapest true offer. A free trial beats any
     * price — it is the lowest-commitment way in and the one that converts — and
     * below that, the cheapest real amount wins. Plan upgrades come last: they
     * are the biggest ask, and "upgrade your whole plan" is a poor answer to
     * "I would like this one feature".
     */
    public static function bestChannel(array $channels): ?array
    {
        if (! $channels) {
            return null;
        }

        $ranked = collect($channels)->sortBy([
            fn ($c) => empty($c['trial_days']) ? 1 : 0,
            fn ($c) => $c['kind'] === 'plan' ? 1 : 0,
            fn ($c) => (float) preg_replace('/[^0-9.]/', '', (string) ($c['price'] ?? '0')),
        ]);

        return $ranked->first();
    }

    /**
     * WI-A4: one CTA rule, so the locked page, the teaser, the tooltip and the
     * ribbon cannot drift. Where a trial exists we say so — "Start free trial"
     * measurably beats a generic CTA, and it matches what actually happens next.
     */
    public static function cta(?array $channel, string $variant = ''): string
    {
        if (! $channel) {
            return ___('Learn more');
        }

        if (! empty($channel['trial_days'])) {
            /* WI-C3: the one thing under test. Both variants are true — the
               trial exists either way — so the losing arm is not a worse
               product, just worse wording. */
            return $variant === 'outcome_first'
                ? ___('Try it free')
                : ___('Start free trial');
        }

        return $channel['kind'] === 'plan' ? ___('Compare plans') : ___('Continue');
    }

    /**
     * WI-A1: the shortest true sell for a key, as one short line — a free
     * trial, else a price, else the plan line. Returns null when nothing sells
     * it. Shared by the nav row and the lock-row component so the two can never
     * quote different numbers for the same feature.
     *
     * A plan channel carries no `price` field at all, only a `detail` like
     * "Included in Premium", so the fallback is what stops a plan-only key from
     * rendering a lock with nothing beside it.
     */
    public static function sellLine(string $key, ?User $owner): ?string
    {
        $spot = self::for($key, $owner);

        /* for() returns the catalog channels whatever the state, because the
           locked PAGE still needs them. Quoting a price is selling, though, so
           it stops when the state says there is nothing to say — otherwise the
           kill-switch would silence every component and leave the nav happily
           advertising prices. */
        if (($spot['state'] ?? 'none') === 'none') {
            return null;
        }

        $channel = self::bestChannel($spot['channels'] ?? []);
        if (! $channel) {
            return null;
        }

        if (! empty($channel['trial_days'])) {
            return ___(':days-day free trial', ['days' => $channel['trial_days']]);
        }

        $price = trim(($channel['price'] ?? '').' '.($channel['period'] ?? ''));

        return $price ?: ($channel['detail'] ?? null);
    }

    /**
     * WI-A1: the sell as it fits a sidebar CHIP — a few characters, not a
     * sentence. "14-day free trial" reads fine in a tooltip and truncates to
     * nonsense in a chip, especially in Arabic, so the chip says "Free trial"
     * and the exact day count stays in the tooltip where there is room for it.
     */
    public static function chipLine(string $key, ?User $owner): ?string
    {
        $spot = self::for($key, $owner);
        if (($spot['state'] ?? 'none') === 'none') {
            return null;
        }

        $channel = self::bestChannel($spot['channels'] ?? []);
        if (! $channel) {
            return null;
        }

        if (! empty($channel['trial_days'])) {
            return ___('Free trial');
        }

        // A price alone; the period lives in the tooltip. A plan channel has no
        // price at all, and a chip with nothing in it is the bare padlock this
        // row exists to replace — so it says what the path actually is.
        return $channel['price'] ?? ($channel['kind'] === 'plan' ? ___('Upgrade') : null);
    }

    /** WI-A1: name, one line of blurb, and the sell — for a native tooltip. */
    public static function tooltip(string $key, ?User $owner): string
    {
        $spot = self::for($key, $owner);

        // Catalog copy is admin-authored CONTENT — concatenated, never ___().
        $parts = array_filter([
            $spot['copy']['label'] ?? null,
            $spot['copy']['blurb'] ?? null,
            self::sellLine($key, $owner),
        ]);

        return trim(implode("\n", $parts));
    }

    /**
     * WI-A2: the offer for a COUNT limit the owner has just hit.
     *
     * Different question from for(): a limit key is not plan-gated, so the
     * tri-state gate says 'ok' and the state machine has nothing to say about
     * it — yet "you cannot add another store" is the highest-intent moment
     * there is, and it currently ends in a bare red toast. This resolves the
     * add-on or plan that raises the ceiling.
     *
     * $type is the bare limit name — 'restaurant', 'menu' — not the key.
     */
    public static function limitOffer(string $type, ?User $owner): ?array
    {
        // Spotlight guardrails (onboarding suppression, reseller brand, session
        // cap) still gate WHETHER we nudge — those are display policy.
        if (! self::allowed($owner)) {
            return null;
        }

        /* M0-5: the resolution itself now lives in FeatureUpsell, the ONE upsell
           brain. It also hardens the sentinel — an unlimited store (999 / legacy
           raw ≥1000) resolves to null and never sees a quantity upsell, because
           a boost buys nothing on an unlimited plan. This method keeps its
           spotlight-shaped return (a single leading channel + a ready-made cta)
           so the limit.blade component is untouched. */
        $offer = self::commerce()->limitOffer($type, $owner);
        if ($offer === null) {
            return null;
        }

        // Lead with the cheapest channel FeatureUpsell picked.
        /** @var list<array<string, mixed>> $channels */
        $channels = $offer['channels'];
        $channel = collect($channels)->firstWhere('cheapest', true) ?? $channels[0];

        return [
            'key' => $offer['key'],
            'label' => $channel['label'],
            'blurb' => $channel['detail'],
            'channel' => $channel,
            'cta' => self::cta($channel),
        ];
    }

    /** P1-13 13g: upsell copy, offers and spotlight events come from Billing (ee); Community sells nothing. */
    private static function commerce(): FeatureCommerce
    {
        return app(FeatureCommerce::class);
    }

    /** The owner's current plan name, for "included in your <plan>". */
    public static function planName(?User $owner): ?string
    {
        if (! $owner) {
            return null;
        }

        try {
            return app(PlanCatalog::class)->find($owner->group_id)?->getAttribute('name');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Platform kill-switch plus the reseller rule: a white-labelled dashboard
     * must never carry platform-branded upsell — the storefront items already
     * follow the same rule, and this reuses their notion of "is this reseller".
     */
    protected static function allowed(?User $owner): bool
    {
        if (! $owner) {
            return false;
        }

        if (config()->has('settings.admin_allow_feature_spotlight')
            && ! config('settings.admin_allow_feature_spotlight')) {
            return false;
        }

        try {
            if (BrandContext::current()->isReseller()) {
                return false;
            }
        } catch (\Throwable $e) {
            // No brand context (console, tests) → platform brand → allowed.
        }

        return true;
    }

    /** Is this account still inside its first-week onboarding? */
    protected static function onboarding(?User $owner): bool
    {
        return $owner?->created_at !== null
            && $owner->created_at->gt(now()->subDays(self::ONBOARDING_DAYS));
    }

    /**
     * The live app trial granting this key, if any.
     * Shape: ['ends_at' => Carbon, 'days_left' => int, 'app' => string].
     */
    protected static function trial(string $key, ?User $owner): ?array
    {
        if (! $owner) {
            return null;
        }

        self::scopeMemo();

        if (! array_key_exists($owner->id, self::$trialInstallsMemo)) {
            try {
                self::$trialInstallsMemo[$owner->id] = AppInstallation::with('app')
                    ->where('user_id', $owner->id)
                    ->where('status', 'active')
                    ->whereNotNull('trial_ends_at')
                    ->get();
            } catch (\Throwable $e) {
                // Null sentinel: a broken query stays null so trial() returns null
                // for this owner, matching the old catch, without re-querying.
                self::$trialInstallsMemo[$owner->id] = null;
            }
        }

        $installs = self::$trialInstallsMemo[$owner->id];
        if ($installs === null) {
            return null;
        }

        foreach ($installs as $install) {
            $grants = $install->app?->grants_features;
            if (! is_array($grants) || ! in_array($key, $grants, true)) {
                continue;
            }

            return [
                'ends_at' => $install->trial_ends_at,
                // 0 means "ends today", negative means it already ended.
                'days_left' => (int) floor(now()->startOfDay()->diffInDays($install->trial_ends_at->startOfDay(), false)),
                'app' => $install->app->name,
                'installation_id' => $install->id,
            ];
        }

        return null;
    }

    /** The session store, or null when there is none (console, queue, tests). */
    protected static function session()
    {
        try {
            return request()->hasSession() ? request()->session() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
