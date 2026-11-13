<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * theme-pack-blade · geo feature routes (F4 nearest-branch + F5 geocode).
 * Owned exclusively by its feature task — parallel agents must not edit
 * any other file under routes/. Registered by the glob in routes/web.php.
 *
 * All provider HTTP is server-side: these proxy endpoints keep API keys and the
 * descriptive User-Agent off the browser.
 */

use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Controllers\User\GeoController;

// F5 · geocode proxy (address autocomplete + reverse "use my location").
Route::get('geo/autocomplete', [GeoController::class, 'autocomplete'])
    ->middleware('throttle:60,1,core-geo-autocomplete')
    ->name('geo.autocomplete');

Route::get('geo/reverse', [GeoController::class, 'reverse'])
    ->middleware('throttle:60,1,core-geo-reverse')
    ->name('geo.reverse');

// F4 · nearest-branch feed for the customer branch-map picker.
Route::get('r/{store}/branches/geo', [GeoController::class, 'branches'])
    ->middleware('throttle:120,1,core-r-branches-geo')
    ->name('geo.branches');
