<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Accounting\Services\Accounting\GlReportService;
use Juwri\Modules\Menu\Http\Controllers\User\ModifierController;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Services\OrderFiscalInvoices;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-258 3-decimal option / credit-note amounts; P6-259 VAT report uses the store's rate(s);
 * P6-260 Z-report tax lines, split shares by method, refunds by method, cash reconciliation.
 */
class FiscalLinesP6258Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_accounting_gl' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $currency, array $opts = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $s = Store::create([
            'user_id' => $owner->id, 'slug' => 'f-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        foreach (['currency_code' => $currency] + $opts as $k => $v) {
            StoreOption::updateStoreOption($s->id, $k, $v);
        }
        ChartOfAccounts::ensure($s->id);

        return $s;
    }

    private function staff(Store $s, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $s->user_id, 'store_id' => $s->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function item(Store $s, float $price, array $extra = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $s->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(array_merge(['store_id' => $s->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1], $extra));
    }

    private function sell(Store $s, StaffMember $st, array $lines, string $method): Order
    {
        $o = PosService::createOrder($s, $st, ['type' => 'takeaway', 'items' => $lines]);
        PosService::settle($o, $method, $method === 'cash' ? 1000.0 : null);

        return $o->fresh();
    }

    // ── P6-258 ──────────────────────────────────────────────────────────

    public static function currencies(): array
    {
        return ['KWD' => ['KWD', 0.125], 'BHD' => ['BHD', 0.125], 'JOD' => ['JOD', 0.125], 'SAR' => ['SAR', 0.13]];
    }

    #[DataProvider('currencies')]
    public function test_option_price_keeps_currency_decimals(string $currency, float $expected): void
    {
        $s = $this->store($currency);
        $menu = $this->item($s, 1);
        $g = MenuVariantOption::create([
            'store_id' => $s->id, 'title' => 'Sauce', 'is_global' => true, 'menu_id' => null, 'options' => ['Garlic'],
            'option_prices' => [0.125], 'required' => false, 'min_select' => 0, 'max_select' => 1, 'position' => 0, 'active' => true,
        ]);
        $menu->attachedVariantOptions()->syncWithoutDetaching([$g->id => ['position' => 0, 'required' => false, 'min_select' => 0, 'max_select' => 1, 'active' => true]]);

        [, $unit] = OrderApiService::resolveOptions($menu->fresh(), [['group_id' => $g->id, 'selected' => ['Garlic']]], 0);
        $this->assertSame($expected, $unit);

        $parse = new \ReflectionMethod(ModifierController::class, 'parsePrices');
        $this->assertSame([$expected, 1.0], $parse->invoke(app(ModifierController::class), '0.125,1', 2, $s->id));
    }

    public function test_kwd_credit_note_tax_keeps_three_decimals(): void
    {
        $s = $this->store('KWD', ['restaurant_taxx_charge' => '5']);
        $st = $this->staff($s);
        PosService::openShift($s, $st, null, 0);
        $o = $this->sell($s, $st, [['item_id' => $this->item($s, 1.125)->id, 'quantity' => 3]], 'cash');
        $this->assertSame(3.544, (float) $o->total); // 3.375 + 0.169 (5%)
        $adj = PosService::refund($o, $st, 1.0, 'cash', 'r');

        $ctx = OrderFiscalInvoices::refundContext($s, $o, $adj);
        $this->assertSame(1.0, $ctx->total);
        $this->assertSame(0.048, $ctx->taxAmount); // 1 × 0.169 / 3.544 = 0.04768 (was 0.05)
    }

    // ── P6-259 ──────────────────────────────────────────────────────────

    public function test_vat_report_shows_store_rate_not_platform_default(): void
    {
        config(['settings.restaurant_taxx_charge' => 15]);
        $s = $this->store('AED', ['restaurant_taxx_charge' => '5']);
        $st = $this->staff($s);
        $o = $this->sell($s, $st, [['item_id' => $this->item($s, 100)->id, 'quantity' => 1]], 'card');
        OrderJournalPoster::postOrderPaid($o);

        $vat = (new GlReportService)->vatReport($s->id);
        $this->assertSame(5.0, $vat['rate']);
        $this->assertSame('order', $vat['mode']);
        $this->assertSame([['rate' => 5.0, 'category' => 'S', 'net' => 100.0, 'vat' => 5.0]], $vat['rates']);
        $this->assertSame(5.0, $vat['output_vat']);
    }

    public function test_vat_report_lists_each_rate_for_vat_per_line_store_kwd(): void
    {
        $s = $this->store('KWD', ['restaurant_taxx_charge' => '5', 'tax_engine' => 'vat']);
        $st = $this->staff($s);
        $a = $this->item($s, 1.125);
        $b = $this->item($s, 2.250, ['tax_rate' => 10]);
        $o = $this->sell($s, $st, [['item_id' => $a->id, 'quantity' => 1], ['item_id' => $b->id, 'quantity' => 1]], 'card');
        $this->assertSame(3.656, (float) $o->total); // 3.375 + 0.056 + 0.225
        OrderJournalPoster::postOrderPaid($o);

        $vat = (new GlReportService)->vatReport($s->id);
        $this->assertSame('per_line', $vat['mode']);
        $this->assertSame(3, $vat['decimals']);
        $this->assertSame([
            ['rate' => 10.0, 'category' => 'S', 'net' => 2.25, 'vat' => 0.225],
            ['rate' => 5.0, 'category' => 'S', 'net' => 1.125, 'vat' => 0.056],
        ], $vat['rates']);
        $this->assertSame(0.281, $vat['output_vat']);
    }

    public function test_vat_report_fixed_tax_has_no_rate(): void
    {
        $s = $this->store('SAR', ['restaurant_taxx_charge' => '2', 'restaurant_tax_type' => 'fixed']);
        $this->assertNull((new GlReportService)->vatReport($s->id)['rate']);
    }

    // ── P6-260 ──────────────────────────────────────────────────────────

    public function test_z_report_tax_lines_split_shares_and_refunds_reconcile_sar(): void
    {
        $s = $this->store('SAR', ['restaurant_taxx_charge' => '15']);
        $st = $this->staff($s);
        $shift = PosService::openShift($s, $st, null, 100);
        $item = $this->item($s, 10);
        $line = [['item_id' => $item->id, 'quantity' => 1]];
        $this->sell($s, $st, $line, 'cash');   // 11.50
        $this->sell($s, $st, $line, 'card');   // 11.50

        $split = PosService::createOrder($s, $st, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]]]); // 23.00
        $shares = $this->actingAs($st, 'staff')
            ->postJson(route('pos.orders.split', ['id' => $split->id, 'store' => $s->id]), ['parts' => 2])->assertOk()->json('shares');
        $this->actingAs($st, 'staff')->postJson(route('pos.orders.splitPay', ['id' => $split->id, 'store' => $s->id]), ['share_id' => $shares[0]['id'], 'method' => 'cash'])->assertOk();
        $this->actingAs($st, 'staff')->postJson(route('pos.orders.splitPay', ['id' => $split->id, 'store' => $s->id]), ['share_id' => $shares[1]['id'], 'method' => 'card'])->assertOk();
        $this->assertSame($shift->id, (int) $split->fresh()->pos_shift_id);

        $cashOrder = Order::where('store_id', $s->id)->where('payment_method', 'cash')->first();
        PosService::refund($cashOrder, $st, 5.0, 'cash', 'r');

        $z = PosService::zReport($shift->fresh());
        $this->assertSame(['cash' => 23.0, 'card' => 23.0], $z['sales_by_method']);
        $this->assertArrayNotHasKey('split', $z['sales_by_method']);
        $this->assertSame(['cash' => 11.5, 'card' => 11.5], $z['split_shares_by_method']);
        $this->assertSame(40.0, $z['net_sales']);
        $this->assertSame(6.0, $z['tax_total']);
        $this->assertSame(46.0, $z['gross_sales']);
        $this->assertSame([['rate' => 15.0, 'category' => 'S', 'net' => 40.0, 'vat' => 6.0]], $z['tax_lines']);
        $this->assertSame(['cash' => 5.0], $z['refunds_by_method']);
        $this->assertSame(5.0, $z['refunds_total']);
        $this->assertSame(118.0, $z['cash_reconciliation']); // 100 + 11.5 + 11.5 − 5
        $this->assertSame($z['expected_cash'], $z['cash_reconciliation']);
    }

    public function test_z_report_per_line_rates_kwd(): void
    {
        $s = $this->store('KWD', ['restaurant_taxx_charge' => '5', 'tax_engine' => 'vat']);
        $st = $this->staff($s);
        $shift = PosService::openShift($s, $st, null, 10);
        $a = $this->item($s, 1.125);
        $b = $this->item($s, 2.250, ['tax_rate' => 10]);
        $this->sell($s, $st, [['item_id' => $a->id, 'quantity' => 1], ['item_id' => $b->id, 'quantity' => 1]], 'cash');

        $z = PosService::zReport($shift->fresh());
        $this->assertSame([
            ['rate' => 10.0, 'category' => 'S', 'net' => 2.25, 'vat' => 0.225],
            ['rate' => 5.0, 'category' => 'S', 'net' => 1.125, 'vat' => 0.056],
        ], $z['tax_lines']);
        $this->assertSame(0.281, $z['tax_total']);
        $this->assertSame(['cash' => 3.656], $z['sales_by_method']);
        $this->assertSame(13.656, $z['cash_reconciliation']);
        $this->assertSame($z['expected_cash'], $z['cash_reconciliation']);
    }
}
