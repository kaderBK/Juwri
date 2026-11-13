<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Middleware;

use Juwri\Core\Models\User;
use App\Services\Entitlements;
use Closure;
use Illuminate\Http\Request;

/**
 * F5-3 (feature-platform gap-fix): partner-app API calls made on behalf of a
 * BANNED tenant are refused. The web session already bounces a banned owner
 * (CheckUserIsBanned), but the partner rails authenticate with a long-lived
 * OAuth token, so a ban previously left the partner reading and metering the
 * banned workspace forever.
 *
 * STATELESS: the check reads `users.status` at request time — no tokens are
 * revoked and no rows are touched on ban, so an unban restores partner access
 * with no repair step. JSON 403 (never a redirect — these are S2S calls).
 */
class EnsurePartnerTenantActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('api') ?? $request->user();

        if ($user instanceof User && Entitlements::isBanned($user)) {
            return response()->json([
                'message' => 'This account is suspended.',
            ], 403);
        }

        return $next($request);
    }
}
