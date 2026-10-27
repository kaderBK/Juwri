<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/lane-a.php (slot Verticals/verticals-lane-a-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/**
 * rev/multi-vertical F0-7 — Lane A DASHBOARD routes.
 *
 * This file is require()d INSIDE the User dashboard group in routes/web.php, so it
 * already inherits: namespace App\Http\Controllers\User, and middleware
 * ['auth','staff.dashboard','dashboard.sync','reseller.suspension','abuse.hold','csp.dashboard'].
 *
 * Add ONLY Lane A's authed dashboard routes here. Never edit web.php or another
 * lane's file (conflict-free parallel merges). Controller strings resolve under
 * the User namespace (e.g. '\Juwri\Modules\Menu\Http\Controllers\User\BrandController@index').
 */

// Lane A dashboard routes go here.

/*
 * rev/multi-vertical Lane A — Catalog & attributes.
 *
 * A brand-new dashboard surface for goods/services verticals (retail, cosmetic,
 * clothing, carparts, pharmacy — VerticalRegistry::itemForm() === 'retail').
 * Deliberately NOT layered onto StoreController/ManagesMenu (the shared,
 * food-framed item form) — every controller here double-checks the vertical
 * shape itself, so visiting these routes on a restaurant store 404s.
 */
Route::prefix('stores/{store}/products')->name('retail.products.')->controller('\Juwri\Modules\Verticals\Http\Controllers\User\RetailItemController')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/export-csv', 'exportCsv')->middleware('ratelimit:export')->name('exportCsv');
    Route::post('/import-csv', 'importCsv')->name('importCsv');
    Route::get('/{product}/edit', 'edit')->name('edit');
    Route::put('/{product}', 'update')->name('update');
    Route::delete('/{product}', 'destroy')->name('destroy');
});

// Variant grid editor (A2) — its own controller, so it's routed outside the
// ->controller('\Juwri\Modules\Verticals\Http\Controllers\User\RetailItemController') group above instead of a bare action
// string that would (wrongly) resolve to RetailItemController@generate.
Route::prefix('stores/{store}/products/{product}/variants')->name('retail.products.variants.')->group(function () {
    Route::post('/generate', '\Juwri\Modules\Verticals\Http\Controllers\User\VariantMatrixController@generate')->name('generate');
    Route::post('/bulk-update', '\Juwri\Modules\Verticals\Http\Controllers\User\VariantMatrixController@bulkUpdate')->name('bulkUpdate');
    Route::delete('/{variant}', '\Juwri\Modules\Verticals\Http\Controllers\User\VariantMatrixController@destroy')->name('destroy');
});
