<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Agents\Http\Middleware\McpOwnerAuth;

/**
 * P1-16: OAuth discovery for the owner MCP server. Protected-resource metadata (RFC 9728) names this platform as
 * the authorization server; the authorization-server metadata (RFC 8414) points at Passport's authorize and token
 * endpoints (authorization code + PKCE S256, refresh tokens). Dynamic client registration is not offered: MCP
 * clients are registered by the platform (decision D-P1-16-3).
 */
class McpOAuthMetadataController extends Controller
{
    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => [McpOwnerAuth::OAUTH_SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'scopes_supported' => [McpOwnerAuth::OAUTH_SCOPE],
        ]);
    }
}
