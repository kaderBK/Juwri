<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P3-08: the goods checkout's shipping quote (methods, fees, delivery dates for the cart and an address); included
// from routes/verticals/public/lane-retail.php.
use Illuminate\Support\Facades\Route;

Route::post('{slug}/shipping/quote', '\Juwri\Modules\Verticals\Http\Controllers\User\GoodsCheckoutController@shippingQuote')->name('storefront.shipping.quote')->middleware(['throttle:30,1,verticals-shipping-quote', 'ratelimit:public-write']);
