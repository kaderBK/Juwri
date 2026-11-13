<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/verticals/lane-b.php (slot Ordering/verticals-lane-b-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/**
 * rev/multi-vertical F0-7 — Lane B DASHBOARD routes.
 *
 * This file is require()d INSIDE the User dashboard group in routes/web.php, so it
 * already inherits: namespace App\Http\Controllers\User, and middleware
 * ['auth','staff.dashboard','dashboard.sync','reseller.suspension','abuse.hold','csp.dashboard'].
 *
 * Add ONLY Lane B's authed dashboard routes here. Never edit web.php or another
 * lane's file (conflict-free parallel merges). Controller strings resolve under
 * the User namespace (e.g. '\Juwri\Modules\Menu\Http\Controllers\User\BrandController@index').
 */

// Lane B dashboard routes go here.

// B1: fulfillment mode selection (store settings "Fulfillment" tab).
Route::get('fulfillment', '\Juwri\Modules\Ordering\Http\Controllers\User\FulfillmentSettingsController@edit')->name('stores.fulfillment.edit');
Route::put('fulfillment', '\Juwri\Modules\Ordering\Http\Controllers\User\FulfillmentSettingsController@update')->name('stores.fulfillment.update');
