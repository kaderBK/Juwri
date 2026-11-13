<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-13 13c: moved from routes/features4/hotel-storefront.php (slot Hotel/features4-hotel-storefront-1).
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Hotel\Http\Controllers\User\StorefrontHotelController;

Route::get('{slug}/hotel/rooms/availability', [StorefrontHotelController::class, 'availability'])
    ->middleware('throttle:public-check')
    ->name('hotel.rooms.availability');

Route::post('{slug}/hotel/rooms/book', [StorefrontHotelController::class, 'book'])
    ->middleware('throttle:public-check')
    ->name('hotel.rooms.book');
