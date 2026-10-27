<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Bridge;

use Illuminate\Http\Request;
use Juwri\Modules\Agents\Models\PhoneLine;
use Symfony\Component\HttpFoundation\Response;

/**
 * P6-09 V-03: the voice bridge contract. The bridge route authenticates the
 * connection (signed token → provider, line, store, call) and re-checks the
 * entitlement BEFORE calling accept(); an implementation never sees an
 * unauthenticated connection and must take the tenant only from $line.
 * Mode A (CPaaS text relay) and Mode B (realtime speech) both implement this.
 */
interface VoiceBridge
{
    /**
     * Accept an authenticated bridge connection (WebSocket upgrade or handoff to a worker).
     *
     * @param  array{p: string, l: int, s: int, c: string, exp: int, n: string}  $claims
     */
    public function accept(Request $request, string $provider, PhoneLine $line, array $claims): Response;
}
