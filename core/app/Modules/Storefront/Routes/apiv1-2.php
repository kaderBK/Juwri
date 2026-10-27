<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
// Shared throttle prefixes on purpose (one counter across all routes using it): otp-send (every customer-OTP send, one limit of 5 a minute); otp-verify (every customer-OTP verify, one limit of 10 a minute).

// P1-09: moved from routes/apiv1.php (slot Storefront/apiv1-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Storefront\Http\Controllers\Api\V1\StorefrontBookingController;
use Juwri\Modules\Storefront\Http\Controllers\Api\V1\StorefrontCatalogController;
use Juwri\Modules\Storefront\Http\Controllers\Api\V1\StorefrontCheckoutController;
use Juwri\Modules\Storefront\Http\Controllers\Api\V1\StorefrontController;
use Juwri\Modules\Storefront\Http\Controllers\Api\V1\StorefrontCustomerOtpController;
use Juwri\Modules\Storefront\Http\Controllers\Api\V1\StorefrontRetailController;
use Juwri\Modules\Storefront\Http\Controllers\Api\V1\StorefrontSlotsController;
use Juwri\Modules\Storefront\Http\Middleware\DeprecatedApiAlias;
use Juwri\Modules\Storefront\Http\Middleware\StorefrontServable;
use Juwri\Modules\Storefront\Http\Middleware\StorefrontTokenAuth;

// Public Storefront API (GAP-3), authenticated by a per-restaurant storefront token
// (X-Storefront-Token). Read-only scope; restaurant resolved from the token only.
//
// P3-06b phase A (kit/api openapi.json 1.7.0-draft): the headless storefront API for every store type — store,
// catalog, items, quote, orders (pay later + core-hosted payment), order status, customer OTP — beside the legacy
// endpoints, which stay frozen with Deprecation / Sunset / Link headers for 12 months after launch (API-4).
// Phase B (P3-08): variants + stock states, catalog filters / sort / cursor, shipping quote, shipping in quote and
// order. Phase C (P3-09): delivery / collection slots with capacity + express, 10-minute slot holds and their release.
// Phase D (P3-10): booking staff / days / slots, bookings (create, read, cancel, reschedule) on the booking engine;
// booking holds ride on POST/DELETE /holds. Kiosk (lane C, API-13/15/24d): `GET /kiosk` for `kiosk` device tokens, which
// also make their store's menu:read / order:write calls (bookings walk-in, orders tagged source=kiosk).
// The legacy reads: the alias headers sit outside the token gate so every answer of a deprecated endpoint (a 401 /
// 403 refusal too) carries Deprecation / Sunset / Link.
Route::prefix('api/v1/storefront')
    ->namespace('Api\V1')
    ->name('api.v1.storefront.')
    ->group(function () {
        Route::get('menu', [StorefrontController::class, 'menu'])
            ->middleware(['api', DeprecatedApiAlias::class.':/api/v1/storefront/catalog', StorefrontTokenAuth::class.':menu:read', 'throttle:storefront'])->name('menu');
        Route::get('restaurant', [StorefrontController::class, 'storeInfo'])
            ->middleware(['api', DeprecatedApiAlias::class.':/api/v1/storefront/store', StorefrontTokenAuth::class.':menu:read', 'throttle:storefront'])->name('restaurant');
    });

Route::prefix('api/v1/storefront')
    ->namespace('Api\V1')
    ->middleware(['api', StorefrontTokenAuth::class.':kiosk', 'throttle:storefront', StorefrontServable::class])
    ->name('api.v1.storefront.')
    ->group(function () {
        Route::get('kiosk', [StorefrontCatalogController::class, 'kiosk'])->name('kiosk');
    });

Route::prefix('api/v1/storefront')
    ->namespace('Api\V1')
    ->middleware(['api', StorefrontTokenAuth::class.':menu:read', 'throttle:storefront'])
    ->name('api.v1.storefront.')
    ->group(function () {
        Route::middleware(StorefrontServable::class)->group(function () {
            Route::get('store', [StorefrontCatalogController::class, 'store'])->name('store');
            Route::get('catalog', [StorefrontCatalogController::class, 'catalog'])->name('catalog');
            Route::get('items/{item_id}', [StorefrontCatalogController::class, 'item'])->name('items.show');
            // P3-08 / phase B: the variant matrix with live stock states (API-6).
            Route::get('items/{item_id}/variants', [StorefrontCatalogController::class, 'variants'])->name('items.variants');
            Route::get('orders/{track_token}', [StorefrontCheckoutController::class, 'show'])->name('orders.show');
            // P3-09 / phase C: hourly delivery / collection slots with live capacity and the express option.
            Route::get('delivery/slots', [StorefrontSlotsController::class, 'index'])->name('delivery.slots');
            // P3-08: juwri retail reads beside the kit contract (additive): wishlist, size guides, returns window.
            Route::get('wishlist', [StorefrontRetailController::class, 'wishlist'])->name('wishlist.index');
            Route::get('size-guides/{guide_id}', [StorefrontRetailController::class, 'sizeGuide'])->name('sizeGuides.show');
            Route::get('orders/{track_token}/returns', [StorefrontRetailController::class, 'returns'])->name('orders.returns.show');
            // P3-10 / phase D: the booking engine's staff, day strip and start times (`booking_api`), and a booking by its
            // signed manage token.
            Route::get('booking/staff', [StorefrontBookingController::class, 'staff'])->name('booking.staff');
            Route::get('booking/days', [StorefrontBookingController::class, 'days'])->name('booking.days');
            Route::get('booking/slots', [StorefrontBookingController::class, 'slots'])->name('booking.slots');
            Route::get('bookings/{manage_token}', [StorefrontBookingController::class, 'show'])->name('bookings.show');
        });
    });

// P3-08: juwri retail writes (shopper data: `order:write`, the write budget, the Origin allowlist for browser tokens).
Route::prefix('api/v1/storefront')
    ->middleware(['api', StorefrontTokenAuth::class.':order:write', 'throttle:storefront-write', StorefrontServable::class])
    ->name('api.v1.storefront.')
    ->group(function () {
        Route::post('wishlist', [StorefrontRetailController::class, 'wishlistAdd'])->name('wishlist.store');
        Route::post('wishlist/sync', [StorefrontRetailController::class, 'wishlistSync'])->name('wishlist.sync');
        Route::delete('wishlist/{item_id}', [StorefrontRetailController::class, 'wishlistRemove'])->name('wishlist.destroy');
        Route::post('stock-alerts', [StorefrontRetailController::class, 'stockAlert'])->name('stockAlerts.store');
        Route::post('orders/{track_token}/returns', [StorefrontRetailController::class, 'returnsStore'])->name('orders.returns.store');
        // P3-09 / phase C: 10-minute slot holds (API-3, API-11) and their release (API-24a).
        Route::post('holds', [StorefrontSlotsController::class, 'store'])->name('holds.store');
        Route::delete('holds/{hold_id}', [StorefrontSlotsController::class, 'destroy'])->name('holds.destroy');
        // P3-10 / phase D: book from a hold (deposit via the core-hosted page), cancel (D7/D8) and same-price reschedule.
        Route::post('bookings', [StorefrontBookingController::class, 'store'])->name('bookings.store');
        Route::post('bookings/{manage_token}/cancel', [StorefrontBookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('bookings/{manage_token}/reschedule', [StorefrontBookingController::class, 'reschedule'])->name('bookings.reschedule');
    });

// P3-06b: the quote is a read (`menu:read`) that prices — it spends the write budget (README §2.9).
Route::post('api/v1/storefront/quote', [StorefrontCheckoutController::class, 'quote'])
    ->middleware(['api', StorefrontTokenAuth::class.':menu:read', 'throttle:storefront-write', StorefrontServable::class])
    ->name('api.v1.storefront.quote');

// P3-08 / phase B: shipping methods, fees and dates for an address and cart (a read that prices: the write budget).
Route::post('api/v1/storefront/shipping/quote', [StorefrontCheckoutController::class, 'shippingQuote'])
    ->middleware(['api', StorefrontTokenAuth::class.':menu:read', 'throttle:storefront-write', StorefrontServable::class])
    ->name('api.v1.storefront.shipping.quote');

// P1-7 / P3-06b: storefront order-write (`order:write` scope; Origin allowlist for browser tokens). One path, two
// bodies: the new OrderSubmitRequest (`lines`) and the deprecated legacy body (`items`), see StorefrontCheckoutController.
Route::post('api/v1/storefront/orders', [StorefrontCheckoutController::class, 'store'])
    ->middleware(['api', StorefrontTokenAuth::class.':order:write', 'throttle:storefront-write', StorefrontServable::class])
    ->name('api.v1.storefront.orders.create');

// P3-06b (API-14, API-24c): customer phone OTP for headless clients, keyed per store. The per-IP limits of the
// customer-app OTP routes (6 / 10 per minute) plus the write budget.
Route::prefix('api/v1/storefront/customer/otp')
    ->middleware(['api', StorefrontTokenAuth::class.':order:write', 'throttle:storefront-write', StorefrontServable::class])
    ->name('api.v1.storefront.customer.otp.')
    ->group(function () {
        Route::post('request', [StorefrontCustomerOtpController::class, 'request'])->middleware(['throttle:5,1,otp-send', 'storecap:sms'])->name('request');
        Route::post('verify', [StorefrontCustomerOtpController::class, 'verify'])->middleware('throttle:10,1,otp-verify')->name('verify');
    });
