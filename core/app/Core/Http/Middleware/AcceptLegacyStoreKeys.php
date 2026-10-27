<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Juwri\Core\Support\LegacyStoreKeys;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-04 slice 3 (global middleware): backward compatibility for the
 * `restaurant_id` / `post_id` → `store_id` rename.
 *
 *  - Request: an old key in the query string, form body or JSON body becomes
 *    `store_id` (an explicit `store_id` wins; the old key is removed so it can never
 *    reach a mass-assignment as an unknown column). Every use is logged once per
 *    request (`legacy store key`, with the path and the keys) so we can see which
 *    clients still send them. Skipped for inbound webhooks (`*webhook*` paths): some
 *    partners' own payloads carry a `restaurant_id` of THEIR outlet (Swiggy, Zomato,
 *    the custom delivery body), read raw by their mappers.
 *  - Response: JSON from the public API (`api/*`) gets `restaurant_id` mirrored next to
 *    every `store_id` so existing API readers keep working.
 */
class AcceptLegacyStoreKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! str_contains($request->path(), 'webhook')) {
            $this->aliasRequest($request);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $request->is('api/*')) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $mirrored = LegacyStoreKeys::mirror($data);
                if ($mirrored !== $data) {
                    $response->setData($mirrored);
                }
            }
        }

        return $response;
    }

    private function aliasRequest(Request $request): void
    {
        $used = [];

        $query = $request->query->all();
        $aliased = LegacyStoreKeys::alias($query, $used);
        if ($aliased !== $query) {
            $request->query->replace($aliased);
        }

        if ($request->isJson()) {
            $json = $request->json()->all();
            $aliased = LegacyStoreKeys::alias($json, $used);
            if ($aliased !== $json) {
                $request->json()->replace($aliased);
            }
        }

        // For a JSON request Laravel's request bag IS the json bag (already done above).
        $body = $request->request->all();
        $aliased = LegacyStoreKeys::alias($body, $used);
        if ($aliased !== $body) {
            $request->request->replace($aliased);
        }

        if ($used !== []) {
            Log::info('legacy store key', ['path' => $request->path(), 'keys' => array_values(array_unique($used))]);
        }
    }
}
