<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Kitchen/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #73: KDS — Kitchen Display System
            // MONETIZATION B2: the whole KDS module (screens + stations) is gated
            // behind feature:kds. Lands DARK — the middleware is a kill-switch
            // only, so the kitchen poll keeps working until V0. At the flip, V0
            // makes the screen degrade to an upsell rather than a dead poll loop.
            Route::middleware('feature:kds')->group(function () {
                Route::get('kds', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@index')->name('kds.index');
                Route::get('kds/poll', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@poll')->middleware('ratelimit:dashboard_poll')->name('kds.poll');
                Route::get('kds/all-day', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@allDay')->name('kds.allDay'); // SYSTEMS-RUN K-P1
                // T1: scheduled (pre-)orders panel + manual release.
                Route::get('kds/scheduled', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@scheduledUpcoming')->middleware('ratelimit:dashboard_poll')->name('kds.scheduled');
                Route::post('kds/{order}/release', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@releaseScheduled')->name('kds.release');
                Route::post('kds/mode', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@setMode')->name('kds.setMode');
                Route::post('kds/item/{item}/bump', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@bumpItem')->name('kds.item.bump');
                Route::post('kds/item/{item}/recall', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@recallItem')->name('kds.item.recall');
                Route::get('kds/analytics', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@analytics')->name('kds.analytics');
                Route::post('kds/{order}/fire/{course}', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@fireCourse')->name('kds.fireCourse');
                Route::post('kds/{order}/bump', '\Juwri\Modules\Kitchen\Http\Controllers\User\KdsController@bump')->name('kds.bump');
                // #77: Kitchen stations / multi-kitchen routing
                Route::get('kds/stations', '\Juwri\Modules\Kitchen\Http\Controllers\User\StationController@index')->name('stations.index');
                Route::post('kds/stations', '\Juwri\Modules\Kitchen\Http\Controllers\User\StationController@store')->name('stations.store');
                Route::put('kds/stations/{station}', '\Juwri\Modules\Kitchen\Http\Controllers\User\StationController@update')->name('stations.update');
                Route::delete('kds/stations/{station}', '\Juwri\Modules\Kitchen\Http\Controllers\User\StationController@destroy')->name('stations.destroy');
                Route::post('kds/stations/assign', '\Juwri\Modules\Kitchen\Http\Controllers\User\StationController@assign')->name('stations.assign');
            }); // end feature:kds group
