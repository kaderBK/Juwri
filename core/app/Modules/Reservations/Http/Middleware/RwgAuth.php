<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Middleware;

use Juwri\Modules\Reservations\Services\Rwg\RwgService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GOOGLE-PARTNER Lane C — Reserve with Google booking-server auth.
 *
 * HTTP Basic verification for the `rwg/v3/*` booking-server routes (Google → us).
 * Constant-time credential compare (hash_equals on BOTH parts, no short-circuit),
 * plus a kill-switch: when the integration is disabled or unconfigured, every call
 * gets 503 so Google backs off — the surface is inert until an owner wires creds.
 *
 * Referenced by FQCN from the rwg/v3 route group (no Http/Kernel alias edit — Lane
 * D references this same class). CSRF is stripped on that group via
 * ->withoutMiddleware(PreventRequestForgery::class); this middleware never trusts a token.
 *
 * No tenant data ever appears in an error body (RUNBOOK §4).
 */
class RwgAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Kill-switch / dark: disabled or missing creds ⇒ service unavailable.
        if (! RwgService::enabled() || ! RwgService::credentialsConfigured()) {
            return response()->json(['error' => 'service_unavailable'], 503);
        }

        $user = (string) ($request->getUser() ?? '');
        $pass = (string) ($request->getPassword() ?? '');

        // Both compares always run (no early return) to avoid a timing oracle on
        // which half was wrong.
        $userOk = hash_equals(RwgService::basicUser(), $user);
        $passOk = hash_equals(RwgService::basicPass(), $pass);

        if (! ($userOk && $passOk)) {
            return response()->json(['error' => 'unauthorized'], 401)
                ->header('WWW-Authenticate', 'Basic realm="rwg"');
        }

        return $next($request);
    }
}
