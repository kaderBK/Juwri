<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * torque (car-parts theme) PUBLIC storefront routes.
 *
 * Auto-loaded by the glob('routes/verticals/public/lane-*.php') in routes/web.php,
 * inside the storefront-localized group and BEFORE the {slug} catch-all. Use the
 * FULL controller namespace, and keep paths multi-segment so they can never be
 * mistaken for a bare store {slug}.
 *
 * The "Scan your VIN" flow in store_templates/torque/index.blade.php POSTs here
 * (route name 'vin.decode'); the decode is delegated to the bound VinDecodeProvider
 * (mock today — see App\Services\Vin). Non-carparts stores get enabled:false.
 */

use Illuminate\Support\Facades\Route;

Route::post('{slug}/vin/decode', '\Juwri\Modules\Verticals\Http\Controllers\User\VinDecodeController@decode')
    ->middleware('throttle:public-check')
    ->name('vin.decode');
