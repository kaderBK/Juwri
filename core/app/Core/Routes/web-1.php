<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Core/web-1; was Platform/web-1 until P1-13 moved instance ops to Core); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Controllers\HealthController;
use Juwri\Core\Http\Controllers\MaintenanceController;
use Juwri\Core\Http\Controllers\PaymentCspReportController;
use Juwri\Core\Http\Controllers\PaymentPageProbeController;
use Juwri\Core\Http\Controllers\VoiceBridgeHealthController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

/* O1: liveness/readiness probe — public, no auth/installed group, before the
   {slug} catch-all so an external uptime monitor can always reach it. */
Route::get('/healthz', [HealthController::class, 'check']);

/* P6-09 V-06: readiness of the Cloud-only voice bridge. Off by default, so a
   Community/Enterprise install answers 200 bridge=disabled. Public like /healthz
   (register it before the catch-all) but in its own throttle bucket. */
Route::get('/healthz/voice-bridge', [VoiceBridgeHealthController::class, 'check'])
    ->middleware('throttle:60,1,core-voice-bridge-health');

/* Clear Cache — superadmin-only maintenance action (was unauthenticated). */
Route::get('/clear-cache', [MaintenanceController::class, 'clearCache'])->middleware(['auth', 'admin', 'staff.dashboard']);

Route::get('/favicon.ico', [MaintenanceController::class, 'favicon']);

/* PCI DSS 4.0 6.4.3: CSP violation reports of the payment pages (stored; report-uri + Reporting API). CSRF-exempt
   (browser-posted), throttled per client, capped per page and hour in the controller. */
Route::post('csp-report/payment/{page}', [PaymentCspReportController::class, 'store'])
    ->where('page', '[a-z0-9._-]{1,64}')
    ->middleware('throttle:'.max(1, (int) config('payment_scripts.reports.per_minute', 30)).',1,core-payment-csp-report')
    ->name('pci.csp-report');

/* PCI DSS 4.0 11.6.1: a payment page rendered with a synthetic payment for the change-detection job (signed, short-lived
   URL from pci:check-payment-pages; renders only, writes nothing). */
Route::get('pci/probe/{page}', [PaymentPageProbeController::class, 'show'])
    ->where('page', '[a-z0-9._-]{1,64}')
    ->middleware(['signed', 'throttle:60,1,core-pci-probe'])
    ->name('pci.probe');
