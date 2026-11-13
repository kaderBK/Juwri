<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/lane-f.php (slot Pharmacy/verticals-lane-f-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/**
 * rev/multi-vertical F0-7 — Lane F DASHBOARD routes.
 *
 * This file is require()d INSIDE the User dashboard group in routes/web.php, so it
 * already inherits: namespace App\Http\Controllers\User, and middleware
 * ['auth','staff.dashboard','dashboard.sync','reseller.suspension','abuse.hold','csp.dashboard'].
 *
 * Add ONLY Lane F's authed dashboard routes here. Never edit web.php or another
 * lane's file (conflict-free parallel merges). Controller strings resolve under
 * the User namespace (e.g. '\Juwri\Modules\Menu\Http\Controllers\User\BrandController@index').
 */

// Lane F dashboard routes go here.

/*
 * rev/multi-vertical Lane F1/F2 — OTC-ONLY (D3) pharmacy compliance:
 * age-gate flag + batch/expiry tracking. A brand-new dashboard surface for
 * `age_gate`/`expiry`-capable verticals (pharmacy today — VerticalRegistry
 * capability, never a hardcoded 'pharmacy' string). Deliberately NOT layered
 * onto RetailItemController/its shared form partial (Lane A's frozen retail
 * item form) — PharmacyComplianceController double-checks the vertical
 * capability itself, so visiting these routes on any other store 404s.
 */
Route::prefix('stores/{store}/pharmacy')->name('pharmacy.compliance.')->controller('\Juwri\Modules\Pharmacy\Http\Controllers\User\PharmacyComplianceController')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::put('/settings', 'updateSettings')->name('settings');
    // P1-23 (AUP 3.1(b)): licence documents for review before the pharmacy is published.
    Route::post('/licence', 'storeLicence')->name('licence');
    Route::get('/{product}/edit', 'edit')->name('edit');
    Route::put('/{product}', 'update')->name('update');
});
