<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Channels/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// HungerStation P5: owner settings (connect BYOK, map branch->vendor, event log).
// MONETIZATION C3: gated behind feature:aggregator_hub — the same key
// delivery-hub grants (matching A7's shape). BYOK stays intact. Dark
// until V0.
Route::middleware('feature:aggregator_hub')->group(function () {
    Route::get('hungerstation', '\Juwri\Modules\Channels\Http\Controllers\User\HungerStationController@page')->name('hungerstation.page');
    Route::post('hungerstation/{storeId}/connect', '\Juwri\Modules\Channels\Http\Controllers\User\HungerStationController@connect')->name('hungerstation.connect');
    Route::post('hungerstation/{storeId}/map-branch', '\Juwri\Modules\Channels\Http\Controllers\User\HungerStationController@mapBranch')->name('hungerstation.mapBranch');
    Route::get('hungerstation/{storeId}/events', '\Juwri\Modules\Channels\Http\Controllers\User\HungerStationController@events')->name('hungerstation.events');
}); // end feature:aggregator_hub group
