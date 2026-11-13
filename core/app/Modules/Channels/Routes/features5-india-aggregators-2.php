<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-13 13c: moved from routes/features5/india-aggregators.php (slot Channels/features5-india-aggregators-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Channels\Http\Controllers\User\IndiaAggregatorController;

/* -------------------------------------------------------------------------
 | Owner dashboard — Setup → Integrations → Zomato / Swiggy.
 | Mirrors the User route group's middleware (this file is required outside it).
 | `provider` is a route DEFAULT, not a parameter: a request can never address a
 | channel whose feature gate it did not pass.
 * ---------------------------------------------------------------------- */
foreach (['zomato' => 'aggregator_zomato', 'swiggy' => 'aggregator_swiggy'] as $indiaProvider => $indiaFeature) {
    // WV.V: + `app.locale` — see the note in routes/features5/menu-schedules.php.
    // Without it these owner pages rendered English LTR inside an Arabic RTL dashboard.
    Route::middleware(['web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard', 'feature:'.$indiaFeature])
        ->prefix('stores/{store}/channels/'.$indiaProvider)
        ->name('stores.'.$indiaProvider.'.')
        ->group(function () use ($indiaProvider) {
            // `defaults()` lives on the Route, not on the RouteRegistrar, so it is
            // applied per route rather than to the group.
            $endpoints = [
                ['get',    '/',            'index',            'index'],
                ['post',   'connect',      'connect',          'connect'],
                ['post',   'test',         'test',             'test'],
                ['post',   'menu-push',    'menuPush',         'menuPush'],
                ['post',   'availability', 'syncAvailability', 'availability'],
                ['post',   'outlet',       'toggleOutlet',     'outlet'],
                ['post',   'pause',        'pause',            'pause'],
                ['delete', '/',            'disconnect',       'disconnect'],
            ];

            foreach ($endpoints as [$verb, $uri, $action, $name]) {
                Route::{$verb}($uri, [IndiaAggregatorController::class, $action])
                    ->name($name)
                    ->defaults('provider', $indiaProvider);
            }
        });
}
