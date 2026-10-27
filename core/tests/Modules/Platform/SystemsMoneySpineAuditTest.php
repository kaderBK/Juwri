<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Models\GlJournalLine;
use Juwri\Modules\Accounting\Services\Accounting\ExpenseService;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Accounting\Services\Accounting\GlReportService;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Payroll\PayrollEngine;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN V-P1: money-spine verification. Every GL journal produced across
 * the run's tracks (sales, expenses, payroll, inventory receipts) must be a
 * balanced double-entry, and the aggregate trial balance must reconcile to zero.
 * The GL is a reporting layer — it never becomes a parallel money path.
 */
class SystemsMoneySpineAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'settings.admin_allow_accounting_gl' => true,
            'settings.admin_allow_supplier_suite' => true,
            'settings.admin_allow_payroll_suite' => true,
        ]);
    }

    private function storeFixture(): array
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

    public function test_every_journal_balances_and_trial_balance_reconciles(): void
    {
        [$owner, $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '15');

        // 1) Sale.
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 50, 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'payment_method' => 'card']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'variation' => 0]);
        OrderJournalPoster::postOrderPaid($order->fresh());

        // 2) Expense.
        new ExpenseService()->create($r->id, ['amount' => 100, 'tax' => 15, 'date' => now()->toDateString()]);

        // 3) Inventory receipt.
        $supplier = Supplier::create(['store_id' => $r->id, 'name' => 'S']);
        $ing = Ingredient::create(['store_id' => $r->id, 'name' => 'Flour', 'unit' => 'kg', 'current_stock' => 0, 'cost_per_unit' => 2]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'supplier_id' => $supplier->id, 'status' => 'ordered', 'total' => 20]);
        $poItem = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'ingredient_id' => $ing->id, 'quantity' => 10, 'unit_cost' => 2, 'line_total' => 20]);
        new ReceivingService()->receive($po->fresh('items'), [['item_id' => $poItem->id, 'qty_received' => 10, 'actual_unit_cost' => 2]]);

        // 4) Payroll approval → wage journal.
        $staff = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'A', 'email' => 'a'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => 20]);
        $start = now()->startOfWeek();
        StaffTimeClock::create(['staff_id' => $staff->id, 'store_id' => $r->id, 'clock_in_at' => $start->copy()->addDay()->setTime(8, 0), 'clock_out_at' => $start->copy()->addDay()->setTime(18, 0), 'method' => 'manual']);
        $engine = new PayrollEngine;
        $run = $engine->build($r->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());
        $engine->submit($run->fresh());
        $engine->approve($run->fresh('items'), $owner->id);

        // INVARIANT 1: each journal individually balances.
        foreach (GlJournal::where('store_id', $r->id)->with('lines')->get() as $j) {
            $this->assertEqualsWithDelta($j->totalDebit(), $j->totalCredit(), 0.01, "Journal {$j->id} ({$j->rule_key}) must balance");
        }

        // INVARIANT 2: aggregate debits == credits across the whole ledger.
        $totalDebit = (float) GlJournalLine::whereIn('journal_id', GlJournal::where('store_id', $r->id)->pluck('id'))->sum(DB::raw(GlJournalLine::minorMoneySql('debit'))); // P1-05: debit_minor per row's currency
        $totalCredit = (float) GlJournalLine::whereIn('journal_id', GlJournal::where('store_id', $r->id)->pluck('id'))->sum(DB::raw(GlJournalLine::minorMoneySql('credit'))); // P1-05
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.01);

        // INVARIANT 3: the trial balance report is balanced.
        $tb = new GlReportService()->trialBalance($r->id);
        $this->assertTrue($tb['balanced'], 'Trial balance must reconcile');

        // We actually produced journals across all four money tracks.
        $this->assertGreaterThanOrEqual(4, GlJournal::where('store_id', $r->id)->count());
    }

    public function test_gl_is_noop_when_accounting_off(): void
    {
        config(['settings.admin_allow_accounting_gl' => false]);
        [$owner, $r] = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1]);
        $this->assertNull(OrderJournalPoster::postOrderPaid($order));
        $this->assertSame(0, GlJournal::where('store_id', $r->id)->count());
    }
}
