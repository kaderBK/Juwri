<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use Juwri\Modules\Integrations\Models\ApiToken;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner mobile app: runs AFTER ApiTokenAuth on the `api/v1/app/*` routes and
 * admits only tokens minted by the app login (`kind = app`).
 *
 * Why this exists: POST /api/v1/app/session turns a Bearer token into a full
 * dashboard web session. Without this check, an integration token the owner
 * minted for Zapier (`webhooks:write` only) could be exchanged for everything.
 * Integration tokens keep working on every other v1 route; they just can never
 * become a browser session.
 */
class EnsureOwnerAppToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mobile_app.enabled', true)) {
            return ApiError::response(403, 'app_disabled', 'The mobile app is not available.');
        }

        $token = $request->attributes->get('_api_token');

        if (! $token instanceof ApiToken || ($token->kind ?? 'api') !== 'app') {
            return ApiError::response(403, 'app_token_required', 'This endpoint requires a mobile app token.');
        }

        $user = $token->user;

        // Same rules as the web login: a blocked account or an admin cannot use
        // the owner app, even with a token that predates the block.
        if (! $user || (int) $user->status === 0) {
            return ApiError::response(403, 'account_blocked', 'Your account is blocked.');
        }
        if ($user->isAdmin()) {
            return ApiError::response(403, 'admin_not_supported', 'Admin accounts cannot use the owner app.');
        }

        return $next($request);
    }
}
