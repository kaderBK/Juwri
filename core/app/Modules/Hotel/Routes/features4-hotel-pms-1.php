<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features4/hotel-pms.php (slot Hotel/features4-hotel-pms-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Hotel\Http\Controllers\User\HotelBookingController;
use Juwri\Modules\Hotel\Http\Controllers\User\HotelPmsSetupController;

Route::middleware(['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard', 'entitled:hotel'])
    ->prefix('dashboard/hotel/pms')
    ->name('hotel.pms.')
    ->group(function () {
        // Availability + calendar (read).
        Route::get('availability', [HotelBookingController::class, 'availability'])->name('availability');
        Route::get('calendar', [HotelBookingController::class, 'calendar'])->name('calendar');

        // Bookings list + lifecycle.
        Route::get('bookings', [HotelBookingController::class, 'index'])->name('bookings');
        Route::post('bookings', [HotelBookingController::class, 'store'])->name('bookings.store');
        Route::get('bookings/{booking}', [HotelBookingController::class, 'show'])->whereNumber('booking')->name('bookings.show');
        Route::post('bookings/{booking}/update', [HotelBookingController::class, 'update'])->whereNumber('booking')->name('bookings.update');
        Route::post('bookings/{booking}/confirm', [HotelBookingController::class, 'confirm'])->whereNumber('booking')->name('bookings.confirm');
        Route::post('bookings/{booking}/cancel', [HotelBookingController::class, 'cancel'])->whereNumber('booking')->name('bookings.cancel');
        Route::post('bookings/{booking}/no-show', [HotelBookingController::class, 'noShow'])->whereNumber('booking')->name('bookings.noshow');
        // A6 — check-in handoff (opens the stay + folio, posts nightly charges).
        Route::post('bookings/{booking}/check-in', [HotelBookingController::class, 'checkIn'])->whereNumber('booking')->name('bookings.checkin');

        // A7 — post an extra-service charge onto a stay's folio.
        Route::post('stays/{stay}/service', [HotelBookingController::class, 'postService'])->whereNumber('stay')->name('services.post');

        // A8 — settle a standalone event's balance (money action → hotel_folio).
        Route::post('events/{event}/settle', [HotelBookingController::class, 'settleEvent'])->whereNumber('event')->name('events.settle');

        // A9 — housekeeping board + tasks (hotel_requests) and out-of-order marking (hotel_admin).
        Route::get('housekeeping', [HotelPmsSetupController::class, 'housekeeping'])->name('housekeeping');
        Route::post('housekeeping/task', [HotelPmsSetupController::class, 'housekeepingTask'])->name('housekeeping.task');
        Route::post('rooms/{room}/oo', [HotelPmsSetupController::class, 'roomOo'])->whereNumber('room')->name('rooms.oo');

        // A11 — inventory / rate / service / event configuration (all hotel_admin per
        // the perm map). Read screens render lane-B blades when present, else JSON.
        // Room types.
        Route::get('room-types', [HotelPmsSetupController::class, 'types'])->name('types');
        Route::post('room-types', [HotelPmsSetupController::class, 'typesStore'])->name('types.store');
        Route::post('room-types/{type}/update', [HotelPmsSetupController::class, 'typesUpdate'])->whereNumber('type')->name('types.update');
        Route::post('room-types/{type}/delete', [HotelPmsSetupController::class, 'typesDelete'])->whereNumber('type')->name('types.delete');

        // Rate plans.
        Route::get('rate-plans', [HotelPmsSetupController::class, 'plans'])->name('plans');
        Route::post('rate-plans', [HotelPmsSetupController::class, 'plansStore'])->name('plans.store');
        Route::post('rate-plans/{plan}/update', [HotelPmsSetupController::class, 'plansUpdate'])->whereNumber('plan')->name('plans.update');
        Route::post('rate-plans/{plan}/delete', [HotelPmsSetupController::class, 'plansDelete'])->whereNumber('plan')->name('plans.delete');

        // Per-date rates: read the matrix, bulk-upsert a date→price map.
        Route::get('rates', [HotelPmsSetupController::class, 'rates'])->name('rates');
        Route::post('rates', [HotelPmsSetupController::class, 'ratesBulk'])->name('rates.bulk');

        // Extra services (breakfast / parking / spa …).
        Route::get('services', [HotelPmsSetupController::class, 'services'])->name('services');
        Route::post('services', [HotelPmsSetupController::class, 'servicesStore'])->name('services.store');
        Route::post('services/{service}/update', [HotelPmsSetupController::class, 'servicesUpdate'])->whereNumber('service')->name('services.update');
        Route::post('services/{service}/delete', [HotelPmsSetupController::class, 'servicesDelete'])->whereNumber('service')->name('services.delete');

        // Events (banquets / venue hire) + their line items.
        Route::get('events', [HotelPmsSetupController::class, 'events'])->name('events');
        Route::get('events/{event}', [HotelPmsSetupController::class, 'eventShow'])->whereNumber('event')->name('events.show');
        Route::post('events', [HotelPmsSetupController::class, 'eventsStore'])->name('events.store');
        Route::post('events/{event}/update', [HotelPmsSetupController::class, 'eventsUpdate'])->whereNumber('event')->name('events.update');
        Route::post('events/{event}/delete', [HotelPmsSetupController::class, 'eventsDelete'])->whereNumber('event')->name('events.delete');
        Route::post('events/{event}/items', [HotelPmsSetupController::class, 'eventItemAdd'])->whereNumber('event')->name('events.items.add');
        Route::post('events/{event}/items/{item}/remove', [HotelPmsSetupController::class, 'eventItemRemove'])->whereNumber('event')->whereNumber('item')->name('events.items.remove');

        // Assign / clear a room's room type (extends the rooms flow; UI in lane B).
        Route::post('rooms/{room}/type', [HotelPmsSetupController::class, 'roomType'])->whereNumber('room')->name('rooms.type');
    });
