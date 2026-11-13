<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Delivery/web-2); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// SYSTEMS-RUN E-P1: driver PWA shell (browser) — MUST precede the {slug} catch-all.
Route::get('driver/manifest.webmanifest', '\Juwri\Modules\Delivery\Http\Controllers\DriverPwaController@manifest')->name('driver.pwa.manifest');
Route::get('driver/sw.js', '\Juwri\Modules\Delivery\Http\Controllers\DriverPwaController@serviceWorker')->name('driver.pwa.sw');
/* No `feature:driver_app` here: this is an ANONYMOUS route, so the gate has no
   subject (no logged-in owner) and EnsureFeatureEnabled bounced every driver to
   the owner upsell page. The shell is an empty HTML+JS container that cannot
   know a store until the driver's token is exchanged; the real gate lives on
   `api/v1/driver/*`, where `feature:driver_app` runs AFTER DriverTokenAuth. */
Route::get('driver', '\Juwri\Modules\Delivery\Http\Controllers\DriverPwaController@shell')->middleware('csp.app')->name('driver.pwa.shell'); // P6-122 enforced CSP

/* L4 T4-3 driver app: marketplace FLEET driver shell — MUST precede the {slug}
   catch-all. Platform-level (fleet drivers have no restaurant), so no
   per-store feature gate: DriverTokenAuth on the API is the real guard. */
Route::get('driver-app/manifest.webmanifest', '\Juwri\Modules\Delivery\Http\Controllers\DriverPwaController@fleetManifest')->name('driver.fleet.manifest');
Route::get('driver-app', '\Juwri\Modules\Delivery\Http\Controllers\DriverPwaController@fleetShell')->middleware('csp.app')->name('driver.fleet.shell'); // P6-122 enforced CSP
