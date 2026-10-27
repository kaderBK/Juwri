<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/lane-d.php (slot Verticals/verticals-lane-d-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/**
 * rev/multi-vertical F0-7 — Lane D DASHBOARD routes.
 *
 * This file is require()d INSIDE the User dashboard group in routes/web.php, so it
 * already inherits: namespace App\Http\Controllers\User, and middleware
 * ['auth','staff.dashboard','dashboard.sync','reseller.suspension','abuse.hold','csp.dashboard'].
 *
 * Add ONLY Lane D's authed dashboard routes here. Never edit web.php or another
 * lane's file (conflict-free parallel merges). Controller strings resolve under
 * the User namespace (e.g. '\Juwri\Modules\Menu\Http\Controllers\User\BrandController@index').
 */

// Lane D dashboard routes go here.

/*
 * rev/multi-vertical Lane D — Laundry (services).
 *
 * D1: the service item form. A brand-new dashboard surface for stores whose
 * VerticalRegistry::itemForm() === 'service' (laundry) — deliberately NOT
 * layered onto StoreController/ManagesMenu (food) or RetailItemController
 * (goods; hard-requires brand/weight/variant concepts a service never has).
 */
Route::prefix('stores/{store}/services')->name('laundry.services.')->controller('\Juwri\Modules\Verticals\Http\Controllers\User\ServiceItemController')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{service}/edit', 'edit')->name('edit');
    Route::put('/{service}', 'update')->name('update');
    Route::delete('/{service}', 'destroy')->name('destroy');
});
