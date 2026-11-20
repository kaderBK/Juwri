<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Juwri\Modules\Auth\Http\Controllers\LoginController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest-only screens (`guest` alias: sign-in, sign-up): an account that is
 * already signed in on any of the given guards goes to its dashboard.
 */
class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        foreach ($guards ?: [null] as $guard) {
            if (Auth::guard($guard)->check()) {
                // R5: a partner owner / team seat goes to the partner portal or the chooser (Reseller module hook).
                return redirect(LoginController::landingFor(Auth::guard($guard)->user()) ?? RouteServiceProvider::USER);
            }
        }

        return $next($request);
    }
}
