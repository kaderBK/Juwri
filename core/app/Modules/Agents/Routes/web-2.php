<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-16: Agents web slot 2 — included in place by ModuleRoutes::slot('Agents/web-2') in routes/web.php.
use Illuminate\Support\Facades\Route;

// OAuth 2.1 discovery for the owner MCP server (RFC 9728 protected resource + RFC 8414 authorization server
// metadata pointing at Passport). No dynamic client registration (D-P1-16-3).
Route::get('.well-known/oauth-protected-resource', '\Juwri\Modules\Agents\Http\Controllers\McpOAuthMetadataController@protectedResource')->name('mcp.oauth.protected-resource');
Route::get('.well-known/oauth-protected-resource/mcp', '\Juwri\Modules\Agents\Http\Controllers\McpOAuthMetadataController@protectedResource')->name('mcp.oauth.protected-resource.mcp');
Route::get('.well-known/oauth-authorization-server', '\Juwri\Modules\Agents\Http\Controllers\McpOAuthMetadataController@authorizationServer')->name('mcp.oauth.authorization-server');
