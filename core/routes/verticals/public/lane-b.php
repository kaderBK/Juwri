<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * rev/multi-vertical F0-7 — Lane B PUBLIC storefront routes.
 *
 * This file is require()d inside the storefront-localized group in routes/web.php,
 * registered BEFORE the {slug} catch-all so its routes are not shadowed. Use the
 * FULL controller namespace here (e.g. '\Juwri\Modules\Verticals\Http\Controllers\User\FitmentController@filter'), matching
 * the sibling 'User\StoreController@publicView' route.
 *
 * Add ONLY Lane B's public routes here. Keep every route specific enough that it
 * cannot collide with a store {slug}.
 */

use Illuminate\Support\Facades\Route;
use Juwri\Core\Support\ModuleRoutes;

ModuleRoutes::slot('Verticals/verticals-public-lane-b-1');

// B4: shipment status, anonymous + track-token-scoped — the SAME auth model
// as the existing 'order/track/{token}' page (no {slug} needed: the token
// alone is the capability). Named distinctly so it never collides with that
// route (owned outside this lane's files).
Route::get('order/track/{token}/shipment', '\Juwri\Modules\Delivery\Http\Controllers\User\ShipmentController@publicStatus')->name('storefront.shipment.track');
