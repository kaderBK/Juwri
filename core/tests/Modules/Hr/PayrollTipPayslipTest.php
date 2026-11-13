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
use Juwri\Modules\Hr\Models\PayrollItem;
use Juwri\Modules\Hr\Models\PayrollRun;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Payroll\PayrollEngine;
use Juwri\Modules\Hr\Services\Payroll\TipPoolService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN P-P2: tip pooling (3 methods, sum-exact), payslip, mark-paid, bank export.
 */
class PayrollTipPayslipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_payroll_suite' => true]);
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
    }

    private function scenario(): array
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
        $start = now()->startOfWeek();
        $a = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'A', 'email' => 'a'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => 10]);
        $b = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'B', 'email' => 'b'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => 10]);
        // A worked 30h, B worked 10h (via time clock).
        StaffTimeClock::create(['staff_id' => $a->id, 'store_id' => $r->id, 'clock_in_at' => $start->copy()->addDay()->setTime(8, 0), 'clock_out_at' => $start->copy()->addDay()->setTime(8, 0)->addHours(30), 'method' => 'manual']);
        StaffTimeClock::create(['staff_id' => $b->id, 'store_id' => $r->id, 'clock_in_at' => $start->copy()->addDay()->setTime(8, 0), 'clock_out_at' => $start->copy()->addDay()->setTime(8, 0)->addHours(10), 'method' => 'manual']);
        // 300 in tips within the period.
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'tip_amount' => 300, 'created_at' => $start->copy()->addDays(2)]);

        $run = new PayrollEngine()->build($r->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());

        return [$owner, $r, $run, $a, $b];
    }

    public function test_pool_by_hours_is_sum_exact(): void
    {
        [$owner, $r, $run, $a, $b] = $this->scenario();
        $alloc = new TipPoolService()->apply($run, 'by_hours');
        $this->assertEqualsWithDelta(300.0, array_sum($alloc), 0.001);
        // A (30h) gets 3x B (10h): 225 / 75.
        $itemA = $run->items()->where('staff_id', $a->id)->first();
        $itemB = $run->items()->where('staff_id', $b->id)->first();
        $this->assertEqualsWithDelta(225.0, (float) $itemA->tips, 0.01);
        $this->assertEqualsWithDelta(75.0, (float) $itemB->tips, 0.01);
    }

    public function test_tip_pool_never_allocates_a_negative_share(): void
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
        $start = now()->startOfWeek();
        $run = PayrollRun::create([
            'store_id' => $r->id, 'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->addDays(6)->toDateString(), 'status' => 'draft',
        ]);
        // 7 equal-weight items and a tiny 0.05 pool: 0.05/7 rounds to 0.01 each, so the
        // old remainder-to-last wrote round(0.05 - 6×0.01) = -0.01 onto the last item.
        for ($i = 0; $i < 7; $i++) {
            $s = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'S'.$i, 'email' => 's'.$i.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => 10]);
            PayrollItem::create([
                'payroll_run_id' => $run->id, 'staff_id' => $s->id, 'scheduled_hours' => 0, 'worked_hours' => 1,
                'leave_hours' => 0, 'overtime_hours' => 0, 'rate' => 10, 'base_pay' => 0, 'overtime_pay' => 0,
                'tips' => 0, 'bonuses' => 0, 'deductions' => 0, 'adjustments' => [], 'net' => 0,
            ]);
        }

        $alloc = new TipPoolService()->preview($run->fresh('items'), 'by_hours', [], 0.05);

        $this->assertCount(7, $alloc);
        $this->assertGreaterThanOrEqual(0.0, min($alloc), 'no tip share may be negative');
        $this->assertEqualsWithDelta(0.05, array_sum($alloc), 0.001, 'shares must sum exactly to the pool');
    }

    public function test_pool_custom_percent(): void
    {
        [$owner, $r, $run, $a, $b] = $this->scenario();
        $itemA = $run->items()->where('staff_id', $a->id)->first();
        $itemB = $run->items()->where('staff_id', $b->id)->first();
        $alloc = new TipPoolService()->apply($run, 'custom_percent', ['percents' => [$itemA->id => 60, $itemB->id => 40]]);
        $this->assertEqualsWithDelta(300.0, array_sum($alloc), 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $itemA->fresh()->tips, 0.01);
    }

    public function test_payslip_renders_and_bank_file_exports(): void
    {
        [$owner, $r, $run, $a, $b] = $this->scenario();
        $item = $run->items()->first();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.payroll.payslip', [$run->id, $item->id]).'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('Net pay'));
        $res = $this->actingAs($owner)->get(route('stores.payroll.bankFile', $run->id).'?store_id='.$r->id)->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
    }

    public function test_mark_paid_requires_approval(): void
    {
        [$owner, $r, $run, $a, $b] = $this->scenario();
        $this->withoutMiddleware();
        // Not approved yet → 422.
        $this->actingAs($owner)->post(route('stores.payroll.markPaid', $run->id).'?store_id='.$r->id)->assertStatus(422);

        $engine = new PayrollEngine;
        $engine->submit($run->fresh());
        $engine->approve($run->fresh(), $owner->id);
        $this->actingAs($owner)->post(route('stores.payroll.markPaid', $run->id).'?store_id='.$r->id)->assertRedirect();
        $this->assertSame('paid', $run->fresh()->status);
    }
}
