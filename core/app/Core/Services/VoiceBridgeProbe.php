<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Juwri\Core\Support\RequestEpoch;

/**
 * P6-09 V-06: liveness inputs for GET /healthz/voice-bridge.
 *
 * Kept as its own class (rather than inline in the controller) so the socket
 * probe is a single seam a test can fake without opening a real connection: the
 * controller takes this via the container.
 */
class VoiceBridgeProbe
{
    /**
     * Is the bridge's Reverb WebSocket accepting TCP connections?
     *
     * A dial is enough: the endpoint only needs to know the worker is up, and a
     * completed WebSocket handshake would add latency and a protocol dependency
     * to a probe an uptime monitor may hit every few seconds. 0.0.0.0 / :: are
     * listen addresses, never dialable — normalise to loopback.
     */
    public function reverbReachable(): bool
    {
        $host = (string) config('agents.voice_bridge.reverb.host', '127.0.0.1');
        $port = (int) config('agents.voice_bridge.reverb.port', 8080);

        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            $host = '127.0.0.1';
        }
        if ($port <= 0 || $port > 65535) {
            return false;
        }

        $timeout = (float) config('agents.voice_bridge.probe_timeout', 1.5);
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout > 0 ? $timeout : 1.5);

        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** Is this process an Octane worker (the bridge's serving mode)? */
    public function octaneRunning(): bool
    {
        return RequestEpoch::inWorker();
    }
}
