<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Delivery/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Owner-facing BYOK courier credentials (Uber Direct / DoorDash).
// Registered BEFORE the dispatch group so /dispatch/couriers is never
// swallowed by the dispatch wildcards. Same gate as dispatch.index.
Route::controller('\Juwri\Modules\Delivery\Http\Controllers\User\CourierCredentialsController')->name('stores.dispatch.couriers')
    ->prefix('dispatch/couriers')->middleware('feature:courier_dispatch')->group(function () {
        Route::get('/', 'index');
        Route::post('/{provider}', 'save')->name('.save');
        Route::post('/{provider}/disconnect', 'disconnect')->name('.disconnect');
    });

// T4 courier dispatch — comparison + one-click dispatch.
Route::controller('\Juwri\Modules\Delivery\Http\Controllers\User\DispatchController')->name('stores.dispatch.')->prefix('dispatch')->group(function () {
    Route::get('/', 'index')->middleware('feature:courier_dispatch')->name('index');
    Route::post('/{id}', 'dispatchOrder')->name('order');
    // SYSTEMS-RUN E-P2: dispatcher live map + report (behind driver_app).
    Route::get('/map', 'map')->middleware('feature:driver_app')->name('map');
    Route::get('/map/feed', 'mapFeed')->middleware('feature:driver_app')->name('map.feed');
    Route::post('/{id}/assign', 'assign')->middleware('feature:driver_app')->name('assign');
    Route::get('/report', 'report')->middleware('feature:driver_app')->name('report');
});
