<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-13 13c: moved from routes/web.php (slot Channels/web-6); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

    /* #98: inbound delivery-platform orders (Uber Eats, etc.). CSRF-exempt via webhook/*. */
    Route::post('webhook/delivery/{provider}', '\Juwri\Modules\Channels\Http\Controllers\DeliveryWebhookController@inbound')->middleware('ratelimit:webhook-in')->name('webhook.delivery.inbound');
