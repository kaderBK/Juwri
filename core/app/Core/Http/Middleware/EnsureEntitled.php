<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * P1-4 (feature-platform): PLAN-AWARE gate — `entitled:<key>`.
 *
 * Complements (never replaces) the kill-switch-only `feature:<key>` middleware:
 * `feature:` answers "is this feature ON for the platform", `entitled:` answers
 * "did THIS customer pay for it" via Entitlements::allows (plan snapshot →
 * rows → legacy blob → overrides; kill-switch still vetoes inside allows()).
 *
 * Registered in Kernel but applied to routes gradually from P2 on — attaching
 * it flips a route from open to plan-gated, which is a sales decision.
 */
class EnsureEntitled
{
    public function handle(Request $request, Closure $next, string $key)
    {
        $owner = function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();

        if ($owner && \App\Services\Entitlements::allows($owner, $key)) {
            return $next($request);
        }

        /* P2-4 (feature-platform): render the paywall instead of a bare 403 —
           GET requests for a sellable feature redirect to the feature-locked
           page (show-locked beats hidden).

           M0-5 (monetization): keyed on FeatureUpsell::sellable(), NOT the old
           two-key PILOT allowlist. That allowlist was a genuine dead end — an
           `entitled:` route for any key outside {hotel, pos_enabled} 403'd with
           no buy path even though the catalog could sell it. sellable() is the
           same test EnsureFeatureEnabled already uses, so both gates now render
           the same paywall for the same keys. */
        if ($request->isMethod('GET')
            && ! $request->expectsJson()
            && \Illuminate\Support\Facades\Route::has('feature.locked')
            && app(\Juwri\Core\Contracts\Billing\FeatureCommerce::class)->sellable($key)) {
            return redirect()->route('feature.locked', $key);
        }

        abort(403, ___('This feature is not included in your current plan.'));
    }
}
