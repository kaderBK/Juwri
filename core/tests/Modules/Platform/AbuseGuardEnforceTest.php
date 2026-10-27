<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\AbuseEvent;
use Juwri\Core\Services\AbuseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S6 — Abuse Guard warn→enforce. The effective mode resolves by precedence
 * (admin DB → per-guard env → global default) and defaults to WARN so the
 * feature ships dark.
 */
class AbuseGuardEnforceTest extends TestCase
{
    use RefreshDatabase;

    private function clearAbuseConfig(): void
    {
        foreach (['webhook_in', 'upload', 'public_write', 'signup', 'mcp', 'ai'] as $g) {
            config(['abuse.modes.' . $g => null, 'settings.abuse_' . $g . '_mode' => null]);
        }
    }

    public function test_default_mode_warn_allows_and_logs(): void
    {
        $this->clearAbuseConfig();
        config(['abuse.default_mode' => 'warn']);

        $this->assertSame('warn', AbuseGuard::mode('upload'));
        $this->assertTrue(AbuseGuard::hit('upload', 'store', ['ip' => '1.2.3.4']));
        $this->assertSame(1, AbuseEvent::where('guard', 'upload')->count());
    }

    public function test_global_enforce_denies(): void
    {
        $this->clearAbuseConfig();
        config(['abuse.default_mode' => 'enforce']);

        $this->assertSame('enforce', AbuseGuard::mode('webhook_in'));
        $this->assertFalse(AbuseGuard::hit('webhook_in', 'inbound', ['ip' => '1.2.3.4']));
        $this->assertSame(1, AbuseEvent::where('guard', 'webhook_in')->count());
    }

    public function test_admin_db_override_beats_env_default(): void
    {
        $this->clearAbuseConfig();
        config(['abuse.default_mode' => 'enforce']);
        // Admin explicitly relaxes uploads only.
        config(['settings.abuse_upload_mode' => 'warn']);

        $this->assertSame('warn', AbuseGuard::mode('upload'));
        $this->assertSame('enforce', AbuseGuard::mode('signup'));
    }

    public function test_per_guard_env_override_beats_default(): void
    {
        $this->clearAbuseConfig();
        config(['abuse.default_mode' => 'warn', 'abuse.modes.webhook_in' => 'enforce']);

        $this->assertSame('enforce', AbuseGuard::mode('webhook_in'));
        $this->assertSame('warn', AbuseGuard::mode('upload'));
    }

    public function test_off_short_circuits_without_logging(): void
    {
        $this->clearAbuseConfig();
        config(['abuse.default_mode' => 'off']);

        $this->assertTrue(AbuseGuard::hit('ai', 'generate'));
        $this->assertSame(0, AbuseEvent::count());
    }
}
