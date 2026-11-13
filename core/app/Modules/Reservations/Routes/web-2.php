<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Reservations/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #80: Reservations
            // MONETIZATION B3: gated behind feature:reservations (dark until V0).
            // V0 verifies bookings with deposits degrade read-only, not vanish.
            Route::middleware('feature:reservations')->group(function () {
                Route::get('reservations', '\Juwri\Modules\Reservations\Http\Controllers\User\ReservationController@index')->name('reservations.index');
                Route::post('reservations', '\Juwri\Modules\Reservations\Http\Controllers\User\ReservationController@store')->name('reservations.store');
                Route::post('reservations/{id}/status', '\Juwri\Modules\Reservations\Http\Controllers\User\ReservationController@updateStatus')->name('reservations.status');
                // BL-016: owner confirms a refund_due deposit was refunded in the provider dashboard.
                Route::post('reservations/{id}/deposit/manual-refund', '\Juwri\Modules\Reservations\Http\Controllers\User\ReservationController@confirmManualDepositRefund')->whereNumber('id')->name('reservations.deposit.manual-refund');
            }); // end feature:reservations group
