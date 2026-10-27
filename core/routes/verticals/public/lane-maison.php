<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * maison (clothing theme) PUBLIC storefront routes — AI Style Search + Visual
 * Search. Auto-loaded by glob('routes/verticals/public/lane-*.php') before the
 * {slug} catch-all. Multi-segment paths so they can't shadow a store {slug}.
 * Backed by the bound StylistProvider (mock today — see App\Services\Stylist).
 */

use Illuminate\Support\Facades\Route;

Route::post('{slug}/stylist/search', '\Juwri\Modules\Verticals\Http\Controllers\User\StylistController@search')
    ->middleware(['throttle:public-check', 'storecap:ai'])
    ->name('stylist.search');

Route::post('{slug}/visual/search', '\Juwri\Modules\Verticals\Http\Controllers\User\StylistController@visual')
    ->middleware(['throttle:public-check', 'storecap:ai'])
    ->name('visual.search');
