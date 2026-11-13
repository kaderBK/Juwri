<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * rev/multi-vertical F0-7 — Lane E PUBLIC storefront routes.
 *
 * This file is require()d inside the storefront-localized group in routes/web.php,
 * registered BEFORE the {slug} catch-all so its routes are not shadowed. Use the
 * FULL controller namespace here (e.g. '\Juwri\Modules\Verticals\Http\Controllers\User\FitmentController@filter'), matching
 * the sibling 'User\StoreController@publicView' route.
 *
 * Add ONLY Lane E's public routes here. Keep every route specific enough that it
 * cannot collide with a store {slug}.
 */

use Illuminate\Support\Facades\Route;

// Lane E public storefront routes go here.

// E2/E5: multi-segment ({slug}/parts/...) so these can never be mistaken for a
// bare store {slug} by the catch-all below. Consumed by the fitment-filter
// block this lane adds to store_templates/shelf/index.blade.php.
Route::get('{slug}/parts/fitment-options', '\Juwri\Modules\Verticals\Http\Controllers\User\FitmentPublicController@options')->name('storefront.fitment.options');
Route::get('{slug}/parts/oem-search', '\Juwri\Modules\Verticals\Http\Controllers\User\FitmentPublicController@oemSearch')->name('storefront.fitment.oemSearch');
