<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features4/store4.php (slot AppStore/features4-store4-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/*
 * ST4 — in-dashboard purchase UX (wallet + top-up shortfall resume).
 * Buy/install run through StorePurchaseController → StorePurchaseService, which
 * reuses the EXISTING wallet debit + self-service top-up choke points (no new
 * gateway/money code). On insufficient balance the CTA gets a 402 with the exact
 * shortfall; POSTing to the topup route funds it and the purchase auto-completes
 * at the wallet top-up choke point (CheckoutController::creditWalletTopup).
 *
 * Auth-gated (owner dashboard). `web` middleware already applies (required from
 * inside routes/web.php). Slugs match the marketplace pattern.
 *
 * OWNER-ONLY: a staff dashboard session authenticates the web guard AS THE OWNER
 * (StaffLoginController), so `auth` alone let any staff role spend the owner's
 * money here — buy-card issues a real gateway charge, install/buy debit the wallet,
 * topup-continue starts a gateway top-up. `staff.dashboard` default-denies these
 * unmapped routes for any staff session (403) while the owner passes through, the
 * same fence that protects billing/subscription/api-token routes.
 */
Route::middleware(['auth', 'staff.dashboard'])->group(function () {
    Route::post('user/store/themes/{slug}/buy', [\Juwri\Modules\AppStore\Http\Controllers\User\StorePurchaseController::class, 'buyTheme'])
        ->name('user.store.buy.theme')
        ->where('slug', '[a-z0-9\-]+');

    Route::post('user/store/apps/{appId}/install', [\Juwri\Modules\AppStore\Http\Controllers\User\StorePurchaseController::class, 'installApp'])
        ->name('user.store.install.app')
        ->where('appId', '[0-9]+');

    Route::post('user/store/apps/{appId}/buy-card', [\Juwri\Modules\AppStore\Http\Controllers\User\StorePurchaseController::class, 'buyAppWithCard'])
        ->name('user.store.buy.app.card')
        ->where('appId', '[0-9]+'); // P4-3 feature-platform dual-rail

    Route::post('user/store/topup-continue', [\Juwri\Modules\AppStore\Http\Controllers\User\StorePurchaseController::class, 'topupContinue'])
        ->name('user.store.topup.continue');
});
