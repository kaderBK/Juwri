<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/apiv1.php (slot Delivery/apiv1-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* Driver-app API, authenticated by a per-driver token (DriverTokenAuth).
   FIX-6 F6-6: `feature:driver_app` runs AFTER DriverTokenAuth so the driver is
   resolved and the gate can ask about the driver's MERCHANT. Without it, FIX-1's
   gate on the driver-ADMIN routes (`api/v1/drivers*`) was half a door: an
   unentitled owner could no longer mint or manage drivers, but every token
   already in a driver's phone kept working forever. */
Route::prefix('api/v1/driver')
    ->namespace('Api\V1')
    ->middleware(['api', 'Juwri\Modules\Delivery\Http\Middleware\DriverTokenAuth', 'feature:driver_app', 'throttle:120,1,delivery-apiv1-1'])
    ->name('api.v1.driver.')
    ->group(function () {
        Route::get('me', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@me')->name('me');
        Route::get('deliveries', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@deliveries')->name('deliveries');
        Route::get('available', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@available')->name('available');
        Route::post('deliveries/{orderId}/claim', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@claim')->name('deliveries.claim');
        Route::put('deliveries/{orderId}/status', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@updateStatus')->name('deliveries.status');
        Route::post('deliveries/{orderId}/pod-otp', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@podOtp')->name('deliveries.podOtp');
        Route::post('deliveries/{orderId}/pod-photo', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@podPhoto')->name('deliveries.podPhoto');
        Route::post('logout', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@logout')->name('logout'); // P6-237
        Route::put('location', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@updateLocation')->name('location');

        /* L4 T4-3 driver app: fleet dispatch offers + shift + earnings.
           Accept/reject delegate to DispatchOrchestrator — never a direct assign. */
        Route::get('offers', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@offers')->name('offers');
        Route::post('offers/{offerId}/accept', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@acceptOffer')->name('offers.accept');
        Route::post('offers/{offerId}/reject', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@rejectOffer')->name('offers.reject');
        Route::post('shift', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@shift')->name('shift');
        // HPMS E11: native push device registration for Bearer (driver token) clients.
        Route::post('push/register-device', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@registerDevice')->name('push.register');
        Route::post('push/unregister-device', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@unregisterDevice')->name('push.unregister');
        Route::get('earnings', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAppController@earnings')->name('earnings');
    });

/* L4 T4-3 driver app: OTP login (public — exchanges phone + OTP for a rotated
   driver token; DriverTokenAuth guards everything else). Tightly throttled. */
Route::prefix('api/v1/driver/login')
    ->namespace('Api\V1')
    ->middleware(['api', 'throttle:12,1,delivery-apiv1-1-2'])
    ->name('api.v1.driver.login.')
    ->group(function () {
        Route::post('request', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAuthController@requestOtp')->name('request');
        Route::post('verify', '\Juwri\Modules\Delivery\Http\Controllers\Api\V1\DriverAuthController@verifyOtp')->name('verify');
    });
