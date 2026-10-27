<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Juwri\Core\Services\VoiceBridgeProbe;
use Tests\TestCase;

/**
 * P6-09 V-06: GET /healthz/voice-bridge. Off by default so a Community install
 * stays green; an enabled bridge whose Reverb socket is unreachable returns 503.
 * The socket probe is faked — no real connection is opened.
 */
class VoiceBridgeHealthTest extends TestCase
{
    /** Bind a probe with fixed answers, so no socket is dialled in the test. */
    private function fakeProbe(bool $reverb, bool $octane = false): void
    {
        $this->app->instance(VoiceBridgeProbe::class, new class($reverb, $octane) extends VoiceBridgeProbe
        {
            public function __construct(private bool $reverb, private bool $octane) {}

            public function reverbReachable(): bool
            {
                return $this->reverb;
            }

            public function octaneRunning(): bool
            {
                return $this->octane;
            }
        });
    }

    public function test_disabled_bridge_returns_200_and_stays_green(): void
    {
        config(['agents.voice_bridge.enabled' => false]);
        $this->fakeProbe(false);

        $this->getJson('/healthz/voice-bridge')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'bridge' => 'disabled',
                'reverb' => false,
                'octane' => false,
            ]);
    }

    public function test_enabled_and_reverb_reachable_returns_200(): void
    {
        config(['agents.voice_bridge.enabled' => true]);
        $this->fakeProbe(true, true);

        $this->getJson('/healthz/voice-bridge')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'bridge' => 'enabled',
                'reverb' => true,
                'octane' => true,
            ]);
    }

    public function test_enabled_and_reverb_unreachable_returns_503(): void
    {
        config(['agents.voice_bridge.enabled' => true]);
        $this->fakeProbe(false);

        $this->getJson('/healthz/voice-bridge')
            ->assertStatus(503)
            ->assertJson([
                'ok' => false,
                'bridge' => 'enabled',
                'reverb' => false,
            ]);
    }

    public function test_probe_needs_no_authentication(): void
    {
        config(['agents.voice_bridge.enabled' => true]);
        $this->fakeProbe(true);

        // A plain guest request (no session, no token) is answered like any other.
        $this->getJson('/healthz/voice-bridge')->assertOk();
    }

    public function test_octane_flag_reflects_the_worker_process(): void
    {
        config(['agents.voice_bridge.enabled' => true]);
        $this->app->instance(VoiceBridgeProbe::class, new class extends VoiceBridgeProbe
        {
            public function reverbReachable(): bool
            {
                return true;
            }
        });

        $_SERVER['LARAVEL_OCTANE'] = '1';
        try {
            $this->getJson('/healthz/voice-bridge')->assertOk()->assertJson(['octane' => true]);
        } finally {
            unset($_SERVER['LARAVEL_OCTANE']);
        }
    }

    public function test_payload_contains_no_secret_or_tenant_data(): void
    {
        config([
            'agents.voice_bridge.enabled' => true,
            'agents.voice_bridge.public_url' => 'https://bridge.example.com',
            'services.telephony.bridge_secret' => 'super-secret-bridge-key',
            'services.telephony.ws_base' => 'wss://bridge.example.com',
        ]);
        $this->fakeProbe(true);

        $body = $this->getJson('/healthz/voice-bridge')->assertOk()->getContent();

        $this->assertStringNotContainsString('super-secret-bridge-key', $body);
        $this->assertStringNotContainsString('bridge.example.com', $body);
        // Exactly the four documented keys — nothing else can leak.
        $this->assertSame(['ok', 'bridge', 'reverb', 'octane'], array_keys((array) json_decode($body, true)));
    }
}
