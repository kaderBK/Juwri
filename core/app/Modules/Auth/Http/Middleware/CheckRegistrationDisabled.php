<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the sign-up routes (`checkRegistrationDisabled` alias) when the
 * platform setting enable_user_registration is off.
 */
class CheckRegistrationDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('settings.enable_user_registration', 1)) {
            return $next($request);
        }

        app_alert_error(___('Registration is currently disabled.'));

        return redirect()->route('login');
    }
}
