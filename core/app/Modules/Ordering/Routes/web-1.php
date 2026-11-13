<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Ordering/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B8 catering — owner request/quote/accept (superadmin-gated).
Route::controller('\Juwri\Modules\Ordering\Http\Controllers\User\CateringController')->name('stores.catering.')->prefix('catering')->middleware('feature:catering')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/{id}/quote', 'quote')->name('quote');
    Route::post('/{id}/accept', 'accept')->name('accept');
    Route::post('/{id}/decline', 'decline')->name('decline');
    // SYSTEMS-RUN G-P1: calendar + pipeline + lifecycle.
    Route::get('/calendar', 'calendar')->name('calendar');
    Route::post('/{id}/fulfill', 'fulfill')->name('fulfill');
    Route::post('/{id}/close', 'closeEvent')->name('close');
});
