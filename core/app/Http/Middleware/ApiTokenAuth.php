<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Middleware\Concerns\StripsClientIdentity;
use Juwri\Core\Services\AbuseHold;
use Juwri\Modules\Integrations\Models\ApiToken;

class ApiTokenAuth
{
    use StripsClientIdentity;

    public function handle(Request $request, Closure $next, string $ability = 'read')
    {
        $this->stripClientIdentity($request);

        $bearer = $request->bearerToken();
        $queryToken = config('auth.token_query_fallback') ? $request->query('api_token') : null;
        $raw = $bearer ?? $queryToken;
        $fromQuery = ! $bearer && $raw;

        if (! $raw) {
            return ApiError::status(401, 'Unauthenticated.');
        }

        $token = ApiToken::where('token', hash('sha256', $raw))->first();

        if (! $token || $token->isExpired()) {
            return ApiError::status(401, 'Invalid or expired token.');
        }

        // P4-12: a suspended or closed owner (status 0) is refused — sign-in is refused for them too, and an admin
        // suspension does not delete the tokens (the account may be reinstated). Same answer as the owner app's
        // (EnsureOwnerAppToken): 403 account_blocked. Before, the owner's API and MCP kept working while suspended.
        $owner = $token->user;
        if ($owner === null) {
            return ApiError::status(401, 'Invalid or expired token.');
        }
        if ((int) $owner->status === 0) {
            return ApiError::response(403, 'account_blocked', 'Your account is blocked.');
        }

        // `any` = identity-only routes (GET /api/v1/me): any valid, unexpired token
        // may read them regardless of its resource abilities. A Zapier / Make / n8n
        // connection minted with only `webhooks:write` uses /me as its auth test, so
        // requiring a resource ability there would reject a perfectly valid token.
        if ($ability !== 'any' && ! $token->hasAbility($ability)) {
            return ApiError::status(403, "This token lacks the '{$ability}' ability.");
        }

        // A5-1: passing the token in the query string leaks it into access logs, proxies
        // and browser history. Warn (once per request) and announce a sunset; removal of
        // the query path is a later change — this only deprecates.
        if ($fromQuery) {
            Log::warning('deprecated ?api_token used', [
                'token_id' => $token->id, 'path' => $request->path(),
            ]);
        }

        // A5-1: last_used_at is observability, not a hot fact — writing it on EVERY
        // request is a DB write per call. Only persist when it is stale (>60s).
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subSeconds(60))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        // Attach owner identity via request attributes (server-controlled, not client input).
        $request->attributes->set('_api_user_id', $token->user_id);
        $request->attributes->set('_api_token', $token);
        app()->instance('api.user', $token->user);

        // Abuse Guard P6 (6.3): a held merchant's API cannot WRITE; reads still pass.
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && AbuseHold::active((int) $token->user_id)) {
            return ApiError::status(423, 'Account is temporarily on hold pending review.');
        }

        $response = $next($request);

        // A5-1: signal the deprecation on the wire so integrators notice before removal.
        if ($fromQuery && method_exists($response, 'header')) {
            $response->header('Deprecation', 'true');
            $response->header('Sunset', 'Thu, 31 Dec 2026 23:59:59 GMT');
            $response->header('Link', '<'.url('/developers').'>; rel="deprecation"');
        }

        return $response;
    }
}
