<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Broadcasting\LiveUpdates;
use Symfony\Component\HttpFoundation\Response;

/** The broadcast auth endpoint only exists while live updates are switched on; otherwise it is a plain 404. */
final class EnsureLiveUpdatesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(LiveUpdates::enabled(), 404);

        return $next($request);
    }
}
