<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * P1-13: Community default of a route middleware alias that only an ee module gives meaning (e.g.
 * `reseller.suspension`: Community has no resellers to suspend). The ee provider re-points the alias.
 */
final class PassThrough
{
    public function handle(Request $request, Closure $next, mixed ...$args): mixed
    {
        return $next($request);
    }
}
