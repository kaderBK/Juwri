<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Rota\PayrollService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B7-P3: payroll report math.
 */
class PayrollServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $r;

    private StaffMember $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $this->r->id, 'name' => 'Sam', 'role' => 'waiter',
            'hourly_rate' => 10, 'email' => 's'.Str::random(4).'@x.com', 'password' => Hash::make('x'), 'active' => 1,
        ]);
    }

    private function clock(string $in, ?string $out): void
    {
        StaffTimeClock::create([
            'staff_id' => $this->staff->id, 'store_id' => $this->r->id,
            'clock_in_at' => $in, 'clock_out_at' => $out,
        ]);
    }

    public function test_report_computes_hours_pay_and_tips(): void
    {
        // Scheduled shift 8h.
        StaffShift::create(['store_id' => $this->r->id, 'staff_id' => $this->staff->id, 'starts_at' => '2026-07-13 09:00', 'ends_at' => '2026-07-13 17:00']);
        // Overnight worked pair = 4h.
        $this->clock('2026-07-13 22:00', '2026-07-14 02:00');
        // A second finished pair = 3h.
        $this->clock('2026-07-15 09:00', '2026-07-15 12:00');
        // Open pair (still clocked in) → flagged, not counted.
        $this->clock('2026-07-16 09:00', null);
        // Tips attributed to this staff. PayrollService::tips() counts tips only on
        // PAID orders (is_paid=1) — tips on unpaid/cancelled orders are not owed — so
        // these must be marked paid to be summed.
        Order::create(['store_id' => $this->r->id, 'type' => 'dinein', 'status' => 'completed', 'served_by_staff_id' => $this->staff->id, 'tip_amount' => 5])
            ->forceFill(['created_at' => '2026-07-13 20:00', 'is_paid' => 1])->save();
        Order::create(['store_id' => $this->r->id, 'type' => 'dinein', 'status' => 'completed', 'served_by_staff_id' => $this->staff->id, 'tip_amount' => 3])
            ->forceFill(['created_at' => '2026-07-15 20:00', 'is_paid' => 1])->save();

        $report = new PayrollService()->report($this->r->id, '2026-07-01', '2026-07-31');
        $row = $report['rows'][0];

        $this->assertSame(8.0, $row['scheduled_hours']);
        $this->assertSame(7.0, $row['worked_hours']); // 4 + 3
        $this->assertSame(1, $row['open_clock_pairs']);
        $this->assertSame(10.0, $row['rate']);
        $this->assertSame(70.0, $row['pay']); // 7h × 10
        $this->assertSame(8.0, $row['tips']); // 5 + 3
    }

    public function test_tips_are_staff_and_period_scoped(): void
    {
        // Out-of-period tip is excluded.
        Order::create(['store_id' => $this->r->id, 'type' => 'dinein', 'status' => 'completed', 'served_by_staff_id' => $this->staff->id, 'tip_amount' => 99])
            ->forceFill(['created_at' => '2026-06-01 20:00'])->save();

        $report = new PayrollService()->report($this->r->id, '2026-07-01', '2026-07-31');
        $this->assertSame(0.0, $report['rows'][0]['tips']);
    }
}
