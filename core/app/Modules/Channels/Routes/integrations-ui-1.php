<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/integrations/ui.php (slot Channels/integrations-ui-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// D2: owner Deliverect panel. Standalone per-provider gate
// `feature:aggregator_deliverect` (NOT `aggregator_hub`); lives under the same
// /integrations prefix so the hub card links straight to it. All mutations
// re-resolve ownership in-controller, so the only gate the routes need is the
// feature key.
Route::middleware([
    'web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard',
    'reseller.suspension', 'abuse.hold', 'demo', 'feature:aggregator_deliverect',
])
    ->controller('\Juwri\Modules\Channels\Http\Controllers\User\DeliverectPanelController')
    ->name('integrations.deliverect.')
    ->prefix('integrations/deliverect')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('sku', 'setSku')->name('sku');
        Route::post('busy', 'busy')->name('busy');
        Route::post('unsnooze', 'unsnooze')->name('unsnooze');
        Route::post('{id}/sync', 'sync')->name('sync')->whereNumber('id');
    });
