<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Models\GlJournal;
use Juwri\Modules\Accounting\Services\Accounting\ExpenseService;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Accounting\Services\Accounting\Gl\PostingRules;
use Juwri\Modules\Accounting\Services\Accounting\GlReportService;
use Juwri\Modules\Hr\Models\PayrollRun;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Juwri\Modules\Hr\Services\Payroll\PayrollEngine;
use Juwri\Modules\Hr\Services\Payroll\PayrollGlPoster;
use Juwri\Modules\Hr\Services\Payroll\TipPoolService;
use Juwri\Modules\Hr\Services\Rota\PayrollService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 10a-1 (characterisation): the money rounding of Accounting (posting rules, the journal engine, order
 * sales/refund journals, expenses, GL reports) and Payroll (engine lines/adjustments, tip pool, rota pay report,
 * payroll GL journals) for a 2-decimal (USD), a 3-decimal (KWD) and a 0-decimal (JPY) store, with .5 edges that are
 * exact in binary (10.125, 2.125 …) so PHP 8.3 and 8.4 round() agree on them. Written BEFORE the bare round() calls
 * moved to the Money layer: the USD values are unchanged after the move; the KWD/JPY values that changed are marked
 * "before:" (they were rounded to 2 decimals whatever the currency).
 */
class AccountingPayrollRoundingCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config([
            'settings.currency_code' => 'USD',
            'settings.admin_allow_accounting_gl' => true,
            'settings.admin_allow_payroll_suite' => true,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(string $currency): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'g-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$owner, $store];
    }

    /** @return list<array{0: string, 1: string, 2: string}> [account code, debit, credit] in line order */
    private function lines(GlJournal $journal): array
    {
        return $journal->lines()->with('account')->orderBy('id')->get()
            ->map(fn ($l) => [(string) $l->account->code, (string) $l->debit, (string) $l->credit])->all();
    }

    /** @return list<array{0: string, 1: float, 2: float}> */
    private function resolved(string $rule, int $storeId, array $ctx): array
    {
        PostingRules::bootDefaults();

        return array_map(fn ($l) => [(string) $l['account']->code, (float) $l['debit'], (float) $l['credit']], PostingRules::resolve($rule, $storeId, $ctx));
    }

    public function test_posting_rules_round_sales_and_refunds(): void
    {
        [, $usd] = $this->store('USD');
        $this->assertSame([['1130', 10.13, 0.0], ['4100', 0.0, 8.75], ['2200', 0.0, 1.38]], $this->resolved('sales', $usd->id, ['gross' => 10.125, 'tax' => 1.375]));
        $this->assertSame([['4900', 8.75, 0.0], ['2200', 1.38, 0.0], ['1110', 0.0, 10.13]], $this->resolved('refund', $usd->id, ['gross' => 10.125, 'tax' => 1.375, 'cash_code' => '1110']));

        [, $kwd] = $this->store('KWD');
        // before: [10.13, 8.82, 1.31] (2 decimals in a 3-decimal currency)
        $this->assertSame([['1130', 10.125, 0.0], ['4100', 0.0, 8.812], ['2200', 0.0, 1.313]], $this->resolved('sales', $kwd->id, ['gross' => 10.125, 'tax' => 1.3125]));

        [, $jpy] = $this->store('JPY');
        // before: [1001.5, 851.25, 150.25] (sub-yen amounts; the journal engine then stored 1002 / 851 / 150 — 1002 ≠ 1001)
        $this->assertSame([['1130', 1002.0, 0.0], ['4100', 0.0, 852.0], ['2200', 0.0, 150.0]], $this->resolved('sales', $jpy->id, ['gross' => 1001.5, 'tax' => 150.25]));
    }

    public function test_journal_engine_rounds_and_reports_per_currency(): void
    {
        [, $usd] = $this->store('USD');
        $j = JournalEngine::post($usd->id, [
            ['account' => JournalEngine::account($usd->id, '1110'), 'debit' => 10.125, 'credit' => 0],
            ['account' => JournalEngine::account($usd->id, '4100'), 'debit' => 0, 'credit' => 8.75],
            ['account' => JournalEngine::account($usd->id, '2200'), 'debit' => 0, 'credit' => 1.375],
        ], 'Order', 1, 'sales', 'Sale');
        $this->assertSame([['1110', '10.13', '0.00'], ['4100', '0.00', '8.75'], ['2200', '0.00', '1.38']], $this->lines($j));
        $reports = new GlReportService;
        $tb = $reports->trialBalance($usd->id);
        $this->assertSame([10.13, 10.13, true], [$tb['total_debit'], $tb['total_credit'], $tb['balanced']]);
        $this->assertSame([8.75, 0.0, 8.75], array_values(array_intersect_key($reports->profitAndLoss($usd->id), array_flip(['total_revenue', 'total_expense', 'net_income']))));
        $this->assertSame([1.38, 0.0, 1.38], array_values(array_intersect_key($reports->vatReport($usd->id), array_flip(['output_vat', 'input_vat', 'net_vat_due']))));
        $bs = $reports->balanceSheet($usd->id);
        $this->assertSame([10.13, 1.38, 8.75, 8.75, true], [$bs['total_assets'], $bs['total_liabilities'], $bs['retained_earnings'], $bs['total_equity'], $bs['balanced']]);

        try {
            JournalEngine::post($usd->id, [
                ['account' => JournalEngine::account($usd->id, '1110'), 'debit' => 1.005 + 0.0001, 'credit' => 0],
                ['account' => JournalEngine::account($usd->id, '4100'), 'debit' => 0, 'credit' => 1.02],
            ]);
            $this->fail('an unbalanced journal must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('balance', $e->getMessage());
        }

        [, $kwd] = $this->store('KWD');
        $j = JournalEngine::post($kwd->id, [
            ['account' => JournalEngine::account($kwd->id, '1110'), 'debit' => 1.2345, 'credit' => 0],
            ['account' => JournalEngine::account($kwd->id, '4100'), 'debit' => 0, 'credit' => 1.2345],
        ], 'Order', 2, 'sales', 'Sale');
        // before: '1.230' (rounded to 2 decimals, then stored with fils)
        $this->assertSame([['1110', '1.235', '0.000'], ['4100', '0.000', '1.235']], $this->lines($j));
        $this->assertSame(1.235, $reports->trialBalance($kwd->id)['total_debit'], 'before: 1.23');
        $this->assertSame(1.235, $reports->profitAndLoss($kwd->id)['net_income'], 'before: 1.23');

        [, $jpy] = $this->store('JPY');
        $j = JournalEngine::post($jpy->id, [
            ['account' => JournalEngine::account($jpy->id, '1110'), 'debit' => 1000.5, 'credit' => 0],
            ['account' => JournalEngine::account($jpy->id, '4100'), 'debit' => 0, 'credit' => 1000.5],
        ], 'Order', 3, 'sales', 'Sale');
        $this->assertSame([['1110', '1001', '0'], ['4100', '0', '1001']], $this->lines($j));
        $this->assertSame(1001.0, $reports->trialBalance($jpy->id)['total_debit']);
    }

    public function test_expenses_round_amount_and_tax(): void
    {
        [, $usd] = $this->store('USD');
        $e = (new ExpenseService)->create($usd->id, ['description' => 'Gas', 'amount' => 10.125, 'tax' => 1.375, 'payment_source' => 'cash']);
        $this->assertSame(['10.13', '1.38'], [$e->fresh()->amount, $e->fresh()->tax]);
        $j = GlJournal::where('store_id', $usd->id)->where('source_type', 'Expense')->firstOrFail();
        $this->assertSame([['6900', '10.13', '0.00'], ['1400', '1.38', '0.00'], ['1110', '0.00', '11.51']], $this->lines($j));
        // PHP-version independence (after the move only): 1.005 is 1.00499999… in binary; PHP 8.4 round(1.005, 2) gives
        // 1.0 (8.3 gave 1.01). Money reads the float at 15 significant digits → 1.01 on every PHP version.
        $edge = (new ExpenseService)->create($usd->id, ['description' => 'Edge', 'amount' => 1.005, 'tax' => 0, 'payment_source' => 'cash']);
        $this->assertSame('1.01', $edge->fresh()->amount);

        [, $kwd] = $this->store('KWD');
        $e = (new ExpenseService)->create($kwd->id, ['description' => 'Gas', 'amount' => 10.125, 'tax' => 0.0625, 'payment_source' => 'payable']);
        // before: '10.130', '0.060' and a 10.190 payable
        $this->assertSame(['10.125', '0.063'], [$e->fresh()->amount, $e->fresh()->tax]);
        $j = GlJournal::where('store_id', $kwd->id)->where('source_type', 'Expense')->firstOrFail();
        $this->assertSame([['6900', '10.125', '0.000'], ['1400', '0.063', '0.000'], ['2100', '0.000', '10.188']], $this->lines($j));

        [, $jpy] = $this->store('JPY');
        $e = (new ExpenseService)->create($jpy->id, ['description' => 'Gas', 'amount' => 1000.5, 'tax' => 150.5, 'payment_source' => 'cash']);
        $this->assertSame(['1001', '151'], [$e->fresh()->amount, $e->fresh()->tax]);
        $j = GlJournal::where('store_id', $jpy->id)->where('source_type', 'Expense')->firstOrFail();
        $this->assertSame([['6900', '1001', '0'], ['1400', '151', '0'], ['1110', '0', '1152']], $this->lines($j));
    }

    public function test_order_sales_and_refund_journals(): void
    {
        foreach ([['USD', 50, 2, ['115.00', '100.00', '15.00']], ['KWD', 1.111, 1, ['1.278', '1.111', '0.167']]] as [$currency, $price, $qty, $expected]) {
            [, $store] = $this->store($currency);
            StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '15');
            StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', 'percentage');
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
            $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => $price, 'active' => '1']);
            $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'payment_method' => 'card']);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'variation' => 0]);

            $j = OrderJournalPoster::postOrderPaid($order->fresh());
            // KWD before: cash '1.280', revenue '1.110', VAT '0.170' (total, subtotal and tax rounded to 2 decimals).
            $this->assertSame([['1130', $expected[0], $currency === 'USD' ? '0.00' : '0.000'], ['4100', $currency === 'USD' ? '0.00' : '0.000', $expected[1]], ['2200', $currency === 'USD' ? '0.00' : '0.000', $expected[2]]], $this->lines($j), $currency);

            $refund = OrderJournalPoster::postRefund($order->fresh(), $currency === 'USD' ? 10.125 : 0.0625, 'r1');
            // KWD before: '0.060'
            $this->assertSame($currency === 'USD' ? '10.13' : '0.063', (string) $this->lines($refund)[0][1], $currency);
        }
    }

    /** @return array{0: User, 1: Store, 2: list<StaffMember>, 3: PayrollRun} */
    private function payroll(string $currency, float $rate, int $staffCount = 1): array
    {
        [$owner, $store] = $this->store($currency);
        StoreOption::updateStoreOption($store->id, 'overtime_weekly_threshold', '5');
        $start = now()->startOfWeek();
        $staff = [];
        for ($i = 0; $i < $staffCount; $i++) {
            $staff[] = $member = StaffMember::create([
                'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'S'.$i, 'email' => 's'.Str::random(6).'@x.co',
                'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true, 'base_wage' => $rate, 'hourly_rate' => $rate,
            ]);
            // 7.5 h in one week: 5 h regular + 2.5 h overtime at ×1.5
            StaffTimeClock::create(['staff_id' => $member->id, 'store_id' => $store->id, 'clock_in_at' => $start->copy()->addDay()->setTime(8, 0), 'clock_out_at' => $start->copy()->addDay()->setTime(15, 30), 'method' => 'manual']);
        }
        $run = (new PayrollEngine)->build($store->id, $start->toDateString(), $start->copy()->addDays(6)->toDateString());

        return [$owner, $store, $staff, $run];
    }

    public function test_payroll_engine_lines_adjustments_and_gl(): void
    {
        [, $store, , $run] = $this->payroll('USD', 10.25);
        $item = $run->items->first();
        // 5 h × 10.25 = 51.25; 2.5 h × 10.25 × 1.5 = 38.4375 → 38.44
        $this->assertSame(['10.25', '51.25', '38.44', '89.69'], [$item->rate, $item->base_pay, $item->overtime_pay, $item->net]);
        $this->assertSame('89.69', $run->fresh()->total_net);
        (new PayrollEngine)->adjustItem($item->fresh(), 'Bonus', 10.125);
        $this->assertSame(['10.13', '0.00', '99.82', '99.82'], [$item->fresh()->bonuses, $item->fresh()->deductions, $item->fresh()->net, $run->fresh()->total_net]);
        PayrollGlPoster::postApproval($run->fresh('items'));
        $gl = GlJournal::where('store_id', $store->id)->where('rule_key', 'payroll_approved')->firstOrFail();
        $this->assertSame([['6100', '99.82', '0.00'], ['2300', '0.00', '99.82']], $this->lines($gl));
        PayrollGlPoster::postPayment($run->fresh('items'));
        $paid = GlJournal::where('store_id', $store->id)->where('rule_key', 'payroll_paid')->firstOrFail();
        $this->assertSame([['2300', '99.82', '0.00'], ['1110', '0.00', '99.82']], $this->lines($paid));

        [, $store, , $run] = $this->payroll('KWD', 2.125);
        $item = $run->items->first();
        // 5 × 2.125 = 10.625; 2.5 × 2.125 × 1.5 = 7.96875
        // before: '10.630', '7.970', '18.600'
        $this->assertSame(['2.125', '10.625', '7.969', '18.594'], [$item->rate, $item->base_pay, $item->overtime_pay, $item->net]);
        $this->assertSame('18.594', $run->fresh()->total_net, 'before: 18.600');
        (new PayrollEngine)->adjustItem($item->fresh(), 'Bonus', 0.0625);
        // before: bonus '0.060', net/total '18.660'
        $this->assertSame(['0.063', '18.657', '18.657'], [$item->fresh()->bonuses, $item->fresh()->net, $run->fresh()->total_net]);
        PayrollGlPoster::postApproval($run->fresh('items'));
        $gl = GlJournal::where('store_id', $store->id)->where('rule_key', 'payroll_approved')->firstOrFail();
        $this->assertSame([['6100', '18.657', '0.000'], ['2300', '0.000', '18.657']], $this->lines($gl), 'before: 18.660');

        [, , , $run] = $this->payroll('JPY', 1001);
        $item = $run->items->first();
        // 5 × 1001 = 5005; 2.5 × 1001 × 1.5 = 3753.75
        $this->assertSame(['1001', '5005', '3754', '8759'], [$item->rate, $item->base_pay, $item->overtime_pay, $item->net]);
    }

    public function test_tip_pool_and_rota_pay_report(): void
    {
        [, $store, $staff, $run] = $this->payroll('USD', 10.25, 3);
        $pool = new TipPoolService;
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'tip_amount' => 1.125, 'created_at' => now()->startOfWeek()->addDay()]);
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'tip_amount' => 2.25, 'created_at' => now()->startOfWeek()->addDay()]);
        $this->assertSame(3.38, $pool->poolTotal($run->fresh()));
        $this->assertSame([3.34, 3.34, 3.33], array_values($pool->preview($run->fresh(), 'by_hours', [], 10.01)));
        $alloc = $pool->apply($run->fresh(), 'by_hours');
        $this->assertSame([1.13, 1.13, 1.12], array_values($alloc));
        $this->assertSame('272.45', $run->fresh()->total_net, '3 × 89.69 + 3.38');
        $report = (new PayrollService)->report($store->id, now()->startOfWeek()->toDateString(), now()->startOfWeek()->addDays(6)->toDateString());
        $row = $report['rows'][0];
        $this->assertSame([10.25, 76.88], [$row['rate'], $row['pay']]);

        [, $store, , $run] = $this->payroll('KWD', 2.125, 3);
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'tip_amount' => 1.235, 'created_at' => now()->startOfWeek()->addDay()]);
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 1, 'tip_amount' => 0.0105, 'created_at' => now()->startOfWeek()->addDay()]);
        $this->assertSame(1.246, $pool->poolTotal($run->fresh()), 'before: 1.25');
        // before: [0.34, 0.33, 0.33] (the pool cut to whole cents)
        $this->assertSame([0.334, 0.334, 0.333], array_values($pool->preview($run->fresh(), 'by_hours', [], 1.0005)));
        $report = (new PayrollService)->report($store->id, now()->startOfWeek()->toDateString(), now()->startOfWeek()->addDays(6)->toDateString());
        // 7.5 h × 2.125 = 15.9375 — before: rate 2.13, pay 15.94
        $this->assertSame([2.125, 15.938], [$report['rows'][0]['rate'], $report['rows'][0]['pay']]);
    }
}
