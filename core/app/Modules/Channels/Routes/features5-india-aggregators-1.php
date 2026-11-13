<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features5/india-aggregators.php (slot Channels/features5-india-aggregators-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Channels\Http\Controllers\Aggregators\SwiggyWebhookController;
use Juwri\Modules\Channels\Http\Controllers\Aggregators\ZomatoWebhookController;

/* -------------------------------------------------------------------------
 | Partner webhooks (anonymous, HMAC-verified inside the controller).
 | One fixed callback URL per provider: partner consoles hold a single value
 | per outlet, so the provider must be in the PATH, not in the payload.
 * ---------------------------------------------------------------------- */
Route::middleware(['web'])->group(function () {
    Route::post('webhook/zomato/orders', [ZomatoWebhookController::class, 'inbound'])
        ->middleware(['throttle:240,1,channels-webhook-zomato-orders', 'ratelimit:webhook-in'])
        ->name('webhook.zomato.inbound');

    Route::post('webhook/swiggy/orders', [SwiggyWebhookController::class, 'inbound'])
        ->middleware(['throttle:240,1,channels-webhook-swiggy-orders', 'ratelimit:webhook-in'])
        ->name('webhook.swiggy.inbound');
});
