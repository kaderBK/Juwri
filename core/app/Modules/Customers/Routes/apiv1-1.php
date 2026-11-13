<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/apiv1.php (slot Customers/apiv1-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Standalone customer app, authenticated by a per-customer token.
Route::prefix('api/v1/customer')
    ->namespace('Api\V1')
    ->middleware(['api', 'Juwri\Modules\Customers\Http\Middleware\CustomerTokenAuth', 'throttle:120,1,customers-apiv1-1'])
    ->name('api.v1.customer.')
    ->group(function () {
        Route::get('me', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@me')->name('me');
        Route::put('me', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@updateMe')->name('me.update');
        Route::get('orders', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@orders')->name('orders');
        Route::post('orders', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@placeOrder')->name('orders.place');
        Route::get('store-credit', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@storeCredit')->name('store-credit');
        Route::get('gift-cards', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@giftCards')->name('gift-cards');
        Route::get('addresses', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@addresses')->name('addresses');
        Route::post('addresses', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@storeAddress')->name('addresses.store');
        Route::put('addresses/{id}', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@updateAddress')->name('addresses.update');
        Route::delete('addresses/{id}', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@destroyAddress')->name('addresses.destroy');
        Route::post('logout', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerAppController@logout')->name('logout');
        // P6-91: data export + account deletion (password or phone code; exports 3 a day).
        Route::post('privacy/code', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerPrivacyController@code')->middleware(['throttle:5,1,otp-send', 'storecap:sms'])->name('privacy.code');
        Route::post('privacy/export', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerPrivacyController@export')->middleware('throttle:10,1,customer-privacy')->name('privacy.export');
        Route::post('privacy/delete', '\Juwri\Modules\Customers\Http\Controllers\Api\V1\CustomerPrivacyController@destroy')->middleware('throttle:10,1,customer-privacy')->name('privacy.delete');
    });
