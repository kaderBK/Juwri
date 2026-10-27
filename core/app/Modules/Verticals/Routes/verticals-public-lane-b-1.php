<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/public/lane-b.php (slot Verticals/verticals-public-lane-b-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Lane B public storefront routes go here.

// B6: cart qty + line semantics for goods/service verticals. Multi-segment
// ({slug}/goods/..., {slug}/cart) so none of these can ever be mistaken for a
// bare store {slug} by the catch-all below.
Route::get('{slug}/goods/{menu}', '\Juwri\Modules\Verticals\Http\Controllers\User\GoodsCartController@showProduct')->name('storefront.goods.product');
Route::post('{slug}/goods/{menu}/cart', '\Juwri\Modules\Verticals\Http\Controllers\User\GoodsCartController@addToCart')->name('storefront.goods.cart.add')->middleware('throttle:60,1,goods-cart-add');
Route::get('{slug}/cart', '\Juwri\Modules\Verticals\Http\Controllers\User\GoodsCartController@show')->name('storefront.goods.cart.show');
Route::post('{slug}/cart/{line}/quantity', '\Juwri\Modules\Verticals\Http\Controllers\User\GoodsCartController@updateLine')->name('storefront.goods.cart.line.update')->middleware('throttle:60,1,goods-cart-line');

// B2/B3: ship-to-address + pickup-slot checkout for goods/service verticals.
Route::get('{slug}/checkout', '\Juwri\Modules\Verticals\Http\Controllers\User\GoodsCheckoutController@show')->name('storefront.checkout.show');
Route::post('{slug}/checkout', '\Juwri\Modules\Verticals\Http\Controllers\User\GoodsCheckoutController@place')->name('storefront.checkout.place')->middleware('throttle:20,1,goods-checkout-place');
