<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\User\Concerns;

use Illuminate\Http\RedirectResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\ExternalRedirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * rev/external-redirect: the platform-migration opt-out surface on StoreController
 * (M15-style Concerns extraction — no behaviour change). Holds the menu-load hook
 * resolver and the owner dashboard "Disable" endpoint.
 */
trait HandlesExternalRedirect
{
    /**
     * A2: a menu load's opt-out redirect. Returns a 302 / interstitial when the
     * store has left the platform, else null to render normally. The inline
     * status==1 is the H1 abuse guard — a superadmin-BANNED store (status 0) goes
     * dark and does NOT redirect, while an expired-PLAN store (account still
     * active) still does (plan status is independent of account status). publicView
     * calls this ABOVE the biolink-first interstitial, so a departed store wins;
     * because it returns before the ETag/304 and scan-counter blocks, a redirected
     * visit serves no cached menu and burns no scan quota. 302 is un-cacheable
     * (no-store) so toggling off takes effect at once.
     */
    protected function externalMenuRedirect(?Store $store): ?Response
    {
        if ($store && $store->user && $store->user->status == 1) {
            return ExternalRedirect::resolve($store, request());
        }

        return null;
    }

    /**
     * B2: the owner dashboard "Disable" button on the "menu redirects to …"
     * banner. Turns the menu redirect off (keeps the URL for easy re-enable),
     * audited + sitemap-busted, then returns to the dashboard.
     */
    public function disableExternalRedirect(Store $store): RedirectResponse
    {
        abort_unless($store->user_id === request()->user()->id, 403);

        ExternalRedirect::clearMenu($store);
        app_alert_success(___('Menu redirect disabled.'));

        return back();
    }
}
