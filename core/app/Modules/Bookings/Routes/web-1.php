<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P3-10: owner screens of the booking engine (slot Bookings/web-1, included in the dashboard group by
// routes/verticals/lane-bookings.php). Store scoping and id-within-store checks live in the controller.
use Illuminate\Support\Facades\Route;

Route::prefix('appointments')->name('bookings.owner.')->controller('\Juwri\Modules\Bookings\Http\Controllers\User\BookingsOwnerController')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('{booking}/action', 'action')->whereNumber('booking')->name('action');
    Route::get('staff', 'staff')->name('staff');
    Route::post('staff', 'saveStaff')->name('staff.store');
    Route::post('staff/{staff}', 'saveStaff')->whereNumber('staff')->name('staff.update');
    Route::post('staff/{staff}/delete', 'deleteStaff')->whereNumber('staff')->name('staff.delete');
    Route::get('services', 'services')->name('services');
    Route::post('services', 'saveService')->name('services.store');
    Route::post('services/{service}', 'saveService')->whereNumber('service')->name('services.update');
    Route::post('services/{service}/delete', 'deleteService')->whereNumber('service')->name('services.delete');
    Route::get('hours', 'hours')->name('hours');
    Route::post('hours', 'saveHours')->name('hours.save');
    Route::post('closed-days', 'addClosedDay')->name('closed.store');
    Route::post('closed-days/{day}/delete', 'deleteClosedDay')->whereNumber('day')->name('closed.delete');
    Route::get('settings', 'settings')->name('settings');
    Route::post('settings', 'saveSettings')->name('settings.save');
});
