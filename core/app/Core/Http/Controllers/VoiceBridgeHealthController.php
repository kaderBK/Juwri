<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Juwri\Core\Services\VoiceBridgeProbe;

/**
 * P6-09 V-06: GET /healthz/voice-bridge — readiness probe for the Cloud-only
 * voice bridge, sat next to /healthz.
 *
 * Payload: {ok, bridge:'enabled'|'disabled', reverb:bool, octane:bool}. The
 * bridge is OFF by default, so Community/Enterprise self-host answers 200 with
 * bridge=disabled and stays green; only an enabled bridge whose Reverb socket
 * cannot be dialled returns 503. No secret and no tenant data is ever read or
 * echoed here (the payload is four fixed keys).
 */
class VoiceBridgeHealthController extends Controller
{
    public function check(VoiceBridgeProbe $probe): JsonResponse
    {
        $enabled = (bool) config('agents.voice_bridge.enabled', false);

        if (! $enabled) {
            return response()->json([
                'ok' => true,
                'bridge' => 'disabled',
                'reverb' => false,
                'octane' => $probe->octaneRunning(),
            ]);
        }

        $reverb = $probe->reverbReachable();

        return response()->json([
            'ok' => $reverb,
            'bridge' => 'enabled',
            'reverb' => $reverb,
            'octane' => $probe->octaneRunning(),
        ], $reverb ? 200 : 503);
    }
}
