<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\DB;

// AL-40 (auto-locale v1) — geo helpers, kept OUT of the god-file Helper.php so it
// stays under its size ceiling (ControllerSizeGuardTest). Autoloaded via
// composer.json "files".

if (! function_exists('visitor_country')) {
    /**
     * The visitor's ISO-3166 country code for geo-aware defaults (AL-20), or null
     * when geo is off. PRIVACY: never sends the IP anywhere — the only sources are a
     * trusted-proxy Cloudflare header or a LOCAL GeoLite2 mmdb. Ships dark
     * (config/geoip.php has neither set by default), so this returns null and callers
     * treat geo as absent. Result is per-request; callers must not cache it globally.
     */
    function visitor_country(): ?string
    {
        $req = request();

        // 1) Cloudflare's edge-geolocated header — trusted ONLY when the request
        //    actually arrived through a configured trusted proxy, so a direct client
        //    cannot spoof CF-IPCountry. XX (unknown) / T1 (Tor) resolve to null.
        if (config('geoip.trust_cf_country') && $req->isFromTrustedProxy()) {
            $cf = strtoupper(trim((string) $req->headers->get('CF-IPCountry', '')));
            if (strlen($cf) === 2 && $cf !== 'XX' && $cf !== 'T1') {
                return $cf;
            }
        }

        // 2) Local MaxMind GeoLite2 lookup (geoip2/geoip2), only if the owner shipped
        //    an mmdb. Best-effort: any reader/lookup failure is geo-absent, never fatal.
        $mmdb = (string) config('geoip.mmdb_path');
        if ($mmdb !== '' && is_file($mmdb)) {
            try {
                $reader = new Reader($mmdb);
                $code = strtoupper((string) ($reader->country($req->ip())->country->isoCode ?? ''));

                return strlen($code) === 2 ? $code : null;
            } catch (Throwable $e) {
                return null;
            }
        }

        return null; // dark by default — owner provisions a source per install
    }
}

if (! function_exists('currency_country_map')) {
    /**
     * AL-20: { ISO-3166 country code → ISO-4217 currency } from the countries table,
     * FILTERED to a set of offered currency codes (a store's parsed display rates).
     * Lets the storefront's currency-default script turn a visitor's region/geo
     * country into a candidate currency the store actually offers — nothing else.
     * Memoized per offered-set and rebound on app instance (mirrors store_options).
     *
     * @param  array<int, string>  $offered  uppercase currency codes the store offers
     * @return array<string, string>
     */
    function currency_country_map(array $offered): array
    {
        $offered = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $offered)));
        sort($offered);
        if ($offered === []) {
            return [];
        }

        $key = 'memo.currency_country_map.'.md5(implode(',', $offered));
        if (app()->bound($key)) {
            return app($key);
        }

        $map = [];
        try {
            DB::table('countries')
                ->select('code', 'currency')
                ->whereIn('currency', $offered)
                ->orderBy('code')
                ->each(function ($row) use (&$map) {
                    $cc = strtoupper(trim((string) $row->code));
                    $cur = strtoupper(trim((string) $row->currency));
                    if (strlen($cc) === 2 && strlen($cur) === 3) {
                        $map[$cc] = $cur;
                    }
                });
        } catch (Throwable $e) {
            $map = [];
        }

        app()->instance($key, $map);

        return $map;
    }
}
