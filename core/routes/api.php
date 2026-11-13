<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// P10-1: was auth:sanctum (skeleton stub; no sanctum guard is defined and the
// User model carries Passport's HasApiTokens) — served by the passport guard now.
// F5-3: a banned tenant's partner rails are refused (403), stateless.
// F6-5: this returns the tenant's own account record — tenant profile data, so it
// needs the `restaurant:read` scope, and EnforceAppScope intersects that against
// the install's frozen `granted_scopes` at request time (a re-consent takes effect
// immediately, a narrowing revokes immediately — neither needs a re-install).
Route::middleware([
    'auth:api',
    \Juwri\Modules\AppStore\Http\Middleware\EnsurePartnerTenantActive::class,
    \Juwri\Modules\AppStore\Http\Middleware\EnforceAppScope::class.':restaurant:read',
])->get('/user', [UserController::class, 'show']);

// P3-4: partner apps report metered usage with their OAuth token (server-to-server).
// F5-3: EnsurePartnerTenantActive refuses (403) when the tenant is banned — stateless,
// so an unban restores partner access with no repair step.
Route::middleware(['auth:api', \Juwri\Modules\AppStore\Http\Middleware\EnsurePartnerTenantActive::class])
    ->get('/app/license', [\Juwri\Modules\AppStore\Http\Controllers\Api\AppLicenseController::class, 'show'])
    ->name('api.app.license'); // P4-6 feature-platform
Route::middleware(['auth:api', \Juwri\Modules\AppStore\Http\Middleware\EnsurePartnerTenantActive::class])
    ->post('/app/usage', [\Juwri\Modules\AppStore\Http\Controllers\Api\AppUsageController::class, 'store'])
    ->name('api.app.usage');

// N8 Sunmi kiosk printing: ESC/POS receipt stream, authed by the order's unguessable
// track_token (tenant-scoped by construction). The kiosk app raw-prints the bytes.
Route::get('/kiosk/orders/{token}/escpos', [\Juwri\Modules\Pos\Http\Controllers\Api\KioskPrintController::class, 'escpos'])
    ->middleware('throttle:60,1,core-kiosk-orders-escpos')->name('api.kiosk.escpos');

// P1-20 (J28): Caddy on-demand TLS `ask` — certificates only for hosts the platform really serves.
Route::get('tls/ask', '\\'.\Juwri\Modules\Storefront\Http\Controllers\TlsAskController::class) // leading \ = no App namespace prefix
    ->middleware('throttle:120,1,core-tls-ask')->name('tls.ask');

// Owner 2026-10-05: juwri-hosted services for self-hosted installations (Platform module, ee; absent without it).
\Juwri\Core\Support\ModuleRoutes::slot('Platform/api-hosted');
