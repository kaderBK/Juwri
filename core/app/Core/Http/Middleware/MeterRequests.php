<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Juwri\Core\Contracts\Billing\RequestMeter;

/**
 * Abuse Guard P7 slot (P1-13): per-tenant request metering at terminate time, cache-only. The metering itself is
 * the {@see RequestMeter} extension point (Billing's UsageMeter; nothing in Community). Was Billing's UsageMeter
 * listed directly in the web/api groups.
 */
class MeterRequests
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        app(RequestMeter::class)->record($request, $response);
    }
}
