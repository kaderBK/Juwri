<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;

/**
 * P1-20 (J28, SPEC §6): the `ask` endpoint for Caddy on-demand TLS (`on_demand_tls { ask … }`). A certificate
 * is issued only for a host this platform really serves: its own hosts, a verified store domain (custom or
 * platform subdomain, plus the `www.` alias of a verified custom domain) or an active reseller domain.
 * 200 = issue, 404 = refuse. Optional shared secret: hosts.tls_ask_token (TLS_ASK_TOKEN) as `?token=`.
 */
class TlsAskController
{
    public function __invoke(Request $request, StoreAddressResolver $resolver): Response
    {
        $token = (string) config('hosts.tls_ask_token', '');
        if ($token !== '' && ! hash_equals($token, (string) $request->query('token', ''))) {
            return response('', 404);
        }
        $host = StoreDomain::normalizeHost((string) $request->query('domain', ''));
        if ($host === '') {
            return response('', 404);
        }

        return response('', self::serves($host, $resolver) ? 200 : 404);
    }

    public static function serves(string $host, StoreAddressResolver $resolver): bool
    {
        if (in_array($host, HostMap::platformHosts(), true) || $host === 'www.'.HostMap::marketingHost() || HostMap::isStorefrontAlias($host)) {
            return true;
        }
        if ($resolver->domainForHost($host) !== null) {
            return true;
        }

        return app(ResellerContext::class)->isActiveCustomDomain($host);
    }
}
