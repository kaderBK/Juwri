<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * features-run-4 · starter menu presets (C7, hotel-pms platform gap). Owned by lane C.
 * Auto-required by the glob in routes/web.php. Owner dashboard action (the controller
 * re-checks ownership); no ROUTE_PERMISSION_MAP entry, so it is owner-only by default.
 */

use Illuminate\Support\Facades\Route;
use Juwri\Modules\Menu\Http\Controllers\User\StarterMenuController;

Route::middleware(['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard'])
    ->group(function () {
        Route::post('stores/{store}/starter-menu', [StarterMenuController::class, 'seed'])
            ->name('menu.starter.seed');
    });
