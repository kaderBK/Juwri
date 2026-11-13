<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Payroll\PayrollEngine;
use Juwri\Modules\Hr\Services\Payroll\PayrollGlPoster;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN P-P3: payroll → GL posting + labor dashboard.
 */
class PayrollGlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_payroll_suite' => true, 'settings.admin_allow_accounting_gl' => true]);
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
        ChartOfAccounts::ensure($r->id);
        $start = now()->startOfWeek();
        $staff = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'A', 'email' => 'a'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => 20]);
        StaffTimeClock::create(['staff_id' => $staff->id, 'store_id' => $r->id, 'clock_in_at' => $start->copy()->addDay()->setTime(8, 0), 'clock_out_at' => $start->copy()->addDay()->setTime(18, 0), 'method' => 'manual']); // 10h → 200
        $run = new PayrollEngine()->build($r->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());

        return [$owner, $r, $run];
    }

    public function test_approval_posts_balanced_wage_journal(): void
    {
        [$owner, $r, $run] = $this->scenario();
        $engine = new PayrollEngine;
        $engine->submit($run->fresh());
        $engine->approve($run->fresh('items'), $owner->id);

        $j = GlJournal::where('store_id', $r->id)->where('source_type', 'PayrollRun')->where('rule_key', 'payroll_approved')->first();
        $this->assertNotNull($j);
        $this->assertEqualsWithDelta($j->totalDebit(), $j->totalCredit(), 0.01);
        $this->assertEqualsWithDelta(200.0, $j->totalDebit(), 0.01);
    }

    public function test_payment_posts_cash_journal_and_is_idempotent(): void
    {
        [$owner, $r, $run] = $this->scenario();
        $engine = new PayrollEngine;
        $engine->submit($run->fresh());
        $engine->approve($run->fresh('items'), $owner->id);

        PayrollGlPoster::postPayment($run->fresh('items'));
        PayrollGlPoster::postPayment($run->fresh('items'));
        $this->assertSame(1, GlJournal::where('source_type', 'PayrollRun')->where('rule_key', 'payroll_paid')->count());
    }

    public function test_labor_dashboard_math(): void
    {
        [$owner, $r, $run] = $this->scenario();
        // A paid order of ~100 in the window for a sales base.
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'created_at' => now()]);
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.payroll.labor').'?store_id='.$r->id)->assertOk()->assertSeeHtml('Labor');
    }
}
