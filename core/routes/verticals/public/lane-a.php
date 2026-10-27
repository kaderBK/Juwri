<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * rev/multi-vertical F0-7 — Lane A PUBLIC storefront routes.
 *
 * This file is require()d inside the storefront-localized group in routes/web.php,
 * registered BEFORE the {slug} catch-all so its routes are not shadowed. Use the
 * FULL controller namespace here (e.g. '\Juwri\Modules\Verticals\Http\Controllers\User\FitmentController@filter'), matching
 * the sibling 'User\StoreController@publicView' route.
 *
 * Add ONLY Lane A's public routes here. Keep every route specific enough that it
 * cannot collide with a store {slug}.
 */

use Illuminate\Support\Facades\Route;

// Lane A public storefront routes go here.
