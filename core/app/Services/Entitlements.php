<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\StoreView;
use Juwri\Core\Contracts\Billing\EntitlementSource;
use Juwri\Core\Features\PlatformFeatures;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\User;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P2 (plans rebuild, spec §6): the ONE reader for "did this customer pay for
 * it". Sources, in order:
 *   1. the live subscription's entitlement SNAPSHOT (subscriptions.meta —
 *      grandfathering: what they bought wins over later plan edits),
 *   2. plan_entitlement rows for the live plan,
 *   3. the legacy plan()->settings blob (dual-write window: trial/free config
 *      plans and not-yet-mirrored plans still live there),
 * plus ACTIVE ADD-ONS added arithmetically for count limits.
 *
 * NULL = unlimited (the 999 sentinel is normalized away here; the legacy
 * `effective_limit()` delegate re-emits 999 during the transition — kickoff
 * rule #7 — and flips to PHP_INT_MAX at Retire).
 *
 * Entitlements ≠ feature flags (decision 6): global kill-switches remain an
 * operational veto consulted by has(); never the reverse.
 *
 * P1-13: the resolver is Community (Core registry); what was BOUGHT (plan chain,
 * add-ons, bundles) comes from the {@see EntitlementSource} extension point —
 * Billing (ee) binds plans/add-ons, Community grants every feature unlimited.
 */
class Entitlements
{
    /** The paid limit for a count key ('restaurant', 'scan', ...). NULL = unlimited. */
    public static function limit(User $user, string $key): ?int
    {
        $raw = static::rawValue($user, $key.'_limit');
        $base = static::normalize($raw);

        // Unlimited stays unlimited; bounded limits gain add-on boosts.
        if ($base === null) {
            return null;
        }

        // Return the FULL paid capacity (base + active add-on packs), UNCLAMPED.
        // The 999-sentinel collision guard lives in the enforcement/display layer,
        // effective_limit(): 'scan' passes through (packs legitimately sell +10k),
        // every other count key is clamped to 998 there AND the shortfall is logged
        // so the merchant Add-ons page can surface paid-but-ungranted capacity.
        // Clamping here too (the previous behaviour) double-clamped: it capped scan
        // packs at 998 and starved effective_limit()'s warning of the real total.
        // Direct callers never sentinel-test this value — AddonController displays
        // the paid capacity, FeatureUpsell only null-checks it for "unlimited".
        return $base + self::source()->addonTotal($user->id, $key);
    }

    /**
     * P1 (feature-platform): the ONE kill-switch veto implementation — a
     * platform-wide OFF wins over every commercial grant (two-lane model,
     * decision D8). Kill never grants; grant never bypasses kill.
     */
    public static function vetoed(string $key): bool
    {
        // P1-07: the admin_allow_<key> half is the feature registry's switchedOff().
        return PlatformFeatures::switchedOff($key)
            || array_any(['settings.feature_'.$key], fn ($configKey) => config()->has($configKey) && ! config($configKey));
    }

    /**
     * Whether the user is entitled to a boolean feature key, from ANY source:
     * plan chain (snapshot → rows → legacy blob) OR an active entitlement
     * override (admin grant, promo, app install, boolean add-on). Most
     * generous wins; the kill-switch is the sole veto. Fails closed.
     */
    public static function has(User $user, string $key): bool
    {
        if (static::vetoed($key)) {
            return false;
        }

        // F5-3 (feature-platform gap-fix): a BANNED owner resolves as
        // unentitled. STATELESS by design — we read the user's current status
        // here instead of revoking their stored grants on ban, so an unban
        // restores every entitlement automatically with no repair step and the
        // ban itself writes/destroys nothing.
        if (static::isBanned($user)) {
            return false;
        }

        $value = static::rawValue($user, $key);
        if (! empty($value) && (string) $value === '1') {
            return true;
        }

        // P1: override source — table absent (mid-migration) fails closed. PERF:
        // one memoized set of the user's active value=1 override keys instead of an
        // exists() per key across a ~40-key nav render (see overrideKeys()).
        if (in_array($key, self::overrideKeys($user), true)) {
            return true;
        }

        // P3: boolean add-on source — the PAID user_addons row is the truth
        // (renewals extend expires_at, cancel keeps capacity to period end),
        // so the grant follows billing state with zero sync code. A null memo means
        // the table read failed (mid-migration) → fail closed, exactly as the old
        // per-key catch returned false.
        $addonKeys = self::source()->booleanAddonKeys($user);
        if ($addonKeys === null) {
            return false;
        }
        if (in_array($key, $addonKeys, true)) {
            return true;
        }

        // M0-3: bundle source — same rule one level up. The paid bundle row
        // grants the union of its children's keys, so cancelling a bundle
        // re-locks only what no other source still holds, with no revocation
        // bookkeeping. Sharing a child between two bundles is fine: this is a
        // membership test, not ownership.
        return in_array($key, static::bundleGrants($user), true);
    }

    /**
     * PERF: per-user set of active `value=1` override keys, so has() answers a
     * ~40-key nav render from PHP instead of an exists() per key. Keyed by user
     * id; reset on a new app (see scopeMemo()).
     *
     * @var array<int, array<int,string>>
     */
    private static array $overrideKeysMemo = [];

    /** The application instance the memo was filled for. */
    private static ?int $memoApp = null;

    /**
     * Bind the memo to the current application instance, as StoreOption does for
     * its option memo. Keyed by user id alone, the memo cannot tell which
     * database that id came from — and RefreshDatabase hands the next test the
     * same low ids, so one test's grants would leak to the unrelated user that
     * inherited the id in the next.
     */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$overrideKeysMemo = [];
        }
    }

    /**
     * The user's active override keys (value=1), loaded once per request.
     *
     * @return array<int,string>
     */
    private static function overrideKeys(User $user): array
    {
        self::scopeMemo();

        if (! array_key_exists($user->id, self::$overrideKeysMemo)) {
            try {
                self::$overrideKeysMemo[$user->id] = EntitlementOverride::query()
                    ->where('user_id', $user->id)->active()->where('value', '1')
                    ->pluck('feature_key')->filter()->unique()->values()->all();
            } catch (\Throwable $e) {
                // Empty set = fall through (the old per-key catch did the same).
                self::$overrideKeysMemo[$user->id] = [];
            }
        }

        return self::$overrideKeysMemo[$user->id];
    }

    /** What was bought: plan chain, add-ons, bundles (P1-13 extension point). */
    private static function source(): EntitlementSource
    {
        return app(EntitlementSource::class);
    }

    /**
     * Every feature key this user holds through an ACTIVE bundle purchase
     * (derived from the paid row joined to the live catalog; see the source).
     *
     * @return list<string>
     */
    public static function bundleGrants(User $user): array
    {
        return self::source()->bundleGrants($user);
    }

    /** Test/queue hygiene: drop the per-request memos. */
    public static function flushBundleMemo(): void
    {
        self::$overrideKeysMemo = [];
        self::source()->flush();
    }

    /**
     * F5-3: the ONE ban predicate. `users.status` is a non-nullable boolean
     * (default 1); the admin ban toggle writes 0. Kept here so every consumer
     * (resolver, partner-app API, middleware) shares one definition.
     */
    public static function isBanned(User $user): bool
    {
        return $user->status !== null && (int) $user->status === 0;
    }

    /**
     * Pricing follow-ups (PRICING.md §3): for on/off keys added AFTER plans already existed (delivery, coupons, …).
     * A plan chain that holds NO value for the key keeps the feature — a plan written before the key existed must
     * not lose delivery or coupons overnight. A value ('0') withholds it unless another lane (override, add-on,
     * bundle) grants it, exactly as has(). The kill switch and a ban still veto in both cases.
     */
    public static function allowsUnlessWithheld(User $user, string $key): bool
    {
        if (static::traceValue($user, $key) === null) {
            return ! static::vetoed($key) && ! static::isBanned($user);
        }

        return static::has($user, $key);
    }

    /** Canonical read alias (SPEC §2): every new call site should use allows(). */
    public static function allows(User $user, string $key): bool
    {
        return static::has($user, $key);
    }

    /**
     * Remaining headroom for a count key given current usage. NULL = unlimited.
     * P6-3 (feature-platform): real usage counters — previously only
     * 'restaurant' counted and the method had zero callers.
     */
    public static function remaining(User $user, string $key, ?int $used = null): ?int
    {
        $limit = static::limit($user, $key);
        if ($limit === null) {
            return null;
        }

        return max(0, $limit - ($used ?? static::usage($user, $key)));
    }

    /**
     * Current usage for a count key (calendar-month scoped where the limit is).
     *
     * F5-5: a developer sandbox store (and the demo menu the partner console
     * seeds inside it) must not eat the owner's paid restaurant/menu/category/
     * scan quota — the sandbox exists so they can test apps, not as a store
     * they bought.
     *
     * F7-9: `menu`/`category` are per-BUCKET limits, not per-owner totals — the
     * enforcement paths (MenuAdminService, the dashboard, the MCP tools) all
     * cap items per (restaurant, category) and categories per restaurant. This
     * method must therefore answer the same question the gate answers, but as a
     * single scalar for the meter/warning surfaces: the FULLEST bucket the owner
     * has. `remaining()`/`nearLimit()` then describe the bucket that will hit
     * the wall first, which is exactly what the merchant needs warning about.
     * (Pre-F7-9 this summed across every store, so a 3-store owner appeared out
     * of quota while every create still succeeded.)
     */
    public static function usage(User $user, string $key): int
    {
        try {
            $realPostIds = fn () => $user->stores()->excludingSandbox()->pluck('id');

            return match ($key) {
                'restaurant' => $user->stores()->excludingSandbox()->count(),
                'scan' => (int) StoreView::whereIn('store_id', $realPostIds())
                    ->where('date', '>=', now()->startOfMonth())->count(),
                // Fullest single restaurant.
                'category' => (int) (MenuCategory::whereIn('store_id', $realPostIds())
                    ->selectRaw('count(*) as bucket_total')
                    ->groupBy('store_id')
                    ->get()->max('bucket_total') ?? 0),
                // Fullest single (restaurant, category) bucket.
                'menu' => (int) (Menu::whereIn('store_id', $realPostIds())
                    ->selectRaw('count(*) as bucket_total')
                    ->groupBy('store_id', 'category_id')
                    ->get()->max('bucket_total') ?? 0),
                default => 0,
            };
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * P6-1 (feature-platform): THE sentinel check. Every call site that used to
     * compare `< 999` / `!= 999` against effective_limit() goes through here,
     * so retiring the sentinel (999 → NULL/PHP_INT_MAX at the plans-rebuild
     * Retire gate) becomes a one-line change in ONE place. The P0-3 clamp
     * guarantees bounded totals never reach 999, so `< 999` and `!= 999`
     * call sites are equivalent and both map here losslessly.
     */
    public static function isUnlimitedSentinel(int|string|null $limit): bool
    {
        return $limit !== null && (int) $limit === 999;
    }

    /**
     * F7-6 (feature-platform gap fix): the sentinel check for a RAW, UNCLAMPED
     * plan/tier settings value (`$plan->settings->menu_limit`, reseller tier
     * columns, …). Those never pass through effective_limit()'s P0-3 clamp, so
     * legacy rows above the sentinel (e.g. 1000, 5000) exist and have always
     * been treated as unlimited — which is why the blades compared `>= 999`
     * rather than `=== 999`.
     *
     * Clamping first and delegating keeps the retire-the-sentinel change a
     * one-liner in isUnlimitedSentinel(), exactly as P6-1 intended. Empty /
     * null / '' is NOT unlimited (it is "unset", rendered as 0 by callers).
     */
    public static function isUnlimitedRawLimit(int|string|null $limit): bool
    {
        if ($limit === null || $limit === '') {
            return false;
        }

        return static::isUnlimitedSentinel(min((int) $limit, 999));
    }

    /**
     * P6-3: soft-limit warning — true when a BOUNDED limit is at or past the
     * threshold fraction (default 80%). Unlimited never warns.
     */
    public static function nearLimit(User $user, string $key, float $threshold = 0.8): bool
    {
        $limit = static::limit($user, $key);
        if ($limit === null || $limit <= 0) {
            return false;
        }

        return static::usage($user, $key) >= (int) ceil($limit * $threshold);
    }

    // ------------------------------------------------------------------ internals

    /**
     * M0-2 (monetization): read one entitlement value through the resolver
     * rather than reaching into `$user->plan()->settings` directly.
     *
     * Exists because WhatsAppBilling did exactly that — it read the raw legacy
     * blob, so a plan whose allowance lives in a subscription snapshot or a
     * typed plan_entitlement row resolved to the config default instead. The
     * domain that owns a key keeps its own sentinel semantics (WhatsApp treats
     * 0 as unlimited, unlike the 999 sentinel); only the LOOKUP is centralised.
     */
    public static function planValue(User $user, string $entKey)
    {
        return static::rawValue($user, $entKey);
    }

    /**
     * M0-2: the same lookup, but reporting WHICH lane answered.
     *
     * `FeatureAccess::sources()` needs to tell a merchant why they have a
     * feature — "your plan includes it" reads very differently from "you bought
     * it" — and the credit rules in M0-3 need to know whether a grant will
     * survive cancelling an add-on. Returns null when no lane holds the key.
     *
     * @return array{lane:string,value:mixed}|null
     */
    public static function planTrace(User $user, string $entKey): ?array
    {
        return static::traceValue($user, $entKey);
    }

    /**
     * Resolve one raw entitlement value through snapshot → rows → legacy blob.
     */
    protected static function rawValue(User $user, string $entKey)
    {
        return static::traceValue($user, $entKey)['value'] ?? null;
    }

    /**
     * The single implementation of the plan chain, annotated with the lane that
     * answered. {@see rawValue()} is the value-only view of it, so there is one
     * traversal to keep correct rather than two. P1-13: the chain itself
     * (snapshot → typed rows → legacy blob) is the {@see EntitlementSource}'s.
     *
     * @return array{lane:string,value:mixed}|null
     */
    protected static function traceValue(User $user, string $entKey): ?array
    {
        return self::source()->trace($user, $entKey);
    }

    /** 999 (and legacy '999') = unlimited → NULL; anything else → int. */
    protected static function normalize($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;

        return $int === 999 ? null : $int; // exactly 999 is the legacy sentinel
    }
}
