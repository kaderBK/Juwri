<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/apiv1.php (slot Agents/apiv1-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* #102: MCP server (JSON-RPC over HTTP). ApiToken auth (read); mutating tools
   require the token's write ability. Rate-limited to 60 calls/min.
   P1-16: served by the laravel/mcp OwnerMcpServer; McpOwnerAuth = ApiTokenAuth (unchanged)
   or a Passport OAuth 2.1 token with the mcp:use scope. */
Route::post('mcp', '\Juwri\Modules\Agents\Http\Controllers\McpServerController@owner')
    ->middleware(['Juwri\Modules\Agents\Http\Middleware\McpOwnerAuth', 'throttle:60,1,agents-mcp', 'ratelimit:mcp'])
    ->name('mcp');

/* MCP-expansion P2: platform-wide Catalog MCP (JSON-RPC). Public + read-only —
   cross-store discovery. No auth by design (serves only already-public store
   info); rate-limited. CSRF-exempt (see PreventRequestForgery::$except).
   P1-16: served by the laravel/mcp CatalogMcpServer. */
Route::post('mcp/catalog', '\Juwri\Modules\Agents\Http\Controllers\McpServerController@catalog')
    ->middleware(['throttle:60,1,agents-mcp-catalog', 'ratelimit:mcp'])
    ->name('mcp.catalog');

/* MCP-expansion P3: Customer Account MCP (JSON-RPC). Auth'd by the existing
   customer token (CustomerTokenAuth); a diner reads/tracks/reorders THEIR own
   orders. CSRF-exempt (see PreventRequestForgery::$except).
   P1-16: served by the laravel/mcp CustomerMcpServer. */
Route::post('mcp/customer', '\Juwri\Modules\Agents\Http\Controllers\McpServerController@customer')
    ->middleware(['Juwri\Modules\Customers\Http\Middleware\CustomerTokenAuth', 'throttle:60,1,agents-mcp-customer', 'ratelimit:mcp'])
    ->name('mcp.customer');
