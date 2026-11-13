<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ImpersonationRoutes;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security (AUTH-8): limits a superadmin "log in as user" session the same way
 * reseller impersonation is limited (ImpersonationGuard):
 *   - a hard 60-minute cap, after which the admin is signed back in as themself;
 *   - the same sensitive pages (password, 2FA, billing, checkout, ownership
 *     transfer, API tokens, webhooks) are off-limits while impersonating.
 * The session is marked by `quick_admin_user_id` (set in Admin\UserController
 * ::loginAsUser, cleared by logout) plus `quick_admin_impersonation`.
 */
class AdminImpersonationGuard
{
    public const TTL = 60 * 60;

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = $request->hasSession() ? $request->session()->get('quick_admin_user_id') : null;
        if (! $adminId) {
            return $next($request);
        }

        $meta = (array) $request->session()->get('quick_admin_impersonation', []);
        if (! isset($meta['started_at'])) {
            // A session opened before this guard existed: start its clock now.
            $meta['started_at'] = now()->timestamp;
            $request->session()->put('quick_admin_impersonation', $meta);
        }

        if (now()->timestamp - (int) $meta['started_at'] > self::TTL) {
            return $this->expire($request, (int) $adminId, $meta);
        }

        $routeName = optional($request->route())->getName();
        if (ImpersonationRoutes::blocks($routeName)) {
            log_activity('admin.impersonate_blocked', null, ['admin_id' => (int) $adminId, 'route' => $routeName, 'target_id' => $meta['target_id'] ?? null]);
            abort(403, ___('This action is disabled during an impersonation session.'));
        }

        return $next($request);
    }

    /** @param  array<string, mixed>  $meta */
    private function expire(Request $request, int $adminId, array $meta): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $admin = User::find($adminId);
        if ($admin && $admin->isAdmin() && (int) $admin->status === 1) { // P6-229: still active
            Auth::guard('web')->login($admin);
            log_activity('admin.impersonate_expire', $admin, ['admin_id' => $adminId, 'target_id' => $meta['target_id'] ?? null]);
            $to = route('admin.users.index');
        } else {
            $to = route('login');
        }

        return $request->expectsJson()
            ? response()->json(['error' => 'impersonation_expired', 'redirect' => $to], 419)
            : redirect($to)->with('status', ___('Impersonation session expired.'));
    }
}
