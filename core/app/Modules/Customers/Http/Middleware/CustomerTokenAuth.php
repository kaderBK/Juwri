<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Middleware;

use Juwri\Core\Http\Middleware\Concerns\StripsClientIdentity;
use Juwri\Modules\Customers\Models\CustomerToken;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates the standalone customer app (`/api/v1/customer/*`) via a personal
 * access token issued at register/login/OTP. Distinct from ApiTokenAuth (owner)
 * and DriverTokenAuth (driver). Every request is scoped to the token's customer,
 * which itself carries a store_id — so a customer only ever sees their own
 * data within their restaurant.
 */
class CustomerTokenAuth
{
    use StripsClientIdentity;

    public function handle(Request $request, Closure $next)
    {
        $this->stripClientIdentity($request);

        $bearer = $request->bearerToken();
        // S9: the ?customer_token query fallback leaks the token into access logs; it is
        // gated behind config('auth.token_query_fallback') and deprecation-logged.
        $queryToken = config('auth.token_query_fallback') ? $request->query('customer_token') : null;
        $raw = $bearer ?? $queryToken;
        if (! $bearer && $raw) {
            \Illuminate\Support\Facades\Log::warning('auth.token_in_query', ['mw' => 'customer_token', 'route' => $request->path()]);
        }

        if (!$raw) {
            return \App\Support\ApiError::status(401, 'Unauthenticated.');
        }

        $token = CustomerToken::where('token', hash('sha256', $raw))->first();

        if (!$token || $token->isExpired()) {
            return \App\Support\ApiError::status(401, 'Invalid or expired token.');
        }

        $customer = $token->customer;
        if (!$customer) {
            return \App\Support\ApiError::status(401, 'Invalid token.');
        }

        $token->update(['last_used_at' => now()]);

        $request->attributes->set('_customer_id', $customer->id);
        app()->instance('api.customer', $customer);
        app()->instance('api.customer_token', $token);

        return $next($request);
    }
}
