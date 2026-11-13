<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/lane-e.php (slot Verticals/verticals-lane-e-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/**
 * rev/multi-vertical F0-7 — Lane E DASHBOARD routes.
 *
 * This file is require()d INSIDE the User dashboard group in routes/web.php, so it
 * already inherits: namespace App\Http\Controllers\User, and middleware
 * ['auth','staff.dashboard','dashboard.sync','reseller.suspension','abuse.hold','csp.dashboard'].
 *
 * Add ONLY Lane E's authed dashboard routes here. Never edit web.php or another
 * lane's file (conflict-free parallel merges). Controller strings resolve under
 * the User namespace (e.g. '\Juwri\Modules\Menu\Http\Controllers\User\BrandController@index').
 */

// Lane E dashboard routes go here.

/*
 * rev/multi-vertical Lane E — Car-parts fitment management (E3).
 *
 * A brand-new dashboard surface, deliberately NOT layered onto Lane A's
 * RetailItemController/retail item form (frozen seam) — every action here
 * re-checks the vertical's `fitment` capability itself, so visiting these
 * routes on a non-carparts store 404s.
 */
/*
 * P3-08b (D12): the store-wide fitment CSV import. Declared BEFORE the `/{product}` routes below, which would
 * otherwise take `import` for a product id.
 */
Route::prefix('stores/{store}/fitments/import')->name('retail.fitments.import')->controller('\Juwri\Modules\Verticals\Http\Controllers\User\FitmentImportController')->group(function () {
    Route::get('/', 'index')->name('');
    Route::post('/', 'upload')->middleware('throttle:20,1,verticals-route')->name('.upload');
    Route::get('/template.csv', 'template')->name('.template');
    Route::get('/{import}', 'show')->whereUuid('import')->name('.show');
    Route::post('/{import}/confirm', 'confirm')->whereUuid('import')->middleware('throttle:20,1,verticals-confirm')->name('.confirm');
    Route::get('/{import}/errors.csv', 'errors')->whereUuid('import')->name('.errors');
    Route::delete('/{import}', 'destroy')->whereUuid('import')->name('.destroy');
});

Route::prefix('stores/{store}/fitments')->name('retail.fitments.')->controller('\Juwri\Modules\Verticals\Http\Controllers\User\FitmentController')->group(function () {
    Route::get('/', 'landing')->name('index');
    Route::get('/{product}', 'show')->name('show');
    Route::post('/{product}', 'store')->name('store');
    Route::post('/{product}/bulk', 'bulkStore')->name('bulkStore');
    Route::post('/{product}/import-csv', 'importCsv')->name('importCsv');
    Route::post('/{product}/oem', 'updateOem')->name('updateOem');
    Route::delete('/{product}/{fitment}', 'destroy')->name('destroy');
});
