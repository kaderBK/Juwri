<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Services\Accounting\ExpenseService;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Juwri\Modules\Accounting\Services\Accounting\Gl\PeriodLock;
use Juwri\Modules\Accounting\Services\Accounting\GlReportService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN A-P3: financial reports + period close.
 */
class AccountingReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_accounting_gl' => true]);
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

    private function ownerRestaurant(): array
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

        return [$owner, $r];
    }

    /** Post a 115 sale (100 net + 15 VAT) and a 50 expense; verify report math. */
    private function seedActivity(int $rid): void
    {
        JournalEngine::post($rid, [
            ['account' => JournalEngine::account($rid, '1130'), 'debit' => 115, 'credit' => 0],
            ['account' => JournalEngine::account($rid, '4100'), 'debit' => 0, 'credit' => 100],
            ['account' => JournalEngine::account($rid, '2200'), 'debit' => 0, 'credit' => 15],
        ], 'Order', 1, 'order_paid');
        new ExpenseService()->create($rid, ['gl_account_id' => JournalEngine::account($rid, '6200')->id, 'amount' => 50, 'tax' => 0, 'date' => now()->toDateString()]);
    }

    public function test_report_math(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->seedActivity($r->id);
        $svc = new GlReportService;

        $tb = $svc->trialBalance($r->id);
        $this->assertTrue($tb['balanced']);
        $this->assertEqualsWithDelta($tb['total_debit'], $tb['total_credit'], 0.01);

        $pl = $svc->profitAndLoss($r->id);
        $this->assertEqualsWithDelta(100.0, $pl['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(50.0, $pl['total_expense'], 0.01);
        $this->assertEqualsWithDelta(50.0, $pl['net_income'], 0.01);

        $bs = $svc->balanceSheet($r->id);
        $this->assertTrue($bs['balanced'], 'balance sheet must balance');

        $vat = $svc->vatReport($r->id);
        $this->assertEqualsWithDelta(15.0, $vat['output_vat'], 0.01);
        $this->assertEqualsWithDelta(15.0, $vat['net_vat_due'], 0.01);
    }

    public function test_reports_page_renders(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->seedActivity($r->id);
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.books.reports').'?store_id='.$r->id)->assertOk()->assertSeeHtml('Profit');
    }

    public function test_period_close_locks_posting(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.books.period.lock').'?store_id='.$r->id, [
            'period' => now()->format('Y-m'),
        ])->assertRedirect();

        $this->assertTrue(PeriodLock::isLocked($r->id, now()->toDateString()));
        $this->expectException(\RuntimeException::class);
        JournalEngine::post($r->id, [
            ['account' => JournalEngine::account($r->id, '1130'), 'debit' => 10, 'credit' => 0],
            ['account' => JournalEngine::account($r->id, '4100'), 'debit' => 0, 'credit' => 10],
        ]);
    }

    public function test_journals_page_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        // Owner requesting another tenant's store_id → 403.
        $this->actingAs($owner)->get(route('stores.books.journals').'?store_id='.$otherR->id)
            ->assertForbidden();
    }
}
