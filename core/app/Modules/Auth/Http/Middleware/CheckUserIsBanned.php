<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a web-guard account out as soon as it is blocked (status 0), even
 * mid-session, and sends it to the sign-in page with a notice.
 */
class CheckUserIsBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($guard->check() && (int) $guard->user()->status === 0) {
            $guard->logout();
            app_alert_error(___('Your Account is banned, please contact Admin.'));

            return redirect()->route('login');
        }

        return $next($request);
    }
}
