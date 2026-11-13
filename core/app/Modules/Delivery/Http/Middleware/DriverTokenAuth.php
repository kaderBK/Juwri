<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Middleware;

use Juwri\Core\Http\Middleware\Concerns\StripsClientIdentity;
use App\Models\Driver;
use Closure;
use Illuminate\Http\Request;
use App\Http\Middleware\ApiTokenAuth;

/**
 * Authenticates the driver-app endpoints (`/api/v1/driver/*`). A driver token is
 * issued by the owner (via the merchant API) and identifies exactly one driver.
 * Distinct from {@see ApiTokenAuth}, which authenticates the restaurant owner.
 */
class DriverTokenAuth
{
    use StripsClientIdentity;

    public function handle(Request $request, Closure $next)
    {
        $this->stripClientIdentity($request);

        $bearer = $request->bearerToken();
        // S9: the ?driver_token query fallback leaks the token into access logs; it is
        // gated behind config('auth.token_query_fallback') and deprecation-logged.
        $queryToken = config('auth.token_query_fallback') ? $request->query('driver_token') : null;
        $raw = $bearer ?? $queryToken;
        if (! $bearer && $raw) {
            \Illuminate\Support\Facades\Log::warning('auth.token_in_query', ['mw' => 'driver_token', 'route' => $request->path()]);
        }

        if (!$raw) {
            return \App\Support\ApiError::status(401, 'Unauthenticated.');
        }

        $driver = Driver::where('token', hash('sha256', $raw))->first();

        if (!$driver || !$driver->active) {
            return \App\Support\ApiError::status(401, 'Invalid driver token or driver disabled.');
        }

        $request->attributes->set('_driver_id', $driver->id);
        app()->instance('api.driver', $driver);

        return $next($request);
    }
}
