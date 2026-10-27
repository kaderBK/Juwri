<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features4/hotel.php (slot Hotel/features4-hotel-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\AddCspHeaders;
use Juwri\Modules\Hotel\Http\Controllers\HotelGuestController;
use Juwri\Modules\Hotel\Http\Controllers\User\HotelInfoController;
use Juwri\Modules\Hotel\Http\Controllers\User\HotelRoomController;
use Juwri\Modules\Hotel\Http\Controllers\User\HotelServiceRequestController;

/* -------------------------------------------------------------------------
 | Owner dashboard — Hotel module (H3 rooms, H5 service requests, H9 info).
 | Mirrors the User route group's middleware (this file is required outside it).
 | Each controller re-checks HotelService::enabled() so the whole module 404s
 | when hotel_mode is off (H11 gating).
 * ---------------------------------------------------------------------- */
/* P2-3 (feature-platform): entitled:hotel is the plan-aware gate — GET requests
   without the entitlement land on the upsell page instead of the controllers'
   404s. Controllers keep their HotelService re-checks (defense in depth). */
Route::middleware(['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard', 'entitled:hotel'])
    ->prefix('hotel')
    ->name('hotel.')
    ->group(function () {
        // H3 — Rooms & areas admin
        Route::get('rooms', [HotelRoomController::class, 'index'])->name('rooms');
        Route::post('rooms', [HotelRoomController::class, 'store'])->name('rooms.store');
        Route::post('rooms/bulk', [HotelRoomController::class, 'bulkStore'])->name('rooms.bulk');
        Route::post('rooms/delete', [HotelRoomController::class, 'destroy'])->name('rooms.delete');
        Route::get('rooms/print', [HotelRoomController::class, 'printSheet'])->name('rooms.print');

        // H5 — Service requests staff board
        Route::get('requests', [HotelServiceRequestController::class, 'board'])->name('requests');
        Route::post('requests', [HotelServiceRequestController::class, 'store'])->name('requests.store');
        Route::post('requests/status', [HotelServiceRequestController::class, 'updateStatus'])->name('requests.status');

        // H9 — Guest info hub owner editor + hotel settings
        Route::get('info', [HotelInfoController::class, 'edit'])->name('info');
        Route::post('info', [HotelInfoController::class, 'save'])->name('info.save');
        Route::post('mode', [HotelInfoController::class, 'toggleMode'])->name('mode');
    });

/* -------------------------------------------------------------------------
 | Public guest endpoints (from the room QR). Localised like the public menu.
 * ---------------------------------------------------------------------- */
// P6-122b: the guest pages carry the enforced storefront policy.
Route::middleware(['web', 'app.locale', AddCspHeaders::class])->group(function () {
    Route::get('hotel/{slug}/info', [HotelGuestController::class, 'info'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('hotel.guest.info');
    Route::post('hotel/{slug}/request', [HotelGuestController::class, 'requestStore'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->middleware('throttle:public-form')
        ->name('hotel.guest.request');

    /* C3 — guest-facing "my bill" folio, reached by the per-stay token minted at
       check-in (the previously dead HotelStay.token column). Read-only, no auth:
       possession of the 48-char token is the capability. Gated INSIDE the controller
       via HotelService::enabled (no entitled: middleware on an anon route) and
       throttled. Valid only while the stay is active or ≤7 days after check-out. */
    Route::get('hotel/{slug}/folio/{token}', [HotelGuestController::class, 'folio'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:30,1,hotel-hotel-folio')
        ->name('hotel.guest.folio');

    /* C1/C2 — public room booking. Anon like the guest info/folio surfaces: NO
       feature/entitled middleware (an EnsureEntitled on an anon route resolves no
       owner). The controller gates INSIDE — HotelService::enabled() AND the owner's
       `hotel_online_booking` option (off|request|instant, default off) — so a store
       that never opted in 404s. Throttled per the booking spec (30/min browse,
       10/min the money-adjacent submit + cancel). */
    Route::get('hotel/{slug}/book', [HotelGuestController::class, 'book'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->middleware('throttle:30,1,hotel-hotel-book')
        ->name('hotel.guest.book');
    Route::post('hotel/{slug}/book', [HotelGuestController::class, 'bookSubmit'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->middleware('throttle:10,1,hotel-hotel-book-2')
        ->name('hotel.guest.book.submit');

    /* C2 — booking manage page (status + cancel), reached by the 64-char token minted
       at create. Possession of the token is the capability; no login. noindex. */
    Route::get('hotel/{slug}/booking/{token}', [HotelGuestController::class, 'booking'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:30,1,hotel-hotel-booking')
        ->name('hotel.guest.booking');
    Route::post('hotel/{slug}/booking/{token}/cancel', [HotelGuestController::class, 'bookingCancel'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:10,1,hotel-hotel-booking-cancel')
        ->name('hotel.guest.booking.cancel');
});
