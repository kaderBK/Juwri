<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Messaging/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* PARITY-RUN T9 — mobile app & push. The SETTINGS routes are
               dashboard-only, so `feature:` middleware is legal on them. The
               device-registration endpoint below deliberately sits OUTSIDE this
               group: the app calls it on every cold start, and middleware on a
               device transport 403s every install. It checks
               MobilePushService::enabled() in the controller instead. */
Route::controller('\Juwri\Modules\Messaging\Http\Controllers\User\MobilePushController')->name('stores.mobilepush.')->prefix('mobile-app')->middleware('feature:mobile_app_push')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/settings', 'saveSettings')->name('settings');
    Route::post('/credentials', 'saveCredentials')->name('credentials');
    Route::delete('/credentials', 'clearCredentials')->name('credentials.clear');
    Route::post('/test', 'test')->name('test');
    Route::delete('/devices/{id}', 'revokeDevice')->name('devices.revoke')->whereNumber('id');
});
Route::post('mobile-app/devices/register', '\Juwri\Modules\Messaging\Http\Controllers\User\MobilePushController@registerDevice')
    ->middleware('throttle:30,1,messaging-mobile-app-devices-register')->name('stores.mobilepush.register');
