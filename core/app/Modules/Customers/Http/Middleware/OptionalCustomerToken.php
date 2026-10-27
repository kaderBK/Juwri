<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Middleware;

use Juwri\Modules\Customers\Models\CustomerToken;
use Closure;
use Illuminate\Http\Request;

/**
 * F6 · Like CustomerTokenAuth, but NEVER rejects: if a valid bearer/query token is
 * present it binds app('api.customer') so a logged-in diner's review is linked +
 * verified; if absent or invalid, the request proceeds anonymously (session_hash path).
 */
class OptionalCustomerToken
{
    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        // S9: the ?customer_token query fallback leaks the token into access logs; it is
        // gated behind config('auth.token_query_fallback') and deprecation-logged.
        $queryToken = config('auth.token_query_fallback') ? $request->query('customer_token') : null;
        $raw = $bearer ?? $queryToken;
        if (! $bearer && $raw) {
            \Illuminate\Support\Facades\Log::warning('auth.token_in_query', ['mw' => 'customer_token', 'route' => $request->path()]);
        }

        if ($raw) {
            $token = CustomerToken::where('token', hash('sha256', $raw))->first();

            if ($token && !$token->isExpired() && $token->customer) {
                $token->update(['last_used_at' => now()]);
                $request->attributes->set('_customer_id', $token->customer->id);
                app()->instance('api.customer', $token->customer);
                app()->instance('api.customer_token', $token);
            }
        }

        return $next($request);
    }
}
