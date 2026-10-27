<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Pos/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// B9 kiosk — owner config (superadmin-gated).
Route::controller('\Juwri\Modules\Pos\Http\Controllers\User\KioskAdminController')->name('stores.kiosk.')->prefix('kiosk-settings')->middleware('feature:kiosk')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'update')->name('update');
});

/* PARITY-RUN T1 — printers & routing (superadmin-gated). Dashboard-only,
   so `feature:` middleware is legal here; every customer/staff-facing
   print surface checks FeatureAccess::storeAllows() in its controller. */
Route::controller('\Juwri\Modules\Pos\Http\Controllers\User\PrintingController')->name('stores.printing.')->prefix('printing')->middleware('feature:print_routing')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'store')->name('store');
    Route::post('/options', 'options')->name('options');
    Route::post('/pairing-token', 'rotateToken')->name('token');
    Route::post('/{id}', 'update')->name('update');
    Route::post('/{id}/test', 'test')->name('test');
    Route::delete('/{id}', 'destroy')->name('destroy');
});
