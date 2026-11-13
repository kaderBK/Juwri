<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Channels/web-4); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// N1 Foodics POS webhook (order.updated / menu.updated). Tenant = per-connection token.
    Route::post('webhook/foodics/{token}', '\Juwri\Modules\Channels\Http\Controllers\FoodicsWebhookController@inbound')->middleware('ratelimit:webhook-in')->name('webhook.foodics.inbound');
    // N4 Marn POS webhook (order state/status + services-update). Tenant = per-connection token.
    Route::post('webhook/marn/{token}', '\Juwri\Modules\Channels\Http\Controllers\MarnWebhookController@inbound')->middleware('ratelimit:webhook-in')->name('webhook.marn.inbound');
    // N6 Deliverect POS-track endpoints (they call us: register / syncproducts / order-cancel). HMAC-verified.
    Route::post('webhook/deliverect/register', '\Juwri\Modules\Channels\Http\Controllers\DeliverectPosController@register')->middleware('ratelimit:webhook-in')->name('webhook.deliverect.register');
    Route::post('webhook/deliverect/syncproducts', '\Juwri\Modules\Channels\Http\Controllers\DeliverectPosController@syncProducts')->middleware('ratelimit:webhook-in')->name('webhook.deliverect.syncproducts');
    Route::post('webhook/deliverect/ordercancel', '\Juwri\Modules\Channels\Http\Controllers\DeliverectPosController@orderCancel')->middleware('ratelimit:webhook-in')->name('webhook.deliverect.ordercancel');
