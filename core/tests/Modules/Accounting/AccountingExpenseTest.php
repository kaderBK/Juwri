<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\Expense;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Services\Accounting\ExpenseService;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN A-P2: expenses — CRUD, GL posting, recurring, tenant scope, gate.
 */
class AccountingExpenseTest extends TestCase
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

        return [$owner, $r];
    }

    public function test_index_renders_with_nav(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.books.expenses').'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('Expenses'));
    }

    public function test_store_creates_expense_and_journal(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        ChartOfAccounts::ensure($r->id);
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.books.expenses.store').'?store_id='.$r->id, [
            'description' => 'Electricity', 'amount' => 100, 'tax' => 15, 'date' => now()->toDateString(),
            'payment_source' => 'cash',
        ])->assertRedirect();

        $exp = Expense::where('store_id', $r->id)->first();
        $this->assertNotNull($exp);
        $this->assertEqualsWithDelta(100.0, (float) $exp->amount, 0.01);
        // GL journal posted, balanced 115/115.
        $j = GlJournal::where('store_id', $r->id)->where('source_type', 'Expense')->first();
        $this->assertNotNull($j);
        $this->assertEqualsWithDelta(115.0, $j->totalDebit(), 0.01);
        $this->assertEqualsWithDelta(115.0, $j->totalCredit(), 0.01);
    }

    public function test_recurring_generates_idempotently(): void
    {
        // Pin the clock to a mid-month date: on month-end days (e.g. the 31st)
        // now()->subMonths(2) overflows (Jun 31 -> Jul 1), leaving only one due
        // month and flaking this test. Fixed date keeps the month math stable.
        Carbon::setTestNow('2026-08-15 12:00:00');
        try {
            [$owner, $r] = $this->ownerRestaurant();
            ChartOfAccounts::ensure($r->id);
            $svc = new ExpenseService;
            $svc->create($r->id, [
                'description' => 'Rent', 'amount' => 1000, 'date' => now()->subMonths(2)->toDateString(),
                'recur_interval' => 'monthly',
            ]);
            // Two months elapsed → children generated for each due month.
            $svc->generateRecurring(now()->toDateString());
            $firstCount = Expense::where('store_id', $r->id)->whereNotNull('recur_parent_id')->count();
            $this->assertGreaterThanOrEqual(2, $firstCount);
            // Re-run same day → no new children.
            $svc->generateRecurring(now()->toDateString());
            $this->assertSame($firstCount, Expense::where('store_id', $r->id)->whereNotNull('recur_parent_id')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tenant_scope_on_destroy(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $foreign = new ExpenseService()->create($otherR->id, ['description' => 'X', 'amount' => 5, 'date' => now()->toDateString()]);
        $this->withoutMiddleware();
        $this->actingAs($owner)->delete(route('stores.books.expenses.destroy', $foreign->id).'?store_id='.$r->id)
            ->assertNotFound();
    }
}
