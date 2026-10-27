<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Middleware;

use App\Models\StorefrontToken;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiFeatures;

/**
 * GAP-3: authenticates the public Storefront API (`/api/v1/storefront/*`) via a
 * per-restaurant token in the `X-Storefront-Token` header. The restaurant is
 * resolved from the token ONCE and bound to the container — never taken from the
 * client. Identity is bound via the container/attributes (NOT $request->merge, so
 * it can never be spoofed through the input namespace).
 *
 * 401 = missing/invalid/revoked token. 403 = valid token lacking the required
 * scope, plan without storefront_api, or a write from a disallowed origin.
 */
class StorefrontTokenAuth
{
    public function handle(Request $request, Closure $next, string $scope = 'menu:read')
    {
        $headerToken = $request->header('X-Storefront-Token');
        $queryToken = config('auth.token_query_fallback') ? $request->query('storefront_token') : null;
        $raw = $headerToken ?? $queryToken;
        $fromQuery = ! $headerToken && $raw;

        if (! $raw) {
            return ApiError::status(401, 'Missing storefront token.');
        }

        $token = StorefrontToken::where('token', hash('sha256', $raw))
            ->where('active', true)
            ->first();
        if (! $token) {
            return ApiError::status(401, 'Invalid or revoked storefront token.');
        }

        $store = $token->store;
        if (! $store) {
            return ApiError::status(401, 'Invalid storefront token.');
        }

        if (! $token->hasScope($scope)) {
            return ApiError::status(403, 'Token lacks required scope: '.$scope);
        }

        // Plan gate: the restaurant owner's plan must enable the storefront API. P3-06b (API-5): the specific
        // code `storefront_api_not_enabled` with the missing feature key in `details.feature`.
        $owner = User::find($store->user_id);
        if (! $owner || ! plan_feature('storefront_api', $owner)) {
            return StorefrontApiFeatures::refusal(StorefrontApiFeatures::STOREFRONT_API);
        }
        // P3-06b (API-13): every call with a kiosk token also needs `kiosk_api` and the `kiosk` feature (neither implies
        // the other), plus the store's own `kiosk_enabled` switch (refused without `details.feature`).
        if ($token->isKiosk()) {
            $refusal = StorefrontApiFeatures::kioskRefusal($store, $owner);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        // Origin allowlist for the sensitive scopes (order:write, agent) — read
        // tokens stay embeddable. A `browser` token is meant for front-end JS, so
        // its origin binding is MANDATORY (fail closed: no allowlist, or a missing/
        // unlisted Origin, is rejected). A server token (the default) keeps the
        // opt-in behaviour: its allowlist is enforced only when one is set, so no
        // existing server-to-server token — which sends no Origin — is affected.
        if (str_ends_with($scope, ':write') || $scope === 'agent') {
            $allowed = $token->allowed_origins ?: [];
            $origin = $request->header('Origin');
            $originOk = $origin && in_array($origin, $allowed, true);

            if ($token->browser) {
                if (! $originOk) {
                    return ApiError::status(403, 'Origin not allowed for this token.');
                }
            } elseif (! empty($allowed) && ! $originOk) {
                return ApiError::status(403, 'Origin not allowed for this token.');
            }
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        // Bind resolved identity — container only, never request input.
        app()->instance('api.storefront_token', $token);
        app()->instance('api.storefront_post', $store);

        if ($fromQuery) {
            Log::warning('auth.token_in_query', [
                'mw' => 'storefront_token',
                'token_id' => $token->id,
                'route' => $request->path(),
            ]);
        }

        $response = $next($request);

        if ($fromQuery && method_exists($response, 'header')) {
            $response->header('Deprecation', 'true');
            $response->header('Sunset', 'Thu, 31 Dec 2026 23:59:59 GMT');
            $response->header('Link', '<'.url('/developers').'>; rel="deprecation"');
        }

        return $response;
    }
}
