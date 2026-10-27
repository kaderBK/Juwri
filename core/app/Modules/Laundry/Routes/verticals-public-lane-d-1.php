<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/public/lane-d.php (slot Laundry/verticals-public-lane-d-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Lane D public storefront routes go here.

// D2: the storefront service-booking flow (choose services by qty/weight,
// pickup slot, delivery address). Multi-segment ({slug}/book...) so none of
// these can ever be mistaken for a bare store {slug} by the catch-all below —
// same convention Lane B used for {slug}/goods/... and {slug}/checkout.
Route::get('{slug}/book', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryBookingController@show')->name('laundry.book.show');
Route::post('{slug}/book/{menu}/lines', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryBookingController@addLine')->name('laundry.book.addLine')->middleware('throttle:60,1,laundry-book-line');
Route::post('{slug}/book', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryBookingController@place')->name('laundry.book.place')->middleware('throttle:20,1,laundry-book-place');

// P3-11: the laundry booking on the `book` theme — one hold for the pickup and the delivery slot (API-23). Multi-segment
// ({slug}/book/...), registered with the plain booking routes before the {slug} catch-all.
Route::post('{slug}/book/lines', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryThemeController@lines')->middleware('throttle:30,1,laundry-book-lines')->name('laundry.book.lines');
Route::get('{slug}/book/schedule', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryThemeController@schedule')->name('laundry.book.schedule');
Route::post('{slug}/book/hold', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryThemeController@hold')->middleware('throttle:20,1,laundry-book-hold')->name('laundry.book.hold');
Route::post('{slug}/book/hold/{hold}/release', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryThemeController@release')->middleware('throttle:20,1,laundry-book-hold-release')->name('laundry.book.release');
Route::get('{slug}/book/confirm/{hold}', '\Juwri\Modules\Laundry\Http\Controllers\User\LaundryThemeController@confirm')->name('laundry.book.confirm');
