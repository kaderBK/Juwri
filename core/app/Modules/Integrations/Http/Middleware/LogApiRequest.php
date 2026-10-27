<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Middleware;

use Juwri\Modules\Integrations\Models\ApiRequestLog;
use Juwri\Modules\Integrations\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * HPMS E11: log each owner-API-token request AFTER the response. Keyed by the
 * ApiToken that ApiTokenAuth resolved onto the request attributes. Self-guarded
 * (a logging failure never breaks the API) and sampling-safe: skips silently
 * when the table is missing (mid-migration / not yet deployed).
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);

        try {
            if (! Schema::hasTable('api_request_logs')) {
                return $response;
            }
            $token = $request->attributes->get('_api_token');
            ApiRequestLog::create([
                'token_id'    => $token instanceof ApiToken ? $token->id : null,
                'method'      => substr($request->method(), 0, 8),
                'path'        => substr($request->path(), 0, 191),
                'status'      => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
                'ip'          => $request->ip(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // best-effort observability — never break the API request
        }

        return $response;
    }
}
