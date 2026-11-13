<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Every throttle has its OWN prefix (third parameter): without one, Laravel counts all `throttle:N,1` routes of an IP in one bucket, so a
// shopper's quote requests used up the 10-a-minute allowance of the return request.

// P3-08: retail storefront endpoints (wishlist, back-in-stock, size guide, returns); included from
// routes/verticals/public/lane-retail.php inside the storefront-localized group, before the {slug} catch-all.
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Storefront\Http\Controllers\RetailPublicController;
use Juwri\Modules\Storefront\Http\Controllers\StorefrontGrocerySlotsController;
use Juwri\Modules\Storefront\Http\Controllers\StorefrontRetailCheckoutController;

Route::get('{slug}/wishlist', [RetailPublicController::class, 'wishlist'])->name('storefront.wishlist.index')->middleware('throttle:60,1,wishlist-list');
Route::post('{slug}/wishlist', [RetailPublicController::class, 'wishlistAdd'])->name('storefront.wishlist.store')->middleware(['throttle:30,1,wishlist-add', 'ratelimit:public-write']);
Route::delete('{slug}/wishlist/{item}', [RetailPublicController::class, 'wishlistRemove'])->name('storefront.wishlist.destroy')->middleware(['throttle:30,1,wishlist-remove', 'ratelimit:public-write']);
Route::post('{slug}/stock-alerts', [RetailPublicController::class, 'stockAlert'])->name('storefront.stockAlerts.store')->middleware(['throttle:10,1,stock-alert', 'ratelimit:public-write']);
Route::get('{slug}/size-guide/{guide}', [RetailPublicController::class, 'sizeGuide'])->name('storefront.sizeGuide')->middleware('throttle:60,1,size-guide');
Route::get('order/track/{token}/returns', [RetailPublicController::class, 'returnsShow'])->name('storefront.returns.show')->middleware('throttle:30,1,returns-show');
Route::post('order/track/{token}/returns', [RetailPublicController::class, 'returnsStore'])->name('storefront.returns.store')->middleware(['throttle:10,1,returns-store', 'ratelimit:public-write']);

// P3-08 (aisle): the goods checkout of the storefront's own pages — quote, shipping methods, order (+ hosted payment).
Route::post('{slug}/retail/quote', [StorefrontRetailCheckoutController::class, 'quote'])->name('storefront.retail.quote')->middleware('throttle:120,1,retail-quote');
Route::post('{slug}/retail/shipping-quote', [StorefrontRetailCheckoutController::class, 'shippingQuote'])->name('storefront.retail.shippingQuote')->middleware('throttle:60,1,retail-shipping');
Route::post('{slug}/retail/order', [StorefrontRetailCheckoutController::class, 'place'])->name('storefront.retail.place')->middleware(['throttle:20,1,retail-place', 'ratelimit:public-write']);

// P3-09 (basket): the delivery / collection time of the grocery pages — hourly slots, a 10-minute hold, its release.
Route::get('{slug}/grocery/slots', [StorefrontGrocerySlotsController::class, 'index'])->name('storefront.grocery.slots')->middleware('throttle:60,1,grocery-slots');
Route::post('{slug}/grocery/hold', [StorefrontGrocerySlotsController::class, 'hold'])->name('storefront.grocery.hold')->middleware(['throttle:20,1,grocery-hold', 'ratelimit:public-write']);
Route::delete('{slug}/grocery/hold/{hold}', [StorefrontGrocerySlotsController::class, 'release'])->name('storefront.grocery.release')->middleware(['throttle:30,1,grocery-release', 'ratelimit:public-write']);
