<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services\Passkeys;

use Illuminate\Http\Request;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;

/**
 * P1-19 (customer passkeys): which WebAuthn relying party a storefront request speaks for. A passkey is bound to
 * its RP ID (a host); the RP is the host serving the store's page, never a value the browser sends (J28):
 *
 *  - the store's own host (verified custom domain or platform subdomain, recognised by the Storefront resolver)
 *    — only for THAT store: a store host never offers passkeys for another store's id;
 *  - the shared storefront host of the path form `/{slug}` (STOREFRONT_URL in HOST_MODE=split, the APP_URL host
 *    in single-host mode). Stores on a shared host share the RP ID, so the store scope is enforced on the
 *    passkey row (CustomerPasskeys looks a credential up for the page's store only).
 *
 * The dashboard and admin hosts in split mode, the marketing host and unknown hosts get none.
 */
final class CustomerRelyingParty
{
    /**
     * @return array{id: string, origin: string, name: string}|null
     */
    public static function for(Request $request, Store $store): ?array
    {
        if (! config('passkeys.enabled', true)) {
            return null;
        }

        $host = strtolower(rtrim($request->getHost(), '.'));
        $name = trim((string) ($store->title ?? '')) ?: $host;

        if ($request->attributes->has(HostMap::STORE_ATTRIBUTE)) {
            if ((int) $request->attributes->get(HostMap::STORE_ATTRIBUTE) !== (int) $store->id) {
                return null;
            }

            return ['id' => $host, 'origin' => strtolower($request->getSchemeAndHttpHost()), 'name' => $name];
        }

        $split = HostMap::split();
        if (HostMap::kind($host) !== ($split ? HostMap::STOREFRONT : HostMap::APP)) {
            return null;
        }
        $base = $split ? HostMap::storefrontUrl() : HostMap::appUrl();
        $id = HostMap::hostOf($base);
        // `www.` of the app host in single mode: the configured origin is what the browser must report.
        if ($id === '' || $id !== $host) {
            return null;
        }

        return ['id' => $id, 'origin' => self::origin($base), 'name' => $name];
    }

    /** Point laravel/passkeys at this request's relying party. False when passkeys are not offered here. */
    public static function configure(Request $request, Store $store): bool
    {
        $rp = self::for($request, $store);
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
