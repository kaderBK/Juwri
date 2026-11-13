<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/marketplace.php (slot Ordering/marketplace-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\AddCspHeaders;

// P6-122b: enforced storefront policy (the POST checkout renders the confirmation page; the GETs only redirect).
Route::controller('\Juwri\Modules\Ordering\Http\Controllers\OrderHubController')->prefix('order-hub')->name('orderhub.')->middleware(AddCspHeaders::class)->group(function () {
    Route::get('/', 'index')->name('index');                    // 301 → /fusion
    Route::get('/store/{slug}', 'store')->name('store');        // 301 → /fusion/store/{slug} (mkApproved) else /fusion
    Route::post('/add', 'add')->name('add')->middleware(['throttle:60,1,ordering-add']);
    Route::post('/update', 'update')->name('update')->middleware(['throttle:60,1,ordering-update']);
    Route::post('/remove', 'remove')->name('remove')->middleware('throttle:60,1,ordering-remove');
    Route::get('/checkout', 'checkout')->name('checkout');      // 301 → /fusion/bag
    Route::post('/checkout', 'placeOrder')->name('placeOrder')->middleware(['throttle:20,1,ordering-checkout', 'ratelimit:public-write']);
});
