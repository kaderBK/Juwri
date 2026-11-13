<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\AbuseEvent;
use Juwri\Core\Services\AbuseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbuseGuardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mode_is_warn(): void
    {
        $this->assertSame(AbuseGuard::WARN, AbuseGuard::mode('ai'));
    }

    public function test_off_mode_allows_and_logs_nothing(): void
    {
        config(['settings.abuse_ai_mode' => 'off']);

        $this->assertTrue(AbuseGuard::hit('ai', 'generate'));
        $this->assertSame(0, AbuseEvent::count());
    }

    public function test_warn_mode_logs_and_allows(): void
    {
        config(['settings.abuse_ai_mode' => 'warn']);

        $this->assertTrue(AbuseGuard::hit('ai', 'generate', ['meta' => ['x' => 1]]));
        $this->assertSame(1, AbuseEvent::count());
        $this->assertSame('warn', AbuseEvent::first()->mode);
    }

    public function test_enforce_mode_logs_and_denies(): void
    {
        config(['settings.abuse_ai_mode' => 'enforce']);

        $this->assertFalse(AbuseGuard::hit('ai', 'generate'));
        $this->assertSame(1, AbuseEvent::count());
        $this->assertSame('enforce', AbuseEvent::first()->mode);
    }

    public function test_value_reads_setting_with_default(): void
    {
        $this->assertSame(200, AbuseGuard::value('ai', 'user_daily_cap', 200));

        config(['settings.abuse_ai_user_daily_cap' => 50]);
        $this->assertSame(50, AbuseGuard::value('ai', 'user_daily_cap', 200));
    }

    public function test_recent_count(): void
    {
        config(['settings.abuse_signup_mode' => 'warn']);
        AbuseGuard::hit('signup', 'register');
        AbuseGuard::hit('signup', 'register');

        $this->assertSame(2, AbuseGuard::recentCount('signup'));
        $this->assertSame(0, AbuseGuard::recentCount('ai'));
    }
}
