<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlAccount;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Juwri\Modules\Accounting\Services\Accounting\Gl\PeriodLock;
use Juwri\Modules\Accounting\Services\Accounting\Gl\PostingRules;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN S0-P1: chart of accounts + JournalEngine + posting rules.
 */
class GlCoreTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_chart_seeds_hierarchically_and_idempotently(): void
    {
        $r = $this->storeFixture();
        ChartOfAccounts::seed($r->id);
        $count = GlAccount::where('store_id', $r->id)->count();
        $this->assertGreaterThan(20, $count);

        // Re-seed: no duplicates.
        ChartOfAccounts::seed($r->id);
        $this->assertSame($count, GlAccount::where('store_id', $r->id)->count());

        // Hierarchy integrity: child 1110 points at parent 1100.
        $child = GlAccount::where('store_id', $r->id)->where('code', '1110')->first();
        $parent = GlAccount::where('store_id', $r->id)->where('code', '1100')->first();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertArrayHasKey('ar', $parent->name);
    }

    public function test_journal_must_balance(): void
    {
        $r = $this->storeFixture();
        ChartOfAccounts::seed($r->id);
        $cash = JournalEngine::account($r->id, '1110');
        $rev = JournalEngine::account($r->id, '4100');

        $this->expectException(\InvalidArgumentException::class);
        JournalEngine::post($r->id, [
            ['account' => $cash, 'debit' => 100, 'credit' => 0],
            ['account' => $rev, 'debit' => 0, 'credit' => 90],
        ]);
    }

    public function test_balanced_journal_posts(): void
    {
        $r = $this->storeFixture();
        $j = JournalEngine::post($r->id, [
            ['account' => JournalEngine::account($r->id, '1110'), 'debit' => 100, 'credit' => 0],
            ['account' => JournalEngine::account($r->id, '4100'), 'debit' => 0, 'credit' => 100],
        ], 'Order', 55, 'sales', 'Test sale');

        $this->assertEqualsWithDelta(100.0, $j->totalDebit(), 0.001);
        $this->assertEqualsWithDelta(100.0, $j->totalCredit(), 0.001);
        $this->assertSame('J000001', $j->journal_no);
    }

    public function test_posting_is_idempotent_on_source(): void
    {
        $r = $this->storeFixture();
        $lines = [
            ['account' => JournalEngine::account($r->id, '1110'), 'debit' => 50, 'credit' => 0],
            ['account' => JournalEngine::account($r->id, '4100'), 'debit' => 0, 'credit' => 50],
        ];
        $a = JournalEngine::post($r->id, $lines, 'Order', 7, 'sales');
        $b = JournalEngine::post($r->id, $lines, 'Order', 7, 'sales');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, GlJournal::where('store_id', $r->id)->count());
    }

    public function test_locked_period_refuses_posting(): void
    {
        $r = $this->storeFixture();
        PeriodLock::lock($r->id, now()->format('Y-m'));
        $this->expectException(\RuntimeException::class);
        JournalEngine::post($r->id, [
            ['account' => JournalEngine::account($r->id, '1110'), 'debit' => 10, 'credit' => 0],
            ['account' => JournalEngine::account($r->id, '4100'), 'debit' => 0, 'credit' => 10],
        ]);
    }

    public function test_posting_rules_sales_produces_balanced_lines(): void
    {
        $r = $this->storeFixture();
        PostingRules::bootDefaults();
        $lines = PostingRules::resolve('sales', $r->id, ['gross' => 115, 'tax' => 15]);
        $j = JournalEngine::post($r->id, $lines, 'Order', 99, 'sales');
        $this->assertEqualsWithDelta(115.0, $j->totalDebit(), 0.001);
        $this->assertEqualsWithDelta(115.0, $j->totalCredit(), 0.001);
        // VAT payable (2200) credited 15.
        $vat = GlAccount::where('store_id', $r->id)->where('code', '2200')->first();
        $this->assertEqualsWithDelta(15.0, (float) $j->lines->firstWhere('gl_account_id', $vat->id)->credit, 0.001);
    }

    public function test_accounts_are_tenant_scoped(): void
    {
        $r1 = $this->storeFixture();
        $r2 = $this->storeFixture();
        ChartOfAccounts::seed($r1->id);
        ChartOfAccounts::seed($r2->id);
        $this->assertSame(0, GlAccount::where('store_id', $r1->id)
            ->whereIn('id', GlAccount::where('store_id', $r2->id)->pluck('id'))->count());
    }

    public function test_feature_keys_registered_default_off(): void
    {
        foreach (['accounting_gl', 'hrm_suite', 'payroll_suite', 'crm_suite', 'support_desk',
            'team_comms', 'driver_app', 'waiter_mode', 'supplier_suite'] as $key) {
            $this->assertArrayHasKey($key, FeatureRegistry::keyed(), "missing feature key {$key}");
            config(['settings.admin_allow_'.$key => null]);
            $this->assertFalse(FeatureRegistry::keyed()[$key]['default'], "{$key} must default OFF");
        }
    }
}
