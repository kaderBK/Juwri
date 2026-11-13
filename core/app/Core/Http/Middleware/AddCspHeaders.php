<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Juwri\Core\Support\Csp\Policy;
use Juwri\Core\Support\Csp\PolicyFactory;
use App\Support\Csp\StorefrontPolicy;
use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Http\Hosts\HostMap;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the storefront CSP from config/csp.php: `policy` enforcing and/or
 * `report_only_policy` report-only, or the policy class given as a middleware
 * argument (enforcing). P1-00a: replaces Spatie\Csp\AddCspHeaders (J18).
 */
class AddCspHeaders
{
    public function handle(Request $request, Closure $next, ?string $policyClass = null): Response
    {
        $response = $next($request);

        /* A 304 has no body: the browser keeps the page it cached and merges the 304's headers onto that stored
           response. The policy carries a per-request nonce, so a fresh one here would sit next to the OLD body whose
           inline scripts and styles carry the old nonce, and every one of them would be refused (a report each under
           report-only, a broken page under an enforced policy; found by the csp-clean e2e on every theme's reload).
           Send no CSP on a 304 and the stored header, which matches the stored body, stays in force. */
        if ($response->getStatusCode() === Response::HTTP_NOT_MODIFIED) {
            return $response;
        }

        foreach ($this->policies($policyClass, $request) as $policy) {
            if ($policy->shouldBeApplied($request, $response)) {
                $policy->applyTo($response);
            }
        }

        return $response;
    }

    /** @return list<Policy> */
    private function policies(?string $policyClass, Request $request): array
    {
        if ($policyClass) {
            return [PolicyFactory::create($policyClass)];
        }

        // P1-20 (J28): on a store's own origin the storefront policy is ENFORCED, never report-only.
        if (self::enforcedHere($request)) {
            return [PolicyFactory::create(StorefrontPolicy::class)];
        }

        $policies = [];
        if (! empty(config('csp.policy'))) {
            $policies[] = PolicyFactory::create((string) config('csp.policy'));
        }
        if (! empty(config('csp.report_only_policy'))) {
            $policies[] = PolicyFactory::create((string) config('csp.report_only_policy'))->reportOnly();
        }

        return $policies;
    }

    /**
     * csp.storefront_enforce: `store_hosts` (default) enforces on store hosts (verified custom domains,
     * platform subdomains) and, in split mode, on the storefront host; `all` everywhere this middleware
     * runs; `off` leaves the CSP_ENFORCE / per-template switches alone (pre-P1-20 behaviour).
     */
    public static function enforcedHere(Request $request): bool
    {
        $mode = (string) config('csp.storefront_enforce', 'store_hosts');
        if ($mode === 'all') {
            return true;
        }
        if ($mode !== 'store_hosts') {
            return false;
        }
        $kind = ApplyHostProfile::kindOf($request);

        return $kind === HostMap::STORE || (HostMap::split() && $kind === HostMap::STOREFRONT);
    }
}
