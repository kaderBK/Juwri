<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setup gate for the web groups: until the app is set up (see
 * app_is_installed() — an application key plus a migrated database) every
 * gated request is sent to the setup notice page. There is no web installer;
 * setup is `.env` + `php artisan migrate --seed`.
 */
class Installed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app_is_installed()) {
            return $next($request);
        }

        return redirect()->route('setup.pending');
    }
}
