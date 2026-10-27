<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/integrations/ui.php (slot Integrations/integrations-ui-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// D1: owner Integrations hub. `feature:integrations` gates it (dark until the
// integrator flips shipped:true); when off + sellable, EnsureFeatureEnabled
// redirects to the feature.locked upsell. Single-segment /integrations resolves
// here (loaded before the {slug} catch-all via the F0-11 glob require).
Route::middleware([
    'web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard',
    'reseller.suspension', 'abuse.hold', 'demo', 'feature:integrations',
])
    ->controller('\Juwri\Modules\Integrations\Http\Controllers\User\IntegrationsHubController')
    ->name('integrations.')
    ->prefix('integrations')
    ->group(function () {
        Route::get('/', 'index')->name('index');
    });

// P4-21: the store's Odoo connector (one-way Juwri → Odoo). Not behind feature:integrations: the plan gate
// (PlanGate::ODOO_CONNECTOR, Community always allowed) is checked in every action; owner only (staff refused).
Route::middleware([
    'web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard',
    'reseller.suspension', 'abuse.hold', 'demo',
])
    ->controller('\Juwri\Modules\Integrations\Http\Controllers\User\OdooController')
    ->name('integrations.odoo.')
    ->prefix('integrations/odoo')
    ->group(function () {
        Route::get('/', 'show')->name('show');
        Route::post('/', 'save')->middleware('password.confirm')->name('save');
        Route::post('test', 'test')->middleware('throttle:10,1,integrations-odoo-test')->name('test');
        Route::get('mapping', 'mapping')->name('mapping');
        Route::put('mapping', 'saveMapping')->middleware('password.confirm')->name('mapping.save');
        Route::post('retry/{record}', 'retry')->whereNumber('record')->name('retry');
        Route::post('disconnect', 'disconnect')->name('disconnect');
    });

// P4-22: switching tools (menu, customers, loyalty balances from CSV / Excel / the previous Odoo). Owner only (staff
// refused by the staff route map), the current dashboard store only; always a dry run before the import.
Route::middleware([
    'web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard',
    'reseller.suspension', 'abuse.hold', 'demo',
])
    ->controller('\Juwri\Modules\Integrations\Http\Controllers\User\SwitchingController')
    ->name('integrations.switch.')
    ->prefix('integrations/switch')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('template/{dataset}.csv', 'template')->whereIn('dataset', ['menu', 'customers', 'loyalty'])->name('template');
        Route::post('preview', 'preview')->middleware('throttle:20,1,integrations-switch-preview')->name('preview');
        Route::get('{import}', 'show')->whereNumber('import')->name('show');
        Route::get('{import}/progress', 'progress')->whereNumber('import')->name('progress');
        Route::post('{import}/commit', 'commit')->whereNumber('import')->name('commit');
        Route::post('{import}/discard', 'discard')->whereNumber('import')->name('discard');
    });
