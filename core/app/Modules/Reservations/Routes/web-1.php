<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Reservations/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #81: Waitlist (per-restaurant)
// MONETIZATION B3: gated behind feature:waitlist (dark until V0).
Route::prefix('restaurant/{storeId}/waitlist')->name('waitlist.')->middleware('feature:waitlist')->group(function () {
    Route::get('/', '\Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController@index')->name('index');
    Route::post('/add', '\Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController@add')->name('add');
    Route::post('/{entry}/notify', '\Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController@notify')->name('notify');
    Route::post('/{entry}/seat', '\Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController@seat')->name('seat');
    Route::post('/{entry}/leave', '\Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController@leave')->name('leave');
    // google-partner D6: per-store Reserve-with-Google waitlist settings.
    Route::post('/rwg-settings', '\Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController@saveRwgSettings')->name('rwg-settings');
});
