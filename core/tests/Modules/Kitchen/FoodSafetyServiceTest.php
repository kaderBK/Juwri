<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use Juwri\Modules\Kitchen\Models\FoodSafetyCheck;
use Juwri\Modules\Kitchen\Models\FoodSafetyLog;
use Juwri\Modules\Kitchen\Services\FoodSafety\FoodSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B10-P1: food-safety service.
 */
class FoodSafetyServiceTest extends TestCase
{
    use RefreshDatabase;

    private FoodSafetyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 2); // P1-04 5b: fixture store ids must be real stores
        $this->svc = new FoodSafetyService();
    }

    private function check(int $rid, array $over = []): FoodSafetyCheck
    {
        return FoodSafetyCheck::create(array_merge([
            'store_id' => $rid, 'name' => 'Fridge', 'kind' => 'temperature',
            'target_min' => 1, 'target_max' => 5, 'frequency' => 'daily', 'active' => true,
        ], $over));
    }

    public function test_due_today_excludes_logged_checks(): void
    {
        $a = $this->check(1);
        $b = $this->check(1, ['name' => 'Freezer', 'target_min' => -20, 'target_max' => -15]);

        $this->assertCount(2, $this->svc->dueToday(1));

        $this->svc->log($a, null, 3.0);
        $due = $this->svc->dueToday(1);
        $this->assertCount(1, $due);
        $this->assertSame($b->id, $due->first()->id);
    }

    public function test_breach_flagged_out_of_range(): void
    {
        $c = $this->check(1); // 1..5

        $this->assertFalse($this->svc->log($c, null, 3.0)->breach);   // in range
        $this->assertTrue($this->svc->log($c, null, 9.0)->breach);    // above max
        $this->assertTrue($this->svc->log($c, null, -2.0)->breach);   // below min
    }

    public function test_incident_always_breaches(): void
    {
        $log = $this->svc->logIncident(1, null, 'Spill near prep');
        $this->assertSame('incident', $log->kind);
        $this->assertTrue($log->breach);
        $this->assertSame('Spill near prep', $log->note);
    }

    public function test_due_is_tenant_scoped(): void
    {
        $this->check(1);
        $this->check(2, ['name' => 'Other']);
        $this->assertCount(1, $this->svc->dueToday(1));
        $this->assertCount(1, $this->svc->dueToday(2));
    }

    public function test_compliance_rate(): void
    {
        // 1 daily check that has existed the whole window; log it on 15 distinct days.
        $c = $this->check(1);
        $c->forceFill(['created_at' => now()->subDays(40)])->save();
        for ($i = 0; $i < 15; $i++) {
            FoodSafetyLog::create([
                'check_id' => $c->id, 'store_id' => 1, 'kind' => 'temperature', 'value' => 3,
                'breach' => false, 'logged_at' => now()->copy()->subDays($i),
            ]);
        }
        // 15 completed / (1 check × 30 days) = 0.5
        $this->assertEqualsWithDelta(0.5, $this->svc->complianceRate(1, 30), 0.001);
    }

    public function test_compliance_does_not_penalise_a_brand_new_check(): void
    {
        // A check added today and logged today: expected days is bounded by age (1),
        // not the full 30-day window — so it reads 100%, not ~3%.
        $c = $this->check(1);
        FoodSafetyLog::create([
            'check_id' => $c->id, 'store_id' => 1, 'kind' => 'temperature', 'value' => 3,
            'breach' => false, 'logged_at' => now(),
        ]);
        $this->assertEqualsWithDelta(1.0, $this->svc->complianceRate(1, 30), 0.001);
    }
}
