<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Pci\PaymentPagePolicy;
use Juwri\Core\Pci\PaymentPages;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * PCI DSS 4.0 6.4.3 (global stack): a response that rendered a payment page ({@see PaymentPages}) gets that page's
 * strict {@see PaymentPagePolicy}, ENFORCED (config payment_scripts.csp.enforce, default on). It replaces the weaker
 * policy an inner middleware may have set (storefront / dashboard), so the payment page never runs under a policy
 * that allows more than the inventory.
 */
class AddPaymentPageCsp
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $page = PaymentPages::current($request);
        if ($page === null || ! config('payment_scripts.csp.enabled', true) || ! config('csp.enabled', true)) {
            return $response;
        }
        // A 304 keeps the browser's stored page and its stored policy (same reason as AddCspHeaders); a redirect has no page.
        if ($response->getStatusCode() === Response::HTTP_NOT_MODIFIED || $response instanceof RedirectResponse) {
            return $response;
        }

        $policy = new PaymentPagePolicy($page);
        if (! config('payment_scripts.csp.enforce', true)) {
            $policy->reportOnly();
        }
        $header = $policy->prepareHeader();
        $response->headers->set($header, (string) $policy);
        $response->headers->set('Reporting-Endpoints', PaymentPagePolicy::reportingEndpoints($page));

        return $response;
    }
}
