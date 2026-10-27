<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Support\Csp\DashboardPolicy;
use Juwri\Core\Support\Csp\Directive;
use Juwri\Core\Support\Csp\PolicyFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * S12: apply {@see DashboardPolicy} to the privileged surfaces.
 *
 * The storefront AddCspHeaders reads the single global config policy (the
 * storefront one) — a per-route policy passed as a middleware arg would apply
 * ENFORCING, which is unsafe on the not-yet-fully-nonced dashboard. This thin
 * wrapper applies the DashboardPolicy in REPORT-ONLY mode by default so it can
 * ship dark (breaks nothing, just reports to /csp-report), and only enforces
 * when the owner sets `CSP_ENFORCE_DASHBOARD=true` (independent of the
 * storefront's CSP_ENFORCE so enforcing one never breaks the other).
 */
class AddDashboardCspHeaders
{
    /**
     * P6-122: request attribute a controller sets to widen connect-src for its own page (a list of http(s)/ws(s) URLs; only
     * their origin is used). The POS register names its print-bridge addresses here. Anything that is not a plain origin is
     * dropped, so a stored value can never inject another directive.
     */
    public const EXTRA_CONNECT = 'csp.extra_connect';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('csp.enabled', true)) {
            return $response;
        }

        $policy = PolicyFactory::create(DashboardPolicy::class);

        if (! config('csp.enforce_dashboard', false)) {
            $policy->reportOnly();
        }

        foreach ((array) $request->attributes->get(self::EXTRA_CONNECT, []) as $url) {
            if (($origin = self::origin((string) $url)) !== null) {
                $policy->addDirective(Directive::CONNECT, $origin);
            }
        }

        if ($policy->shouldBeApplied($request, $response)) {
            $policy->applyTo($response);
        }

        return $response;
    }

    /** scheme://host[:port] of an http(s)/ws(s) URL, or null when it is not one. */
    public static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https', 'ws', 'wss'], true) || ! preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/', $host)) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }
}
