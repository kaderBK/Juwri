<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Support;

use Illuminate\Http\Request;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Contracts\Tenancy\ResellerContext;

/**
 * P1-19: which WebAuthn relying party a request speaks for. A passkey is bound to its RP ID (a host), so
 * the RP is the platform host serving the page — never a value the browser sends:
 *
 *  - the dashboard host (APP_URL) — owners, reseller owners, and superadmins in single-host mode;
 *  - the admin host (APP_ADMIN_URL) in HOST_MODE=split — superadmins (J28: own origin, own passkeys);
 *  - an active reseller's verified custom domain (white-label sign-in, single-host mode only — split mode
 *    never serves sign-in routes on store/reseller hosts).
 *
 * Storefront, store, marketing and unknown hosts get none: passkeys are not offered there.
 */
final class PasskeyRelyingParty
{
    /**
     * @return array{id: string, origin: string, kind: string}|null
     */
    public static function for(Request $request): ?array
    {
        if (! config('passkeys.enabled', true)) {
            return null;
        }

        $host = strtolower(rtrim($request->getHost(), '.'));
        $kind = HostMap::kind($host);

        if ($kind === HostMap::APP || $kind === HostMap::ADMIN) {
            $base = $kind === HostMap::ADMIN ? HostMap::adminUrl() : HostMap::appUrl();
            $id = HostMap::hostOf($base);
            // `www.` of the app host in single mode: the configured origin is what the browser must report.
            if ($id !== $host) {
                return null;
            }

            return ['id' => $id, 'origin' => self::origin($base), 'kind' => $kind];
        }

        if (! HostMap::split() && $kind === HostMap::UNKNOWN && app(ResellerContext::class)->forRequest() !== null) {
            return ['id' => $host, 'origin' => $request->getSchemeAndHttpHost(), 'kind' => 'reseller'];
        }

        return null;
    }

    /**
     * Point laravel/passkeys at this request's relying party. False when passkeys are not offered here.
     */
    public static function configure(Request $request): bool
    {
        $rp = self::for($request);
        if ($rp === null) {
            return false;
        }

        config([
            'passkeys.relying_party_id' => $rp['id'],
            'passkeys.allowed_origins' => [$rp['origin']],
        ]);

        return true;
    }

    /** scheme://host[:port] of a configured URL. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
