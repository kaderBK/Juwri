<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * lumiere (cosmetic theme) PUBLIC storefront routes — skin scan + routine
 * persistence. Auto-loaded by glob('routes/verticals/public/lane-*.php') before
 * the {slug} catch-all. Multi-segment paths so they can't shadow a store {slug}.
 * Backed by the bound SkinScanProvider (mock today — see App\Services\Skin).
 */

use Illuminate\Support\Facades\Route;

Route::post('{slug}/skin/scan', '\Juwri\Modules\Verticals\Http\Controllers\User\SkinController@scan')
    ->middleware(['throttle:public-check', 'storecap:ai'])
    ->name('skin.scan');

Route::post('{slug}/skin/profile', '\Juwri\Modules\Verticals\Http\Controllers\User\SkinController@profile')
    ->middleware('throttle:public-check')
    ->name('skin.profile');
