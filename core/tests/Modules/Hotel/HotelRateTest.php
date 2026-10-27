<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use Juwri\Modules\Hotel\Models\RatePlan;
use Juwri\Modules\Hotel\Models\RoomRate;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Hotel\Services\Hotel\RateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HPMS A3 — RateService: nightly resolution, plan modifiers, half-open nights,
 * default-plan auto-create (SPEC §2).
 */
class HotelRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 9); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    private function type(float $base = 100, int $rid = 1): RoomType
    {
        return RoomType::create(['store_id' => $rid, 'name' => 'Standard', 'base_rate' => $base]);
    }

    public function test_base_rate_used_when_no_override(): void
    {
        $type = $this->type(120);
        $q = RateService::quote($type, null, '2026-09-01', '2026-09-04'); // 3 nights

        $this->assertCount(3, $q['nights']);
        $this->assertSame(360.0, $q['total']);
        $this->assertSame('2026-09-01', $q['nights'][0]['date']);
        $this->assertSame(120.0, $q['nights'][0]['price']);
        $this->assertSame('USD', $q['currency']);
    }

    public function test_half_open_nights_exclude_checkout_day(): void
    {
        $type = $this->type(100);
        // 1-night stay: only the check-in date is charged.
        $q = RateService::quote($type, null, '2026-09-01', '2026-09-02');
        $this->assertCount(1, $q['nights']);
        $this->assertSame(100.0, $q['total']);
    }

    public function test_per_date_override_beats_base(): void
    {
        $type = $this->type(100);
        // Seasonal / DOW override for the middle night only.
        RoomRate::create(['store_id' => 1, 'room_type_id' => $type->id, 'rate_plan_id' => null, 'date' => '2026-09-02', 'price' => 175]);

        $q = RateService::quote($type, null, '2026-09-01', '2026-09-04');
        $this->assertSame(100.0, $q['nights'][0]['price']);
        $this->assertSame(175.0, $q['nights'][1]['price']); // override
        $this->assertSame(100.0, $q['nights'][2]['price']);
        $this->assertSame(375.0, $q['total']);
    }

    public function test_percent_modifier_off_parent_base(): void
    {
        $type = $this->type(200);
        $plan = RatePlan::create(['store_id' => 1, 'name' => 'Advance -15%', 'modifier_type' => 'percent', 'modifier_value' => -15]);

        $q = RateService::quote($type, $plan, '2026-09-01', '2026-09-03'); // 2 nights
        $this->assertSame(170.0, $q['nights'][0]['price']); // 200 * 0.85
        $this->assertSame(340.0, $q['total']);
    }

    public function test_fixed_modifier_adds_amount(): void
    {
        $type = $this->type(100);
        $plan = RatePlan::create(['store_id' => 1, 'name' => 'Breakfast +20', 'modifier_type' => 'fixed', 'modifier_value' => 20]);

        $q = RateService::quote($type, $plan, '2026-09-01', '2026-09-02');
        $this->assertSame(120.0, $q['nights'][0]['price']);
    }

    public function test_plan_per_date_override_wins_over_modifier(): void
    {
        $type = $this->type(100);
        $plan = RatePlan::create(['store_id' => 1, 'name' => 'Flex +10%', 'modifier_type' => 'percent', 'modifier_value' => 10]);
        // Explicit plan price for one date overrides the modifier path entirely.
        RoomRate::create(['store_id' => 1, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'date' => '2026-09-01', 'price' => 95]);

        $q = RateService::quote($type, $plan, '2026-09-01', '2026-09-03');
        $this->assertSame(95.0, $q['nights'][0]['price']);  // explicit override
        $this->assertSame(110.0, $q['nights'][1]['price']); // 100 * 1.10 modifier
    }

    public function test_child_plan_applies_over_parent_resolved_price(): void
    {
        $type = $this->type(100);
        $parent = RatePlan::create(['store_id' => 1, 'name' => 'Corporate -10%', 'modifier_type' => 'percent', 'modifier_value' => -10]);
        $child = RatePlan::create(['store_id' => 1, 'name' => 'Corp NonRef -5', 'parent_id' => $parent->id, 'modifier_type' => 'fixed', 'modifier_value' => -5]);

        $q = RateService::quote($type, $child, '2026-09-01', '2026-09-02');
        // parent: 100 * 0.90 = 90; child fixed -5 = 85
        $this->assertSame(85.0, $q['nights'][0]['price']);
    }

    public function test_ensure_default_plan_is_idempotent(): void
    {
        $a = RateService::ensureDefaultPlan(9);
        $b = RateService::ensureDefaultPlan(9);

        $this->assertSame($a->id, $b->id);
        $this->assertSame('Standard', $a->name);
        $this->assertNull($a->modifier_type);
        $this->assertSame(1, RatePlan::forRestaurant(9)->count());
    }
}
