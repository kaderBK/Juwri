<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * External Redirect — the platform-migration opt-out engine.
 *
 * When a store owner opts out of the new platform, their printed QR codes still
 * point at our domain. This resolver turns a `/{slug}` (and, independently,
 * `/{slug}/bio`) visit into a 302 to a configured external URL (the legacy
 * install or the owner's own site), or an owner-selectable "menu moved"
 * interstitial. See rev/external-redirect/HANDOFF.md §3 for the full contract.
 *
 * Load-bearing invariants:
 *  - 302 only (never 301 — browsers cache 301 forever) + `Cache-Control: no-store`
 *    so toggling the redirect off takes effect on the very next request.
 *  - Account-active is the CALLER's guard: publicView only reaches resolve() inside
 *    the `$store->user->status == 1` block, so a superadmin-BANNED store goes dark
 *    and never redirects (abuse guard). resolve() assumes an active, non-null post.
 *  - Loop guard: a target that points back at the platform host, a verified custom
 *    storefront domain, or an active reseller domain is rejected at save AND read
 *    time (re-runs the TrustHosts query directly — never trusts a possibly-cold
 *    `trusted_custom_domains` cache read, which would fail open).
 */
class ExternalRedirect
{
    /** Max stored-URL length (placeholders included). */
    public const MAX_URL = 2048;

    /** Owner-selectable "menu moved" interstitial view (A6 ships the blade). */
    public const INTERSTITIAL_VIEW = 'external-redirect-interstitial';

    /**
     * Menu-load resolver. Returns a 302 / interstitial Response, or null to render
     * the menu normally. Interstitial is owner-selectable on the menu surface.
     */
    public static function resolve(Store $store, Request $request): ?Response
    {
        return self::run(
            $store,
            $request,
            'external_redirect_enabled',
            'external_redirect_url',
            'external_redirect_interstitial',
        );
    }

    /**
     * Bio-page resolver — independent toggle + URL, and ALWAYS an instant 302
     * (bio has no interstitial option, by locked decision).
     */
    public static function resolveBio(Store $store, Request $request): ?Response
    {
        return self::run(
            $store,
            $request,
            'bio_external_redirect_enabled',
            'bio_external_redirect_url',
            null,
        );
    }

    /**
     * Shared resolver core. $interstitialKey === null ⇒ always instant 302 (bio).
     */
    private static function run(
        Store $store,
        Request $request,
        string $enabledKey,
        string $urlKey,
        ?string $interstitialKey,
    ): ?Response {
        // 1. Global kill-switch (superadmin Option; default enabled).
        if (config('settings.external_redirect_global', true) == '0') {
            return null;
        }

        // 2. Per-store toggle must be affirmatively on, with a non-empty URL.
        //    Fail-closed: require '1' (loose) — anything else renders the menu.
        if (StoreOption::getStoreOption($store->id, $enabledKey) != '1') {
            return null;
        }
        $savedUrl = trim((string) StoreOption::getStoreOption($store->id, $urlKey));
        if ($savedUrl === '') {
            return null;
        }

        // 3. Only GET menu loads are ever intercepted (a POST never is).
        if (! $request->isMethod('get')) {
            return null;
        }

        // 4. `?redirect=0` support bypass — lets the owner/support view the real menu.
        if ($request->query('redirect') === '0') {
            return null;
        }

        // 5. Owner-preview / admin bypass. Explicit ownership check on the web guard
        //    (the store owner) — NOT bare auth()->check(), or a DIFFERENT signed-in
        //    owner would wrongly see the menu. Admins bypass too (isAdmin idiom).
        if (auth()->check()
            && ((int) $store->user_id === (int) auth()->id() || auth()->user()->isAdmin())) {
            return null;
        }

        // 6. Build the target (query forwarding, §3.3). Re-validate at read time and
        //    fail OPEN to the normal menu on anything invalid (never emit a bad 302).
        $target = self::buildTarget($savedUrl, (string) $store->slug, self::forwardQuery($request));
        if (self::validate($target) !== null) {
            Log::warning('external_redirect: invalid target at read time — rendering menu', [
                'store_id' => $store->id,
                'target' => $target,
            ]);

            return null;
        }

        // 7. Interstitial (owner-selectable, menu only) vs instant 302. Both no-store.
        if ($interstitialKey !== null
            && StoreOption::getStoreOption($store->id, $interstitialKey) == '1') {
            return response()
                ->view(self::INTERSTITIAL_VIEW, ['store' => $store, 'target' => $target], 200)
                ->header('Cache-Control', 'no-store');
        }

        return redirect()->away($target, 302)->header('Cache-Control', 'no-store');
    }

    /**
     * Build the outbound target from the saved URL + the store slug + the forwarded
     * query (the original query string minus `redirect`, no leading `?`). See the
     * §3.3 table — every row is a test case.
     *
     *  - `{slug}`  → the store slug.
     *  - `{query}` → the forwarded query WITH a leading `?` when non-empty, else ''.
     *  - No `{query}` placeholder → forwarded params merge into the target's query;
     *    on a key collision the target's OWN param wins (and keeps its position).
     */
    public static function buildTarget(string $savedUrl, string $slug, string $origQuery): string
    {
        $target = str_replace('{slug}', $slug, $savedUrl);

        if (str_contains($target, '{query}')) {
            return str_replace('{query}', $origQuery !== '' ? '?'.$origQuery : '', $target);
        }

        if ($origQuery === '') {
            return $target;
        }

        return self::mergeQuery($target, $origQuery);
    }

    /**
     * Merge forwarded params into $target's query. The target's own params win on
     * collision and keep their original order; forwarded-only params are appended.
     */
    private static function mergeQuery(string $target, string $origQuery): string
    {
        parse_str($origQuery, $incoming);
        $parts = @parse_url($target);
        if (! is_array($parts)) {
            return $target; // unparsable — never mangle a URL we can't safely rebuild
        }
        parse_str($parts['query'] ?? '', $existing);

        $merged = $existing; // target params first, target wins
        foreach ($incoming as $k => $v) {
            if (! array_key_exists($k, $existing)) {
                $merged[$k] = $v;
            }
        }

        $rebuilt = '';
        if (! empty($parts['scheme'])) {
            $rebuilt .= $parts['scheme'].'://';
        } elseif (! empty($parts['host'])) {
            $rebuilt .= '//';
        }
        $rebuilt .= $parts['host'] ?? '';
        if (! empty($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if (! empty($merged)) {
            $rebuilt .= '?'.http_build_query($merged);
        }
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * The forwarded query string: the request's query minus the `redirect` bypass
     * param, no leading `?`. Everything else (qr-id, table, item, …) is preserved.
     */
    private static function forwardQuery(Request $request): string
    {
        $q = $request->query();
        unset($q['redirect']);

        return http_build_query($q);
    }

    /**
     * Static validation used by every save surface (owner / admin / reseller) and
     * re-run at resolve time. Returns null when the URL is acceptable, else a
     * human-readable error message. NO network fetch (zero SSRF surface, v1).
     *
     * $fresh forces the loop-guard host list to be recomputed from the DB. Save
     * surfaces pass true (a new tenant domain must be caught the instant it lands);
     * the resolve/read path leaves it false so the 302 hot path reads a 60s-cached
     * list instead of two queries per redirect.
     */
    public static function validate(string $url, bool $fresh = false): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return ___('Please enter a redirect URL.');
        }
        if (mb_strlen($url) > self::MAX_URL) {
            return ___('The redirect URL is too long.');
        }

        // Validate the STRUCTURE with placeholders substituted by harmless dummies.
        $probe = str_replace(['{slug}', '{query}'], ['store', ''], $url);

        $parts = @parse_url($probe);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return ___('Please enter a valid URL.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return ___('The redirect URL must start with https://.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ___('The redirect URL must not contain a username or password.');
        }
        if (filter_var($probe, FILTER_VALIDATE_URL) === false) {
            return ___('Please enter a valid URL.');
        }

        // Loop guard: the target host must not be a host we serve, or the redirect
        // would bounce straight back into the platform.
        if (in_array(strtolower($parts['host']), self::trustedHosts($fresh), true)) {
            return ___('The redirect URL must point to a different website, not back to this one.');
        }

        return null;
    }

    /** The five per-store option keys this feature owns (menu + bio). */
    public const TOGGLE_KEYS = ['external_redirect_enabled', 'external_redirect_interstitial', 'bio_external_redirect_enabled'];

    public const URL_KEYS = ['external_redirect_url', 'bio_external_redirect_url'];

    /**
     * The ONE save path every surface uses — owner settings (B), admin edit (C),
     * reseller portal (D). Validates, persists (only changed keys), audits each
     * change, and busts the sitemap cache. Returns null on success or a
     * human-readable error message on the first invalid URL (nothing persisted
     * in that case, so a rejected save leaves the store's redirect config intact).
     *
     * Keeps all three surfaces in exact parity — the field set, validation, audit
     * shape and cache invalidation live here, not copied per lane.
     */
    public static function saveFromRequest(Store $store, Request $request): ?string
    {
        $allKeys = array_merge(self::TOGGLE_KEYS, self::URL_KEYS);
        if (! $request->hasAny($allKeys)) {
            return null; // this request carries no redirect fields — leave config untouched
        }

        // Validate URLs BEFORE any write (atomic: reject leaves config intact).
        // $fresh=true: a save must see a newly-added tenant domain immediately.
        foreach (self::URL_KEYS as $key) {
            $url = trim((string) $request->input($key, ''));
            if ($url !== '' && ($err = self::validate($url, true)) !== null) {
                return $err;
            }
        }
        // A toggle on with no URL is a mistake — surface it, don't half-enable.
        if ($request->boolean('external_redirect_enabled') && trim((string) $request->input('external_redirect_url', '')) === '') {
            return ___('Enter a menu redirect URL to turn the menu redirect on.');
        }
        if ($request->boolean('bio_external_redirect_enabled') && trim((string) $request->input('bio_external_redirect_url', '')) === '') {
            return ___('Enter a bio redirect URL to turn the bio redirect on.');
        }

        foreach (self::TOGGLE_KEYS as $key) {
            self::persist($store, $key, $request->boolean($key) ? '1' : '0');
        }
        foreach (self::URL_KEYS as $key) {
            self::persist($store, $key, trim((string) $request->input($key, '')));
        }

        // A newly redirected / restored store must leave / rejoin the sitemap at
        // once (A4 caches sitemap.restaurants).
        SitemapHostCache::forgetRestaurants();

        return null;
    }

    /**
     * Turn the MENU redirect off for one store (the owner dashboard "Disable"
     * button, B2). Keeps the saved URL so re-enabling is one click. No-op when
     * already off. Audited + sitemap-busted.
     */
    public static function clearMenu(Store $store): void
    {
        $old = (string) (StoreOption::getStoreOption($store->id, 'external_redirect_enabled') ?: '');
        if ($old === '' || $old === '0') {
            return;
        }
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '0');
        self::logChange($store, 'external_redirect_enabled', $old, '0');
        SitemapHostCache::forgetRestaurants();
    }

    /**
     * Turn the MENU redirect ON for one store with a given URL/template (the
     * superadmin bulk migration tool, C2). Per-store updateStoreOption (never a raw
     * bulk ->update() — that bypasses the mutator/audit). Audited + sitemap-busted.
     */
    public static function applyMenu(Store $store, string $url): void
    {
        self::persist($store, 'external_redirect_enabled', '1');
        self::persist($store, 'external_redirect_url', $url);
        SitemapHostCache::forgetRestaurants();
    }

    /** Is this store's public menu currently redirecting? (banner gate, B2.) */
    public static function menuActive(Store $store): bool
    {
        return StoreOption::getStoreOption($store->id, 'external_redirect_enabled') == '1'
            && trim((string) StoreOption::getStoreOption($store->id, 'external_redirect_url')) !== '';
    }

    /** Write one option only when it actually changed, auditing the change. */
    private static function persist(Store $store, string $key, string $new): void
    {
        // getStoreOption() runs values through json_decode, so a stored '0' comes
        // back as int 0 and a stored '1' as int 1; an unset key returns false.
        // Cast straight to string (NOT `?: ''`, which would collapse int 0 to '')
        // so a stored '0' compares as '0'. Then treat an unset toggle ('') and a
        // stored '0' as the same "off" state — re-saving an already-off toggle is a
        // true no-op (no spurious write, no bogus external_redirect.clear audit row
        // on every owner/admin/reseller save).
        $old = (string) StoreOption::getStoreOption($store->id, $key);
        if ($old === $new || ($new === '0' && $old === '')) {
            return;
        }
        StoreOption::updateStoreOption($store->id, $key, $new);
        self::logChange($store, $key, $old, $new);
    }

    /**
     * Audit one redirect-option change. The shared write every save surface
     * (owner B / superadmin C / reseller D) calls, so all three produce an
     * identical ActivityLog row. Actor is the acting web-guard user (owner =
     * self, reseller = the reseller user, admin = the admin) — resolved by
     * log_activity() from auth(), which is correct for every lane. Never throws
     * (log_activity swallows its own failures — audit must not break a save).
     *
     * @param  mixed  $old
     * @param  mixed  $new
     */
    public static function logChange(Store $store, string $key, $old, $new): void
    {
        log_activity(
            'external_redirect.'.(self::isCleared($new) ? 'clear' : 'set'),
            $store,
            ['key' => $key, 'old' => $old, 'new' => $new],
            (int) $store->id,
        );
    }

    /** A change that turns a toggle off or empties a URL reads as a "clear". */
    private static function isCleared(mixed $new): bool
    {
        return $new === null || $new === '' || $new === '0' || $new === 0 || $new === false;
    }

    /**
     * Hosts this platform serves — the app host plus every verified custom
     * storefront domain and every active reseller custom domain. Re-runs the same
     * query as TrustHosts::verifiedCustomDomains() rather than reading its cache
     * key (a cold read there is null → would silently let a self-redirect loop
     * through). Schema-guarded so install/migrate cannot fatal.
     *
     * The 302 read path passes $fresh=false and reads a 60s cache (populated by
     * this same closure, so a cache miss recomputes — it never returns a cold,
     * empty list that would let a self-redirect loop through). Save surfaces pass
     * $fresh=true for the uncached, always-current list.
     *
     * @return array<int, string>
     */
    private static function trustedHosts(bool $fresh = false): array
    {
        if ($fresh) {
            return self::computeTrustedHosts();
        }

        return Cache::remember('external_redirect_trusted_hosts', 60, fn () => self::computeTrustedHosts());
    }

    /**
     * The uncached loop-guard host list — the app host plus every host the registered
     * sources give (verified custom storefront domains, active reseller custom domains).
     *
     * @return array<int, string>
     */
    private static function computeTrustedHosts(): array
    {
        $hosts = [];
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost !== '') {
            $hosts[] = $appHost;
        }

        // verified store domains, reseller custom domains: registered by those modules
        array_push($hosts, ...TrustedHostSources::hosts());

        return array_values(array_unique(array_filter($hosts, fn ($h) => $h !== '')));
    }
}
