<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Controllers\Admin\AbuseGuardController;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prod-findings P4: detection that catches a runaway tenant on day one, not day
 * ten. The existing heavy-tenant alert only fires once a tenant is already >10% of
 * the box; the rate-of-change signal flags a tenant whose request volume today is a
 * large multiple of its own trailing baseline (a 40 → 8,000/day jump), below that
 * share. Also asserts the new dashboard_poll guard is registered.
 */
class AbuseSurgeAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep evaluateAlerts() hermetic — the .env-health probe is irrelevant here.
        config(['settings.admin_env_health_alert' => false]);
    }

    private function seedUsage(int $userId, string $date, int $value): void
    {
        TenantUsageDaily::create([
            'date' => $date, 'user_id' => $userId, 'metric' => 'requests_public', 'value' => $value,
        ]);
    }

    public function test_request_surge_flags_a_tenant_spiking_over_its_baseline(): void
    {
        $u = User::factory()->create();
        for ($i = 7; $i >= 1; $i--) {
            $this->seedUsage($u->id, now()->subDays($i)->toDateString(), 40); // ~40/day baseline
        }
        $this->seedUsage($u->id, now()->toDateString(), 8000); // today: 200x the baseline

        $alerts = PlatformMetrics::evaluateAlerts();

        $this->assertTrue(
            collect($alerts)->contains(fn ($a) => str_contains($a, 'request surge') && str_contains($a, 'user '.$u->id)),
            'a 40 → 8,000/day jump must raise a request-surge alert (falsify: drop the surge block → fails)'
        );
    }

    public function test_flat_tenant_does_not_surge(): void
    {
        $u = User::factory()->create();
        for ($i = 7; $i >= 0; $i--) {
            $this->seedUsage($u->id, now()->subDays($i)->toDateString(), 4000); // steady, no jump
        }

        $alerts = PlatformMetrics::evaluateAlerts();

        $this->assertFalse(
            collect($alerts)->contains(fn ($a) => str_contains($a, 'request surge')),
            'a tenant with flat volume must NOT surge'
        );
    }

    public function test_dashboard_poll_guard_is_registered(): void
    {
        $this->assertArrayHasKey('dashboard_poll', AbuseRateLimit::DEFAULTS);
        $this->assertArrayHasKey('dashboard_poll', AbuseGuardController::LIMITERS);
    }
}
