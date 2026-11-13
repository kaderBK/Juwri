<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Legal\CookieConsent;
use Symfony\Component\HttpFoundation\Response;

/**
 * HPMS E4: guest refer-a-friend capture. A share link is `{store}?ref=CODE`; when
 * a visitor arrives with `?ref=`, stash it in the `mb_ref` cookie (30 days) so it
 * survives until the referee's first paid order, where LoyaltyAccrual credits both
 * sides. No-op unless `ref` is present and no stash already exists; never throws.
 */
class CaptureGuestReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $ref = $request->query('ref');
            if (is_string($ref) && $ref !== '' && strlen($ref) <= 16
                && ctype_alnum($ref) && CookieConsent::attribution($request, 'mb_ref') === null) {
                // P1-23: 30-day cookie only with marketing consent, else the session (this visit's order).
                CookieConsent::rememberAttribution($request, 'mb_ref', $ref);
            }
        } catch (\Throwable $e) {
            // best-effort — capture must never break a page load
        }

        return $next($request);
    }
}
