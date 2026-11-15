<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\User;

use App\Http\Controllers\MarketplaceController;
use App\Models\AppInstallation;
use App\Services\Theme\ThemeSets;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\AppStore\Http\Controllers\AppStoreController;
use Juwri\Modules\Payments\Models\PaymentGateway;

/**
 * ST2 + ST3 (features-run-4): the App Store and Theme Store rendered INSIDE the
 * owner dashboard chrome (authed) instead of the public marketplace shell.
 *
 * This is a thin presentation shim: the catalog/detail query + faceting logic
 * is NOT re-implemented here. We delegate to the existing public controllers
 * (Juwri\Modules\AppStore\Http\Controllers\AppStoreController / MarketplaceController), pull their
 * prepared view data via ->getData(), and re-render it into dashboard-chrome
 * Blade views. Commerce (AppInstallService / ThemePurchaseService) is untouched.
 */
class AppStoreBrowseController extends Controller
{
    /** ST2: App Store browse inside the dashboard. */
    public function apps(Request $request)
    {
        $data = app(AppStoreController::class)->index($request)->getData();

        return view('owner.marketplace.apps', $data);
    }

    /** ST2: App Store detail inside the dashboard (inline install CTA). */
    public function appShow(string $slug)
    {
        $data = app(AppStoreController::class)->show($slug)->getData();
        // Reinstalls never get a second trial (AppInstallService), so only offer it
        // to an owner who has never installed this app.
        $data['trialEligible'] = isset($data['app']) && $data['app']->hasTrial()
            && ! AppInstallation::where('app_id', $data['app']->id)->where('user_id', auth()->id())->exists();

        return view('owner.marketplace.app-show', $data + $this->topupContext());
    }

    /** ST3: Theme Store browse inside the dashboard. */
    public function themes(Request $request)
    {
        // Scoped to the ACTIVE store's theme set: a clothing store is offered
        // the retail themes (clothing, gifts) and never a restaurant's. With
        // no active store yet the whole catalogue shows, same as the public
        // marketplace. The set comes from the vertical registry, so every
        // vertical — retail, service, hotel — gets its own family.
        $active = dashboard_current_store();
        $set = $active
            ? app(ThemeSets::class)->forStoreType($active->store_type ?? null)
            : null;

        $data = app(MarketplaceController::class)->index($request, $set)->getData();

        return view('owner.marketplace.themes', $data);
    }

    /** ST3: Theme Store detail inside the dashboard (apply picker + preview). */
    public function themeShow(string $slug)
    {
        $data = app(MarketplaceController::class)->show($slug)->getData();

        return view('owner.marketplace.theme-show', $data + $this->topupContext());
    }

    /**
     * ST4: shared top-up context for the purchase CTAs. When the wallet is short,
     * the buyer picks one of these gateways to fund the exact shortfall and the
     * purchase auto-completes on gateway success (StorePurchaseService).
     *
     * @return array{topupEnabled:bool, topupGateways:Collection}
     */
    private function topupContext(): array
    {
        $enabled = (bool) config('settings.wallet_topup_enabled', true);

        return [
            'topupEnabled' => $enabled,
            'topupGateways' => $enabled
                ? PaymentGateway::withoutMor()->where('payment_install', true)->get()
                : collect(),
        ];
    }
}
