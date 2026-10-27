<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only demo installs (`demo` alias): while demo_mode() is on, every
 * write request is refused and the visitor is told why. Reads pass.
 */
class IsDemoMode
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! demo_mode() || ! in_array($request->getMethod(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $message = ___('These features are disabled on the demo site.');

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message]);
        }

        app_alert_error($message);

        return back();
    }
}
