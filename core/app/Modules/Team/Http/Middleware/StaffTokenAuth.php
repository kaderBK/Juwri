<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Middleware\Concerns\StripsClientIdentity;
use Juwri\Modules\Team\Models\StaffToken;

/**
 * Authenticates the staff app (`/api/v1/staff/*`) via a personal access token
 * issued at login/PIN. Distinct from the owner, driver and customer token
 * middlewares. The authenticated StaffMember carries owner_id + store_id
 * + role, so every action is both tenant-scoped and permission-gated.
 */
class StaffTokenAuth
{
    use StripsClientIdentity;

    public function handle(Request $request, Closure $next)
    {
        $this->stripClientIdentity($request);

        $bearer = $request->bearerToken();
        // S9: the ?staff_token query fallback leaks the token into access logs; it is
        // gated behind config('auth.token_query_fallback') and deprecation-logged.
        $queryToken = config('auth.token_query_fallback') ? $request->query('staff_token') : null;
        $raw = $bearer ?? $queryToken;
        if (! $bearer && $raw) {
            Log::warning('auth.token_in_query', ['mw' => 'staff_token', 'route' => $request->path()]);
        }

        if (! $raw) {
            return ApiError::status(401, 'Unauthenticated.');
        }

        $token = StaffToken::where('token', hash('sha256', $raw))->first();

        if (! $token || $token->isExpired()) {
            return ApiError::status(401, 'Invalid or expired token.');
        }

        $staff = $token->staff;
        if (! $staff || ! $staff->active) {
            return ApiError::status(401, 'Staff account disabled.');
        }

        $token->update(['last_used_at' => now()]);

        $request->attributes->set('_staff_id', $staff->id);
        app()->instance('api.staff', $staff);
        app()->instance('api.staff_token', $token);

        return $next($request);
    }
}
