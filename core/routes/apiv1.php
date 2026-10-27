<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 |----------------------------------------------------------------------
 | REST API v1 + driver API + MCP servers (R2d — extracted from web.php)
 |----------------------------------------------------------------------
 | This file is `require`d in-place from routes/web.php (see the require
 | statement there) so it executes inside the SAME `web` middleware group,
 | controller namespace, and variable scope — the route table is therefore
 | byte-identical to before the split (verified: `route:list` diff empty).
 | It MUST stay required AFTER the storefront {slug} catch-all's position;
 | do not register it separately in RouteServiceProvider (that would change
 | route order / the {slug} precedence contract, memory api-webhooks-hardened).
 */

use Juwri\Modules\Integrations\Services\Api\OpenApiGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

\Juwri\Core\Support\ModuleRoutes::slot('Integrations/apiv1-1');

\Juwri\Core\Support\ModuleRoutes::slot('Storefront/apiv1-1');

\Juwri\Core\Support\ModuleRoutes::slot('Reseller/apiv1');

\Juwri\Core\Support\ModuleRoutes::slot('Storefront/apiv1-2');

// Agents P3: customer/agent-facing MCP server (JSON-RPC), agent-scoped storefront token.
// P1-16: served by the laravel/mcp StorefrontMcpServer (shared agent tool registry).
Route::post('mcp/storefront', '\Juwri\Modules\Agents\Http\Controllers\McpServerController@storefront')
    ->middleware(['api', 'Juwri\Modules\Storefront\Http\Middleware\StorefrontTokenAuth:agent', 'throttle:storefront', 'ratelimit:mcp'])
    ->name('mcp.storefront');

\Juwri\Core\Support\ModuleRoutes::slot('Customers/apiv1-1');

\Juwri\Core\Support\ModuleRoutes::slot('Team/apiv1-1');

// ═══════════════════════════════════════════════════════════════════════════
// Owner mobile app (Hotwire Native shell) — rev/mobile-app/IMPLEMENTATION-SPEC.md
// ═══════════════════════════════════════════════════════════════════════════
// Public: version gate, credential login, and the one-time session hand-off.
// `handoff` is a BROWSER navigation inside the app's WebView — it sets the web
// session, so it deliberately carries no `api`/token middleware.
Route::prefix('api/v1/app')
    ->namespace('Api\V1')
    ->name('api.v1.app.')
    ->group(function () {
        Route::get('config', 'OwnerAppController@config')->middleware(['api', 'throttle:60,1,core-config'])->name('config');
        Route::get('path-configuration/{platform}.json', 'OwnerAppController@pathConfiguration')
            ->where('platform', 'ios|android')->middleware(['api', 'throttle:60,1,core-path-configuration-json'])->name('path-configuration');
        Route::post('auth/login', 'OwnerAppController@login')->middleware(['api', 'throttle:auth-login'])->name('login');
        Route::get('handoff/{nonce}', 'OwnerAppController@handoff')
            ->where('nonce', '[A-Za-z0-9]{64}')->middleware('throttle:30,1,core-handoff')->name('handoff');
    });

\Juwri\Core\Support\ModuleRoutes::slot('Integrations/apiv1-2');

\Juwri\Core\Support\ModuleRoutes::slot('Delivery/apiv1-1');

/* Dev MCP (rev/dev-mcp §1): public, read-only developer docs/reference server
   for AI coding tools. No auth by design — serves only already-public info.
   Rate-limited; validate_theme adds its own input-size caps. */
Route::post('mcp/dev', 'DevMcpController@handle')
    ->middleware(['throttle:60,1,core-mcp-dev', 'ratelimit:mcp'])
    ->name('mcp.dev');

\Juwri\Core\Support\ModuleRoutes::slot('Agents/apiv1-1');

/* WIRING-RUN A0: the per-feature route globs used to live HERE, ~400 lines after
   the {slug} storefront catch-all, so GET /stories was swallowed by publicView.
   They now load before the catch-all (see the "MUST precede" block above).
   Keep them there — moving them back silently 404s single-segment feature pages. */
