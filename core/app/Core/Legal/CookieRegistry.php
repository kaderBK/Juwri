<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use Carbon\CarbonInterval;
use Juwri\Core\Editions\EditionPackages;

/**
 * P1-23: the ONE list of cookies this application can set (privacy.md §5, FILL-LIST item 16). The /cookies page
 * prints it, the banner (partials/cookie-consent) builds its category toggles and its "remove on revoke" patterns
 * from it, and the server-side consent gate ({@see CookieConsent}) takes the categories and the lifetime of every
 * consent-gated cookie it writes from it — so the published list, the banner and the code cannot drift apart.
 * CookieRegistryTest scans the code for cookie names and fails on any name that is not listed here.
 *
 * Entry: `name` shown on the page; `pattern` = an anchored regular expression (valid in PHP and JavaScript) that
 * matches the real cookie name(s); `category` essential | analytics | marketing; `purpose` = cookies.purpose.<key>
 * (lang/<locale>/cookies.php); `minutes` = lifetime, null = until the browser closes; `party` first | third (third =
 * set by a third-party service's script or on its domain); `provider` = the third party's name.
 *
 * An installed edition package adds its own cookies through its manifest key `cookies` (a PHP file returning entries
 * of the same shape), so a Community install lists only what it can set.
 */
final class CookieRegistry
{
    /** Display order; essential needs no consent, the others are the banner's toggles. */
    public const CATEGORIES = ['essential', 'analytics', 'marketing'];

    private const YEAR = 60 * 24 * 365;

    /** @var array<string, array{name: string, pattern: string, category: string, purpose: string, minutes: int|null, party: string, provider?: string}> */
    public const COOKIES = [
        // Essential — first party.
        'session' => ['name' => '(session)', 'pattern' => '(session)', 'category' => 'essential', 'purpose' => 'session', 'minutes' => 0, 'party' => 'first'],
        'xsrf' => ['name' => 'XSRF-TOKEN', 'pattern' => '^XSRF-TOKEN$', 'category' => 'essential', 'purpose' => 'csrf', 'minutes' => 0, 'party' => 'first'],
        'remember' => ['name' => 'remember_web_*', 'pattern' => '^remember_web_[0-9a-f]+$', 'category' => 'essential', 'purpose' => 'remember', 'minutes' => 576000, 'party' => 'first'],
        'two_factor' => ['name' => '2fa_remember', 'pattern' => '^2fa_remember$', 'category' => 'essential', 'purpose' => 'two_factor', 'minutes' => 60 * 24 * 30, 'party' => 'first'],
        'consent' => ['name' => CookieConsent::COOKIE, 'pattern' => '^site_consent$', 'category' => 'essential', 'purpose' => 'consent', 'minutes' => 60 * 24 * CookieConsent::DAYS, 'party' => 'first'],
        'lang_code' => ['name' => 'Quick_user_lang_code', 'pattern' => '^Quick_user_lang_code$', 'category' => 'essential', 'purpose' => 'language', 'minutes' => self::YEAR, 'party' => 'first'],
        'lang_name' => ['name' => 'Quick_user_lang', 'pattern' => '^Quick_user_lang$', 'category' => 'essential', 'purpose' => 'language', 'minutes' => self::YEAR, 'party' => 'first'],
        'lang_legacy' => ['name' => 'Quick_lang', 'pattern' => '^Quick_lang$', 'category' => 'essential', 'purpose' => 'language', 'minutes' => self::YEAR, 'party' => 'first'],
        'lang_choice' => ['name' => 'qc_lang_choice', 'pattern' => '^qc_lang_choice$', 'category' => 'essential', 'purpose' => 'language', 'minutes' => self::YEAR, 'party' => 'first'],
        'lang_dismissed' => ['name' => 'qc_lang_dismissed', 'pattern' => '^qc_lang_dismissed$', 'category' => 'essential', 'purpose' => 'language_prompt', 'minutes' => self::YEAR, 'party' => 'first'],
        'theme' => ['name' => 'theme', 'pattern' => '^theme$', 'category' => 'essential', 'purpose' => 'theme', 'minutes' => self::YEAR, 'party' => 'first'],
        'owner_app' => ['name' => 'owner_pwa', 'pattern' => '^owner_pwa$', 'category' => 'essential', 'purpose' => 'owner_app', 'minutes' => self::YEAR, 'party' => 'first'],
        'table' => ['name' => 'mb_table_session', 'pattern' => '^mb_table_session$', 'category' => 'essential', 'purpose' => 'table', 'minutes' => 60 * 12, 'party' => 'first'],
        'goods_cart' => ['name' => 'mv_goods_cart_token', 'pattern' => '^mv_goods_cart_token$', 'category' => 'essential', 'purpose' => 'cart', 'minutes' => 60 * 24 * 14, 'party' => 'first'],
        'wishlist' => ['name' => 'site_wishlist', 'pattern' => '^site_wishlist$', 'category' => 'essential', 'purpose' => 'wishlist', 'minutes' => self::YEAR, 'party' => 'first'], // P3-08 guest wishlist token (asked for by the shopper)
        'cross_cart' => ['name' => 'mk_cart_token', 'pattern' => '^mk_cart_token$', 'category' => 'essential', 'purpose' => 'cart', 'minutes' => 60 * 24 * 30, 'party' => 'first'],
        'studio' => ['name' => 'studio_guest', 'pattern' => '^studio_guest$', 'category' => 'essential', 'purpose' => 'studio', 'minutes' => 60 * 24 * 3, 'party' => 'first'],
        'popup' => ['name' => 'WebsiteName', 'pattern' => '^WebsiteName$', 'category' => 'essential', 'purpose' => 'popup', 'minutes' => 60 * 24, 'party' => 'first'],
        // Analytics — only after consent.
        'ga' => ['name' => '_ga', 'pattern' => '^_ga$', 'category' => 'analytics', 'purpose' => 'ga', 'minutes' => self::YEAR * 2, 'party' => 'third', 'provider' => 'Google'],
        'ga_session' => ['name' => '_ga_*', 'pattern' => '^_ga_', 'category' => 'analytics', 'purpose' => 'ga', 'minutes' => self::YEAR * 2, 'party' => 'third', 'provider' => 'Google'],
        'gid' => ['name' => '_gid', 'pattern' => '^_gid$', 'category' => 'analytics', 'purpose' => 'ga', 'minutes' => 60 * 24, 'party' => 'third', 'provider' => 'Google'],
        'gat' => ['name' => '_gat*', 'pattern' => '^_gat', 'category' => 'analytics', 'purpose' => 'ga_throttle', 'minutes' => 1, 'party' => 'third', 'provider' => 'Google'],
        'tawk_uuid' => ['name' => 'twk_uuid_*', 'pattern' => '^twk_', 'category' => 'analytics', 'purpose' => 'chat', 'minutes' => 60 * 24 * 182, 'party' => 'third', 'provider' => 'Tawk.to'],
        'tawk_uuid_legacy' => ['name' => '__tawkuuid', 'pattern' => '^__tawk', 'category' => 'analytics', 'purpose' => 'chat', 'minutes' => 60 * 24 * 182, 'party' => 'third', 'provider' => 'Tawk.to'],
        'tawk_connection' => ['name' => 'TawkConnectionTime', 'pattern' => '^TawkConnectionTime$', 'category' => 'analytics', 'purpose' => 'chat', 'minutes' => null, 'party' => 'third', 'provider' => 'Tawk.to'],
        // Marketing — only after consent.
        'ref_code' => ['name' => 'ref_code', 'pattern' => '^ref_code$', 'category' => 'marketing', 'purpose' => 'referral', 'minutes' => 60 * 24 * 30, 'party' => 'first'],
        'store_ref' => ['name' => 'mb_ref', 'pattern' => '^mb_ref$', 'category' => 'marketing', 'purpose' => 'referral', 'minutes' => 60 * 24 * 30, 'party' => 'first'],
        'gclid' => ['name' => '_gcl_*', 'pattern' => '^_gcl_', 'category' => 'marketing', 'purpose' => 'ads', 'minutes' => 60 * 24 * 90, 'party' => 'third', 'provider' => 'Google'],
        'fbp' => ['name' => '_fbp', 'pattern' => '^_fbp$', 'category' => 'marketing', 'purpose' => 'meta', 'minutes' => 60 * 24 * 90, 'party' => 'third', 'provider' => 'Meta'],
        'fbc' => ['name' => '_fbc', 'pattern' => '^_fbc$', 'category' => 'marketing', 'purpose' => 'meta', 'minutes' => 60 * 24 * 90, 'party' => 'third', 'provider' => 'Meta'],
        'fr' => ['name' => 'fr', 'pattern' => '^fr$', 'category' => 'marketing', 'purpose' => 'meta', 'minutes' => 60 * 24 * 90, 'party' => 'third', 'provider' => 'Meta'],
    ];

    /** @var array<string, array{name: string, pattern: string, category: string, purpose: string, minutes: int|null, party: string, provider?: string}>|null */
    private static ?array $packageCookies = null;

    /**
     * Every cookie, Community + installed edition packages, with the runtime values filled in (session cookie name,
     * session lifetime).
     *
     * @return array<string, array{name: string, pattern: string, category: string, purpose: string, minutes: int|null, party: string, provider?: string}>
     */
    public static function all(): array
    {
        $all = self::COOKIES + self::packageCookies();
        $session = (string) config('session.cookie', 'site_session');
        $sessionMinutes = config('session.expire_on_close') ? null : (int) config('session.lifetime', 120);
        $all['session']['name'] = $session;
        $all['session']['pattern'] = '^'.preg_quote($session, '/').'$';
        $all['session']['minutes'] = $sessionMinutes;
        $all['xsrf']['minutes'] = $sessionMinutes;

        return $all;
    }

    /**
     * @return array{name: string, pattern: string, category: string, purpose: string, minutes: int|null, party: string, provider?: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** The registry entry whose pattern matches a real cookie name. */
    public static function forName(string $cookie): ?string
    {
        foreach (self::all() as $key => $entry) {
            if (preg_match('/'.$entry['pattern'].'/', $cookie) === 1) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<string> the categories a visitor turns on or off (the banner's toggles) */
    public static function optionalCategories(): array
    {
        return array_values(array_diff(self::CATEGORIES, ['essential']));
    }

    /** @return array<string, array<string, array{name: string, pattern: string, category: string, purpose: string, minutes: int|null, party: string, provider?: string}>> category => entries */
    public static function byCategory(): array
    {
        $out = array_fill_keys(self::CATEGORIES, []);
        foreach (self::all() as $key => $entry) {
            $out[$entry['category']][$key] = $entry;
        }

        return array_filter($out);
    }

    /**
     * One JavaScript regular expression source per optional category: the cookies the banner removes when the visitor
     * turns that category off.
     *
     * @return array<string, string>
     */
    public static function dropPatterns(): array
    {
        $out = [];
        foreach (self::optionalCategories() as $category) {
            $patterns = array_map(fn (array $e): string => '(?:'.$e['pattern'].')', self::byCategory()[$category] ?? []);
            $out[$category] = $patterns === [] ? '(?!)' : implode('|', $patterns);
        }

        return $out;
    }

    /** Lifetime in days of a consent-gated cookie the server writes ({@see CookieConsent::rememberAttribution}). */
    public static function days(string $cookie): int
    {
        $key = self::forName($cookie);
        $entry = $key === null ? null : self::all()[$key];
        if ($entry === null || $entry['category'] === 'essential' || $entry['minutes'] === null) {
            throw new \LogicException("cookie [{$cookie}] is not a consent-gated cookie of the cookie registry");
        }

        return max(1, intdiv($entry['minutes'], 60 * 24));
    }

    /** "2 years", "until you close the browser" — in the current locale. */
    public static function lifetime(?int $minutes): string
    {
        if ($minutes === null) {
            return (string) __('cookies.lifetime.session');
        }

        return CarbonInterval::minutes($minutes)->cascade()->locale(app()->getLocale())->forHumans(['parts' => 1]);
    }

    /** Test seam: forget the edition packages' cookies (EditionPackages::fake). */
    public static function flush(): void
    {
        self::$packageCookies = null;
    }

    /** @return array<string, array{name: string, pattern: string, category: string, purpose: string, minutes: int|null, party: string, provider?: string}> */
    private static function packageCookies(): array
    {
        if (self::$packageCookies !== null) {
            return self::$packageCookies;
        }
        $out = [];
        foreach (EditionPackages::all() as $package) {
            $file = $package['manifest']['cookies'] ?? null;
            if (is_string($file) && is_file($file)) {
                $entries = (static fn (string $f): mixed => require $f)($file);
                foreach (is_array($entries) ? $entries : [] as $key => $entry) {
                    $out[(string) $key] = $entry;
                }
            }
        }

        return self::$packageCookies = $out;
    }
}
