<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-13 13c: moved from routes/web.php (slot Channels/web-5); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

    // HungerStation inbound webhook — register BEFORE webhook/{gateway} or it's shadowed.
    Route::post('webhook/hungerstation', '\Juwri\Modules\Channels\Http\Controllers\HungerStationWebhookController@inbound')->middleware(['ratelimit:webhook-in', 'throttle:120,1,channels-webhook-hungerstation'])->name('webhook.hungerstation');
    // N7 Square webhooks (single-segment) — register BEFORE webhook/{gateway} or it's shadowed.
    Route::post('webhook/square', '\Juwri\Modules\Channels\Http\Controllers\SquareWebhookController@inbound')->middleware(['ratelimit:webhook-in', 'throttle:120,1,channels-webhook-square'])->name('webhook.square.inbound');
