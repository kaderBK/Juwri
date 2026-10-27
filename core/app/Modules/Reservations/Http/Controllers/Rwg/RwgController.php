<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Controllers\Rwg;

use Juwri\Core\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GOOGLE-PARTNER Lane C — base for the Reserve-with-Google booking server (Google → us).
 *
 * Shared JSON helpers for the rwg/v3 REST endpoints. Auth + CSRF-exemption +
 * rate-limit are applied by the route group (RwgAuth + ratelimit:webhook-in +
 * withoutMiddleware(PreventRequestForgery)); controllers assume the caller is Google.
 *
 * Contract note: E2E business-logic failures are returned as HTTP 200 with a
 * `*_failure` body (see BookingServerController), NOT a non-200 — only auth,
 * kill-switch, and malformed-JSON use real HTTP error codes.
 */
abstract class RwgController extends Controller
{
    /**
     * Decode the request JSON body to an array (Google sends application/json).
     *
     * G8: a non-empty body that is not valid JSON, or valid JSON that is not an
     * object/array, must be a transport-level 400 `bad_request` — never silently
     * treated as an empty payload (which returned a 200 empty answer). An empty
     * body stays an empty payload (health-probe tolerance).
     *
     * @return array<string,mixed>
     */
    protected function payload(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new HttpResponseException($this->badRequest('bad_request'));
        }

        return $decoded;
    }

    /** Transport-level error (auth handled upstream; this is for malformed input). */
    protected function badRequest(string $error = 'bad_request'): JsonResponse
    {
        return response()->json(['error' => $error], 400);
    }
}
