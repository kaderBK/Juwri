<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Reservations/web-3); included in place by ModuleRoutes::slot().
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Reservations\Http\Middleware\RwgAuth;

/* ===== GOOGLE-PARTNER Reserve with Google booking server (Google → us) =====
       REST over HTTPS, HTTP Basic (RwgAuth) + ratelimit:webhook-in. CSRF-exempt for
       THIS group only via ->withoutMiddleware(PreventRequestForgery) — we never edit the
       shared PreventRequestForgery::$except (SPEC §9 fence). RwgAuth referenced by FQCN.
       F0 WIRING: Lane D's four waitlist methods are folded into this same group so
       they inherit RwgAuth (defense-in-depth over the controller's in-Basic check);
       their route names keep the `waitlist.` segment (rwg.waitlist.*). */
    Route::prefix('rwg/v3')->name('rwg.')
        ->middleware([RwgAuth::class, 'ratelimit:webhook-in'])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->group(function () {
            Route::get('HealthCheck', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\HealthCheckController')->name('healthcheck');
            Route::post('BatchAvailabilityLookup', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\BookingServerController@batchAvailabilityLookup')->name('availability');
            Route::post('CreateBooking', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\BookingServerController@createBooking')->name('createBooking');
            Route::post('UpdateBooking', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\BookingServerController@updateBooking')->name('updateBooking');
            Route::post('GetBookingStatus', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\BookingServerController@getBookingStatus')->name('getBookingStatus');
            Route::post('ListBookings', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\BookingServerController@listBookings')->name('listBookings');
            // Lane D waitlist methods (folded into Lane C's group at F0) — inherit RwgAuth; names keep `waitlist.`
            Route::post('BatchGetWaitEstimates', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\RwgWaitlistController@batchGetWaitEstimates')->name('waitlist.batchEstimates');
            Route::post('CreateWaitlistEntry', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\RwgWaitlistController@createWaitlistEntry')->name('waitlist.create');
            Route::post('GetWaitlistEntry', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\RwgWaitlistController@getWaitlistEntry')->name('waitlist.get');
            Route::post('DeleteWaitlistEntry', '\Juwri\Modules\Reservations\Http\Controllers\Rwg\RwgWaitlistController@deleteWaitlistEntry')->name('waitlist.delete');
        });
