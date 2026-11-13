<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\TestCase;

class SchedulingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SchedulingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SchedulingService;
    }

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111',
            'title' => 'R',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function set(int $rid, array $kv): void
    {
        foreach ($kv as $k => $v) {
            StoreOption::updateStoreOption($rid, $k, $v);
        }
    }

    /** Disabled by default → no slots, ASAP validation passes, scheduled rejected. */
    public function test_disabled_by_default(): void
    {
        $r = $this->storeFixture();
        $this->assertFalse($this->svc->enabled($r->id));
        $this->assertSame([], $this->svc->slotsFor($r->id, null, 'takeaway', now()->format('Y-m-d')));
        $this->assertNull($this->svc->validate($r->id, null, 'takeaway', null)); // ASAP ok

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->validate($r->id, null, 'takeaway', now()->addHours(2)->format('Y-m-d H:i'));
    }

    /** Enabled + no branch hours → slots generated across the day on the interval grid. */
    public function test_generates_slots_when_enabled(): void
    {
        $r = $this->storeFixture();
        $this->set($r->id, [
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 0,
            'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00', 'UTC'));
        $slots = $this->svc->slotsFor($r->id, null, 'takeaway', '2026-08-01');
        Carbon::setTestNow();

        // 00:00..23:30 at 30-min = 48 slots.
        $this->assertCount(48, $slots);
        $this->assertSame('00:00', $slots[0]['label']);
        $this->assertSame('23:30', $slots[47]['label']);
    }

    /** Lead time hides slots inside the lead window. */
    public function test_lead_time_excludes_near_slots(): void
    {
        $r = $this->storeFixture();
        $this->set($r->id, [
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 15,
            'scheduling_lead_minutes' => 60,
            'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));
        $slots = $this->svc->slotsFor($r->id, null, 'takeaway', '2026-08-01');
        Carbon::setTestNow();

        // Earliest allowed = 13:00; nothing before it.
        $labels = array_column($slots, 'label');
        $this->assertNotContains('12:45', $labels);
        $this->assertContains('13:00', $labels);
    }

    /** Branch hours (incl. closed day) constrain slots. */
    public function test_branch_hours_and_closed_day(): void
    {
        $r = $this->storeFixture();
        $this->set($r->id, ['allow_order_scheduling' => '1', 'scheduling_slot_interval' => 60, 'scheduling_lead_minutes' => 0]);

        $branch = Branch::create([
            'store_id' => $r->id,
            'name' => 'Main',
            'active' => true,
            'accepting_orders' => true,
            'timezone' => 'UTC',
            'hours' => [
                'sat' => ['open' => '10:00', 'close' => '14:00', 'closed' => false],
                'sun' => ['open' => '10:00', 'close' => '14:00', 'closed' => true],
            ],
        ]);

        // 2026-08-01 is a Saturday.
        Carbon::setTestNow(Carbon::parse('2026-08-01 06:00', 'UTC'));
        $sat = $this->svc->slotsFor($r->id, $branch, 'takeaway', '2026-08-01');
        $sun = $this->svc->slotsFor($r->id, $branch, 'takeaway', '2026-08-02');
        Carbon::setTestNow();

        $this->assertSame(['10:00', '11:00', '12:00', '13:00'], array_column($sat, 'label'));
        $this->assertSame([], $sun); // closed
    }

    /** Capacity marks a slot full once enough scheduled orders sit in it. */
    public function test_slot_capacity_marks_full(): void
    {
        $r = $this->storeFixture();
        $this->set($r->id, [
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 60,
            'scheduling_lead_minutes' => 0,
            'scheduling_slot_capacity' => 2,
            'timezone' => 'UTC',
        ]);

        // Two orders already scheduled into the 12:00 slot.
        foreach (range(1, 2) as $i) {
            Order::create([
                'store_id' => $r->id,
                'status' => 'new',
                'type' => 'takeaway',
                'customer_name' => 'C',
                'scheduled_at' => Carbon::parse('2026-08-01 12:30', 'UTC'),
                'created_at' => now(),
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-08-01 06:00', 'UTC'));
        $slots = $this->svc->slotsFor($r->id, null, 'takeaway', '2026-08-01');
        $noon = collect($slots)->firstWhere('label', '12:00');
        $one = collect($slots)->firstWhere('label', '13:00');
        Carbon::setTestNow();

        $this->assertTrue($noon['full']);
        $this->assertFalse($one['full']);
    }

    /** validate() rejects full slots, past times, and beyond-horizon; accepts a good one. */
    public function test_validate_rules(): void
    {
        $r = $this->storeFixture();
        $this->set($r->id, [
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30,
            'scheduling_max_days' => 3,
            'scheduling_prep_minutes' => 20,
            'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));

        // Good: 13:00 (aligned, beyond lead).
        $ok = $this->svc->validate($r->id, null, 'takeaway', '2026-08-01 13:00');
        $this->assertInstanceOf(Carbon::class, $ok);
        $this->assertSame('2026-08-01 13:00', $ok->format('Y-m-d H:i'));
        // fire_at = scheduled - 20m.
        $this->assertSame('2026-08-01 12:40', $this->svc->computeFireAt($r->id, $ok)->format('Y-m-d H:i'));

        // Too soon (inside lead).
        try {
            $this->svc->validate($r->id, null, 'takeaway', '2026-08-01 12:15');
            $this->fail('expected lead-time rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        // Beyond horizon.
        try {
            $this->svc->validate($r->id, null, 'takeaway', '2026-08-10 13:00');
            $this->fail('expected horizon rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        Carbon::setTestNow();
    }

    /**
     * Horizon is day-granular: the WHOLE of the last bookable day (today + maxDays) is
     * offered by the picker, so validate() must accept a slot on it that is later than
     * the current wall-clock time. The old to-the-second cutoff rejected exactly those
     * "shown but not bookable" slots.
     */
    public function test_last_horizon_day_is_fully_bookable(): void
    {
        $r = $this->storeFixture();
        $this->set($r->id, [
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30,
            'scheduling_max_days' => 3,
            'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));

        // Last horizon day is 2026-08-04. A 20:00 slot there is LATER than now's 12:00
        // clock time → the old `at > now+maxDays` check wrongly rejected it.
        $ok = $this->svc->validate($r->id, null, 'takeaway', '2026-08-04 20:00');
        $this->assertInstanceOf(Carbon::class, $ok, 'the whole last horizon day is bookable');
        $this->assertSame('2026-08-04 20:00', $ok->format('Y-m-d H:i'));

        // The day AFTER the horizon (2026-08-05) is still rejected.
        try {
            $this->svc->validate($r->id, null, 'takeaway', '2026-08-05 08:00');
            $this->fail('expected a rejection past the horizon day');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        Carbon::setTestNow();
    }

    /** Per-type enablement: scheduling_types limits which order types qualify. */
    public function test_type_restriction(): void
    {
        $r = $this->storeFixture();
        $this->set($r->id, [
            'allow_order_scheduling' => '1',
            'scheduling_types' => 'takeaway,delivery',
        ]);

        $this->assertTrue($this->svc->enabled($r->id, 'takeaway'));
        $this->assertTrue($this->svc->enabled($r->id, 'delivery'));
        $this->assertFalse($this->svc->enabled($r->id, 'on-table'));
    }
}
