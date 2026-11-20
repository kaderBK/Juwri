<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Support\PasskeySession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate (`admin` alias): only a signed-in superadmin passes.
 * Guests and every other account are sent to the sign-in page.
 *
 * With auth.force_2fa_admin on, an admin without two-factor auth is sent
 * to enrol first; 2fa.show is outside this gate, so there is no loop.
 * P1-19 (J28 "2FA or passkey"): a session opened with a passkey passes too.
 */
class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            return redirect()->route('login');
        }

        // SEC-1119 (#6, defence in depth): a staff dashboard session signs the web guard in AS THE OWNER, so a superadmin
        // owner's staff would pass the check above. Refuse here too, so an admin group without staff.dashboard is covered.
        abort_if(is_staff_dashboard(), 403);

        if (config('auth.force_2fa_admin') && ! PasskeySession::hasStrongSignIn($user)) {
            return redirect()->route('2fa.show')
                ->with('warning', ___('Enable two-factor authentication to continue.'));
        }

        return $next($request);
    }
}
