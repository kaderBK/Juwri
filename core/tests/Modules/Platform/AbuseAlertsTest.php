<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\AbuseEvent;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abuse Guard P6 (6.2): abuse signals wired into PlatformMetrics::evaluateAlerts.
 */
class AbuseAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_spike_alert(): void
    {
        config(['settings.abuse_alert_signups_per_hour' => 2]);
        for ($i = 0; $i < 3; $i++) {
            AbuseEvent::create(['guard' => 'signup', 'action' => 'velocity', 'mode' => 'warn', 'created_at' => now()]);
        }

        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertTrue(collect($alerts)->contains(fn ($a) => str_contains($a, 'signup spike')));
    }

    public function test_single_user_flood_alert(): void
    {
        config(['settings.abuse_alert_user_events_per_day' => 3]);
        for ($i = 0; $i < 4; $i++) {
            AbuseEvent::create(['guard' => 'ai_spend', 'action' => 'user_daily_cap', 'user_id' => 77, 'mode' => 'warn', 'created_at' => now()]);
        }

        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertTrue(collect($alerts)->contains(fn ($a) => str_contains($a, 'user 77')));
    }

    public function test_heavy_tenant_alert(): void
    {
        config(['settings.abuse_heavy_tenant_pct' => 10]);
        \Juwri\Core\Models\TenantUsageDaily::bump(1, 'requests_public', 90);
        \Juwri\Core\Models\TenantUsageDaily::bump(2, 'requests_public', 10);

        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertTrue(collect($alerts)->contains(fn ($a) => str_contains($a, 'heavy tenant: user 1')));
    }

    public function test_no_alerts_when_quiet(): void
    {
        config(['settings.abuse_alert_signups_per_hour' => 30, 'settings.abuse_alert_user_events_per_day' => 50]);
        $alerts = PlatformMetrics::evaluateAlerts();
        $this->assertFalse(collect($alerts)->contains(fn ($a) => str_contains($a, 'signup spike')));
    }
}
