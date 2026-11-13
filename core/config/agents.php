<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P6-09 voice bridge (Cloud only). The bridge is the always-on WebSocket/Octane worker that carries a
 * phone call between the carrier and AgentChatService::forVoice. Self-hosted Community/Enterprise does
 * NOT run it (no carrier contracts, no always-on workers), so `enabled` defaults to off and the
 * /healthz/voice-bridge probe stays green on every installation that does not turn it on.
 *
 * VOICE_BRIDGE_PUBLIC_URL is where the carrier sends callers: the public wss origin of the bridge
 * (TLS-terminated by the proxy in front). No secret lives here; the signed per-call token is minted in
 * VoiceWebhookController and verified in the bridge (see services.telephony.* for its signing key).
 */
return [
    'voice_bridge' => [
        // Master switch. Off unless juwri Cloud (or an operator testing the role) sets it.
        'enabled' => (bool) env('VOICE_BRIDGE_ENABLED', false),

        // Public wss origin handed to the carrier, e.g. https://bridge.juwri.com.
        'public_url' => env('VOICE_BRIDGE_PUBLIC_URL'),

        // Per-line concurrency ceiling (SPEC §6): new calls past this get unavailable()/handoff, never a drop.
        'max_concurrent_calls_per_line' => (int) env('VOICE_BRIDGE_MAX_CONCURRENT_CALLS', 3),

        // Reverb (the bridge's own WebSocket) liveness probe used by /healthz/voice-bridge.
        'reverb' => [
            'host' => env('VOICE_BRIDGE_REVERB_HOST', env('REVERB_SERVER_HOST', '127.0.0.1')),
            'port' => (int) env('VOICE_BRIDGE_REVERB_PORT', env('REVERB_SERVER_PORT', 8080)),
        ],

        // Seconds to wait for the Reverb TCP connect before calling it unreachable.
        'probe_timeout' => (float) env('VOICE_BRIDGE_PROBE_TIMEOUT', 1.5),
    ],

    // P6-33: hourly phone-agent error budget per store (voice:error-budget-check). Over budget = platform alert + store push.
    'error_budget' => [
        'failed_call_rate' => (float) env('VOICE_BUDGET_FAILED_RATE', 0.05),   // > 5% failed calls in the hour...
        'min_calls' => (int) env('VOICE_BUDGET_MIN_CALLS', 20),                // ...only once the hour had this many calls
        'handoff_failures' => (int) env('VOICE_BUDGET_HANDOFF_FAILURES', 0),   // alert when above this (0 = any failure)
        'signature_rejects' => (int) env('VOICE_BUDGET_SIGNATURE_REJECTS', 10),
        'callback_errors' => (int) env('VOICE_BUDGET_CALLBACK_ERRORS', 5),
        'latency_p95_ms' => (int) env('VOICE_BUDGET_LATENCY_P95_MS', 3000),
    ],
];
