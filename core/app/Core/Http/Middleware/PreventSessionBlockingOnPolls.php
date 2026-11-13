<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session blocking (config session.block, on for production hardening) makes
 * StartSession acquire a per-session cache lock for the whole request and hold
 * it until the response is sent, serialising every concurrent request that
 * shares a session cookie. That guard exists for ONE reason: double-submit /
 * lost-update protection on state-changing requests (two rapid "place order"
 * clicks, a form POSTed twice). It has no value on a safe, idempotent read.
 *
 * But it was enabled GLOBALLY (session.block=true for every request), so it also
 * serialises reads — and that is where it bites. High-frequency reads that share
 * a session with a slower request collide: a live-order/notification/KDS/wallet
 * poller firing on a timer, or the geocode proxy (GeoController geo/autocomplete)
 * firing per keystroke and holding the lock across an up-to-4s upstream HTTP call.
 * The second request waits session.block_wait_seconds (10s) and throws
 * LockTimeoutException → a 500. On prod (2026-09-21) this was the entire day's
 * error log: 65 LockTimeoutException, all on /geo/autocomplete.
 *
 * This runs in the GLOBAL stack, before the web group's StartSession, and turns
 * blocking OFF (for this request only — config is per-process) for every SAFE
 * method (GET/HEAD/OPTIONS/TRACE — nothing that mutates state). State-changing
 * methods (POST/PUT/PATCH/DELETE) keep the full double-submit hardening. Scoping
 * by method instead of an allow-list of paths makes the whole "high-frequency
 * read holds the block lock" failure mode impossible, with nothing to maintain:
 * a future slow or per-keystroke GET can't reintroduce the LockTimeoutException
 * 500s. Reads still read and write the session normally (locale, flash, CSRF) —
 * only the concurrency lock is skipped, and concurrent reads were never
 * serialised in a way any code relies on.
 */
class PreventSessionBlockingOnPolls
{
    public function handle(Request $request, Closure $next): Response
    {
        // Safe methods (GET/HEAD/OPTIONS/TRACE) and browser telemetry beacons never
        // place an order or submit a form, so they don't need double-submit
        // serialisation. Skip the block lock for them; match by path because global
        // middleware runs before the router resolves routes.
        if (! $request->isMethodSafe() && ! $request->is('csp-report', 'dash/client-error')) {
            return $next($request);
        }

        config(['session.block' => false]);

        // Octane (P6-39): the session manager keeps the config repository it was built with (the worker's boot copy), not the
        // per-request clone config() writes to, so the line above never reached StartSession and every same-session poll took
        // the lock (8 workers asleep in its 50 ms retry loop, p95 13 s). Set the flag on the manager's own repository for this
        // request only and put it back afterwards: the repository outlives the request.
        $manager = app('session');
        $was = $manager->shouldBlock();
        $setBlock = fn (bool $on) => (fn () => $this->config->set('session.block', $on))->call($manager);
        $setBlock(false);

        try {
            return $next($request);
        } finally {
            $setBlock($was);
        }
    }
}
