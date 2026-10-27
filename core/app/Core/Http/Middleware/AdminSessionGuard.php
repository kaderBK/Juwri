<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A1 (upstream superadmin hardening, 185d3adb0): superadmin session hardening, on
 * the admin route group only (owners keep their longer sessions).
 *
 *  - Idle timeout: no admin request for `auth.admin_idle_timeout` seconds
 *    (default 30 min) signs the admin out.
 *  - Step-up: the sensitive sections (users, payment gateways, platform
 *    settings, plans, resellers and tiers, withdrawals, WhatsApp settings,
 *    backups, admin security) need the password confirmed within
 *    `auth.admin_password_timeout` seconds (default 30 min) — a stolen session
 *    cookie alone cannot reach them. `auth.admin_step_up` = false turns it off.
 */
class AdminSessionGuard
{
    /** Admin route names (after the `admin.` prefix) that need a recent password. */
    public const STEP_UP = [
        'users.', 'gateways.', 'settings.', 'plans.', 'resellers.', 'reseller-tiers.',
        'withdrawals.', 'whatsapp.settings', 'backup-health.', 'security.', 'paylink.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }
        $session = $request->session();

        $idle = (int) config('auth.admin_idle_timeout', 1800);
        $last = (int) $session->get('admin_last_seen_at', 0);
        if ($idle > 0 && $last > 0 && now()->timestamp - $last > $idle && ! $session->has('quick_admin_user_id')) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => ___('Your admin session timed out. Please sign in again.'), 'redirect' => route('login')], 401)
                : redirect()->route('login')->with('status', ___('Your admin session timed out. Please sign in again.'));
        }
        $session->put('admin_last_seen_at', now()->timestamp);

        if (config('auth.admin_step_up', true) && $this->needsStepUp($request)) {
            $confirmedAt = (int) $session->get('auth.password_confirmed_at', 0);
            if (now()->timestamp - $confirmedAt > (int) config('auth.admin_password_timeout', 1800)) {
                if ($request->isMethod('GET') && ! $request->expectsJson()) {
                    return redirect()->guest(route('password.confirm'));
                }
                $message = ___('Confirm your password to continue.');

                return $request->expectsJson() || $request->ajax()
                    ? response()->json(['success' => false, 'message' => $message, 'redirect' => route('password.confirm')], 423)
                    : redirect()->guest(route('password.confirm'))->with('status', $message);
            }
        }

        return $next($request);
    }

    private function needsStepUp(Request $request): bool
    {
        $name = (string) optional($request->route())->getName();
        if (! Str::startsWith($name, 'admin.')) {
            return false;
        }
        $name = substr($name, 6);

        return Str::startsWith($name, self::STEP_UP);
    }
}
