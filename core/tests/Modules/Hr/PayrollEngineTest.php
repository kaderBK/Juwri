<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\LeaveRequest;
use Juwri\Modules\Hr\Models\LeaveType;
use Juwri\Modules\Hr\Models\StaffRoleRate;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Payroll\PayrollEngine;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN P-P1: payroll engine — overtime, leave hours, adjustments, approval lock.
 */
class PayrollEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_payroll_suite' => true]);
    }

    private function ownerStaff(float $rate = 20): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Sam', 'email' => 's'.Str::random(5).'@x.co',
            'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => $rate,
        ]);
        StaffRoleRate::create(['staff_id' => $staff->id, 'role' => 'waiter', 'hourly_rate' => $rate]);

        return [$owner, $r, $staff];
    }

    private function clock(int $staffId, int $rid, $in, $out): void
    {
        StaffTimeClock::create(['staff_id' => $staffId, 'store_id' => $rid, 'clock_in_at' => $in, 'clock_out_at' => $out, 'method' => 'manual']);
    }

    public function test_overtime_boundary_math(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff(20);
        StoreOption::updateStoreOption($r->id, 'overtime_weekly_threshold', '40');
        StoreOption::updateStoreOption($r->id, 'overtime_multiplier', '1.5');
        // One week period; 50 worked hours → 40 regular + 10 OT.
        $start = now()->startOfWeek();
        $this->clock($staff->id, $r->id, $start->copy()->addDay()->setTime(8, 0), $start->copy()->addDay()->setTime(18, 0)); // 10h
        $this->clock($staff->id, $r->id, $start->copy()->addDays(2)->setTime(8, 0), $start->copy()->addDays(2)->setTime(18, 0)); // 10h
        $this->clock($staff->id, $r->id, $start->copy()->addDays(3)->setTime(8, 0), $start->copy()->addDays(3)->setTime(18, 0)); // 10h
        $this->clock($staff->id, $r->id, $start->copy()->addDays(4)->setTime(8, 0), $start->copy()->addDays(4)->setTime(18, 0)); // 10h
        $this->clock($staff->id, $r->id, $start->copy()->addDays(5)->setTime(8, 0), $start->copy()->addDays(5)->setTime(18, 0)); // 10h  => 50h

        $run = new PayrollEngine()->build($r->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());
        $item = $run->items->first();
        $this->assertEqualsWithDelta(50.0, (float) $item->worked_hours, 0.1);
        $this->assertEqualsWithDelta(10.0, (float) $item->overtime_hours, 0.1);
        $this->assertEqualsWithDelta(800.0, (float) $item->base_pay, 0.5);        // 40 * 20
        $this->assertEqualsWithDelta(300.0, (float) $item->overtime_pay, 0.5);    // 10 * 20 * 1.5
        $this->assertEqualsWithDelta(1100.0, (float) $item->net, 0.5);
    }

    public function test_paid_leave_capped_to_period_when_leave_extends_past_end(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff(20);
        LeaveType::ensureSeeded($r->id);
        $annual = LeaveType::where('store_id', $r->id)->where('name', 'Annual')->first();
        $start = now()->startOfWeek();
        $end = $start->copy()->addDays(6); // 7-day period (Mon..Sun)
        // Leave runs from the period start well PAST the period end.
        LeaveRequest::create([
            'store_id' => $r->id, 'staff_id' => $staff->id, 'leave_type_id' => $annual->id,
            'from_date' => $start->toDateString(), 'to_date' => $start->copy()->addDays(20)->toDateString(),
            'status' => 'approved',
        ]);

        $run = new PayrollEngine()->build($r->id, $start->toDateString(), $end->toDateString());
        $item = $run->items->first();

        // Only the 7 in-window days count: 7 × 8 = 56h. Before the fix the endOfDay clip
        // on the past-end to_date made Carbon 3's float diff ~7.9999 days → ~64h.
        $this->assertEqualsWithDelta(56.0, (float) $item->leave_hours, 0.01, 'leave hours must be capped to the 7 in-window days');
        $this->assertEqualsWithDelta(1120.0, (float) $item->base_pay, 0.5); // 56 × 20
    }

    public function test_overtime_is_applied_per_week_not_on_the_period_aggregate(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff(20);
        StoreOption::updateStoreOption($r->id, 'overtime_weekly_threshold', '48');
        StoreOption::updateStoreOption($r->id, 'overtime_multiplier', '1.5');

        $start = now()->startOfWeek();               // Monday, ISO week 1
        // Week 1: 60h (6 × 10h, days 0-5). Week 2: 30h (3 × 10h, days 7-9).
        foreach ([0, 1, 2, 3, 4, 5] as $d) {
            $this->clock($staff->id, $r->id, $start->copy()->addDays($d)->setTime(8, 0), $start->copy()->addDays($d)->setTime(18, 0));
        }
        foreach ([7, 8, 9] as $d) {
            $this->clock($staff->id, $r->id, $start->copy()->addDays($d)->setTime(8, 0), $start->copy()->addDays($d)->setTime(18, 0));
        }

        $run = new PayrollEngine()->build($r->id, $start->toDateString(), $start->copy()->addDays(13)->toDateString());
        $item = $run->items->first();

        $this->assertEqualsWithDelta(90.0, (float) $item->worked_hours, 0.1);
        // Week 1 owes 60-48 = 12h OT; week 2 (30h) owes none. Before the fix the period
        // total (90h) was compared to 48×2=96 → 0 overtime.
        $this->assertEqualsWithDelta(12.0, (float) $item->overtime_hours, 0.1, 'overtime is a weekly rule');
        $this->assertEqualsWithDelta(1560.0, (float) $item->base_pay, 0.5);      // 78 regular × 20
        $this->assertEqualsWithDelta(360.0, (float) $item->overtime_pay, 0.5);   // 12 × 20 × 1.5
    }

    public function test_paid_leave_hours_added(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff(20);
        LeaveType::ensureSeeded($r->id);
        $annual = LeaveType::where('store_id', $r->id)->where('name', 'Annual')->first();
        $start = now()->startOfWeek();
        LeaveRequest::create([
            'store_id' => $r->id, 'staff_id' => $staff->id, 'leave_type_id' => $annual->id,
            'from_date' => $start->toDateString(), 'to_date' => $start->copy()->addDay()->toDateString(),
            'status' => 'approved',
        ]);
        // 2 days paid leave × 8h × 20 = 320 base, no worked hours.
        $run = new PayrollEngine()->build($r->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());
        $item = $run->items->first();
        $this->assertEqualsWithDelta(16.0, (float) $item->leave_hours, 0.1);
        $this->assertEqualsWithDelta(320.0, (float) $item->base_pay, 0.5);
    }

    public function test_adjustment_and_approval_lock(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff(20);
        $engine = new PayrollEngine;
        $run = $engine->build($r->id, now()->startOfWeek()->toDateString(), now()->startOfWeek()->addDays(6)->toDateString());
        $item = $run->items->first();

        $engine->adjustItem($item, 'Bonus', 100);
        $this->assertEqualsWithDelta(100.0, (float) $item->fresh()->net, 0.5);

        $engine->submit($run->fresh());
        $engine->approve($run->fresh(), $owner->id);
        $this->assertTrue($run->fresh()->isLocked());

        // Adjusting a locked run throws.
        $this->expectException(ValidationException::class);
        $engine->adjustItem($item->fresh(), 'Late', 50);
    }

    public function test_index_renders_and_tenant_scope(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        [$other, $otherR] = $this->ownerStaff();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.payroll.index').'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('Payroll'));
        $this->actingAs($owner)->get(route('stores.payroll.index').'?store_id='.$otherR->id)->assertForbidden();
    }
}
