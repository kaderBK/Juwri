<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P3-10: the storefront booking flow (slot Bookings/public-1, included before the {slug} catch-all by
// routes/verticals/public/lane-bookings.php). Multi-segment paths ({slug}/appointments/...) never collide with a
// store slug; `{slug}/book` stays laundry's. Writes are throttled; manage pages need the signed manage token.
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\AddCspHeaders;

Route::prefix('{slug}/appointments')->name('bookings.')->middleware([AddCspHeaders::class])
    ->controller('\Juwri\Modules\Bookings\Http\Controllers\Storefront\BookingStorefrontController')->group(function () {
        Route::get('/', 'start')->name('start');
        Route::get('times', 'times')->name('times');
        Route::post('hold', 'hold')->middleware('throttle:20,1,bookings-hold')->name('hold');
        Route::get('details/{hold}', 'details')->name('details');
        Route::post('details/{hold}/release', 'release')->middleware('throttle:20,1,bookings-details-release')->name('release');
        Route::post('book', 'book')->middleware('throttle:10,1,bookings-book')->name('book');
        Route::get('m/{token}', 'manage')->name('manage');
        Route::get('m/{token}/calendar', 'calendar')->name('calendar');
        Route::get('m/{token}/pay', 'pay')->name('pay');
        Route::post('m/{token}/pay', 'charge')->middleware('throttle:10,1,bookings-m-pay')->name('charge');
        Route::post('m/{token}/cancel', 'cancel')->middleware('throttle:10,1,bookings-m-cancel')->name('cancel');
        Route::get('m/{token}/reschedule', 'rescheduleTimes')->name('rescheduleTimes');
        Route::post('m/{token}/reschedule', 'reschedule')->middleware('throttle:10,1,bookings-m-reschedule')->name('reschedule');
    });
