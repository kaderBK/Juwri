<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Tables/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// #82: Floor map (per-restaurant)
// MONETIZATION B3: gated behind feature:floor_map (dark until V0).
// The table_merge sub-group inside keeps its own gate — those routes
// require BOTH floor_map and table_merge, which is correct.
Route::prefix('restaurant/{storeId}/floor-map')->name('floor-map.')->middleware('feature:floor_map')->group(function () {
    Route::get('/', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@index')->name('index');
    Route::post('/layout', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@saveLayout')->name('save');
    Route::get('/status', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@status')->name('status');
    Route::get('/turn-times', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@turnTimes')->name('turnTimes'); // FP9 #48/#49
    Route::get('/all-locations', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@allLocations')->name('all');
    Route::post('/tables/{tableId}/status', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@setStatus')->name('setStatus');
    Route::post('/tables', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@addTable')->name('addTable');
    Route::delete('/tables/{tableId}', '\Juwri\Modules\Tables\Http\Controllers\User\FloorMapController@deleteTable')->name('deleteTable');
    /* PARITY-RUN T4: merge two or more tables onto one bill. Owner
       dashboard only (never reached by a guest), so `feature:` is
       safe here; the register's twin gates in-controller instead. */
    Route::middleware('feature:table_merge')->group(function () {
        Route::get('/merges', '\Juwri\Modules\Tables\Http\Controllers\User\TableMergeController@index')->name('merges');
        Route::post('/merges', '\Juwri\Modules\Tables\Http\Controllers\User\TableMergeController@store')->name('merge');
        Route::delete('/merges/{groupId}', '\Juwri\Modules\Tables\Http\Controllers\User\TableMergeController@destroy')->name('unmerge');
    });
});
