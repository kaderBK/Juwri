<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * O12b: stamp every request with a correlation id and tenant context.
 *
 * - Reads an inbound `X-Request-Id` (so a value set by a proxy/CDN is preserved)
 *   or mints a uuid, and echoes it back on the response.
 * - Pushes `request_id` + `user_id` + `store_id` into the log context so every
 *   line written during the request is greppable back to one actor/tenant.
 *
 * Cheap by design — it runs on every web/api request including the anonymous
 * storefront, so `store_id` is read from the already-resolved session value
 * (set by dashboard.sync), never a fresh query.
 */
class AddRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);

        // Best-effort: log context must never break a request. (It also keeps a
        // strict Log-facade mock in an unrelated test from receiving an
        // unexpected withContext() call and throwing.)
        try {
            Log::withContext([
                'request_id' => $requestId,
                'user_id'    => Auth::id(),
                'store_id'   => ($request->hasSession() && Auth::check())
                    ? $request->session()->get('dashboard_store_id')
                    : null,
            ]);
        } catch (\Throwable $e) {
            // ignore — correlation context is non-essential
        }

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }
}
