<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Shared throttle prefixes on purpose (one counter across all routes using it): otp-send (every customer-OTP send, one limit of 5 a minute); otp-verify (every customer-OTP verify, one limit of 10 a minute); staff-auth (staff login, staff PIN, POS unlock, kiosk PIN: one limit of 10 a minute).

// P1-09: moved from routes/apiv1.php (slot Storefront/apiv1-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Storefront\Http\Middleware\DeprecatedApiAlias;

// Public customer endpoints (no auth). Order tracking is gated by the order's
// track_token; the auth endpoints issue a customer token (see below).
Route::prefix('api/v1')
    ->namespace('Api\V1')
    ->middleware(['api', 'throttle:120,1,storefront-apiv1-1'])
    ->name('api.v1.')
    ->group(function () {
        // P3-06b (API-4, API-12): deprecated aliases of GET /storefront/orders/{track_token} and GET /storefront/catalog —
        // frozen shapes, Deprecation/Sunset/Link headers, removed 12 months after launch.
        Route::get('track/{token}', '\Juwri\Modules\Storefront\Http\Controllers\Api\V1\PublicController@track')
            ->middleware(DeprecatedApiAlias::class.':/api/v1/storefront/orders/{token}')->name('track');
        Route::get('menu/{storeId}', '\Juwri\Modules\Storefront\Http\Controllers\Api\V1\PublicController@menu')
            ->middleware(DeprecatedApiAlias::class.':/api/v1/storefront/catalog')->whereNumber('storeId')->name('public.menu'); // P4-17: a malformed id is a 404, not a TypeError 500

        // Customer app auth — tighter per-route throttles to blunt brute force.
        Route::post('stores/{id}/customer/register', 'CustomerAuthController@register')->middleware('throttle:auth-register')->whereNumber('id')->name('customer.register');
        Route::post('stores/{id}/customer/login', 'CustomerAuthController@login')->middleware('throttle:auth-login')->whereNumber('id')->name('customer.login');
        Route::post('stores/{id}/customer/otp/request', 'CustomerAuthController@otpRequest')->middleware(['throttle:5,1,otp-send', 'storecap:sms'])->whereNumber('id')->name('customer.otp.request');
        Route::post('stores/{id}/customer/otp/verify', 'CustomerAuthController@otpVerify')->middleware('throttle:10,1,otp-verify')->whereNumber('id')->name('customer.otp.verify');

        // Staff app auth — email/password or quick PIN, throttled.
        Route::post('stores/{id}/staff/login', '\Juwri\Modules\Team\Http\Controllers\Api\V1\StaffAuthController@login')->middleware('throttle:10,1,staff-auth')->whereNumber('id')->name('staff.login');
        Route::post('stores/{id}/staff/pin', '\Juwri\Modules\Team\Http\Controllers\Api\V1\StaffAuthController@pin')->middleware('throttle:10,1,staff-auth')->whereNumber('id')->name('staff.pin');
    });
