<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Accounting\Services\Accounting\OrderInvoice;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDocumentService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-99b golden checkouts (TAX-ENGINE.md): on the VAT engine order tax == Σ line VAT == fiscal invoice
 * VAT == journal Output VAT, and what is payable == what is charged; a store that did not opt in keeps its totals.
 */
class TaxEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true, 'settings.admin_allow_accounting_gl' => true]);
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
        View::share(['settings' => (object) []]);
        Queue::fake();
    }

    /** @param array<string,mixed> $options */
    private function store(array $options): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($options + ['fiscal_country' => 'SA', 'fiscal_legal_name' => 'Riyadh Diner', 'fiscal_vat_number' => '300000000000003', 'currency_code' => 'SAR', 'restaurant_taxx_charge' => '15'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    /**
     * @param  list<array{0:string,1:float,2:int,3?:array<string,mixed>}>  $items
     * @param  array<string,mixed>  $attrs
     */
    private function order(Store $store, array $items, array $attrs = []): Order
    {
        $order = Order::create($attrs + ['store_id' => $store->id, 'type' => 'delivery', 'status' => 'new', 'payment_method' => 'card']);
        foreach ($items as $row) {
            $menu = Menu::create(['store_id' => $store->id, 'name' => $row[0], 'price' => $row[1], 'active' => '1'] + ($row[3] ?? []));
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $row[2]]);
        }
        $order = $order->fresh();
        $order->snapshotTotals();

        return $order->fresh();
    }

    /** order tax == Σ line VAT == invoice VAT == journal VAT; payable == charged */
    private function assertOneVat(Store $store, Order $order, int $taxMinor, int $grandMinor): void
    {
        $money = $order->computeMoney();
        $this->assertSame($taxMinor, (int) round($money['tax'] * 100), 'order tax');
        $this->assertSame($grandMinor, (int) round($money['grand'] * 100), 'order total');
        $this->assertSame($grandMinor, (int) $order->grand_total_minor, 'stored total');
        $this->assertSame($taxMinor, (int) $order->tax_total_minor, 'stored tax');
        $this->assertSame($grandMinor, (int) round($order->amount_due * 100), 'payable == charged');

        $snap = TaxEngine::snapshot($order);
        $this->assertNotNull($snap);
        $this->assertSame($taxMinor, $snap['tax']);
        $this->assertSame($taxMinor, array_sum(array_column($snap['lines'], 'tax')), 'Σ line VAT');

        $f = FiscalInvoiceMath::compute(OrderFiscalContext::for($store, $order), 2, dropOutOfScope: true);
        $this->assertSame($taxMinor, $f['tax_total'], 'fiscal invoice VAT');
        $this->assertSame(0, $f['rounding']);
        $this->assertSame($grandMinor, $f['payable'] + $f['excluded']);

        $j = OrderJournalPoster::postOrderPaid($order);
        $this->assertNotNull($j);
        $j->load('lines.account');
        $vat = $j->lines->first(fn ($l) => $l->account->code === '2200');
        $this->assertSame($taxMinor, (int) round((float) ($vat->credit ?? 0) * 100), 'journal Output VAT');
        $this->assertEqualsWithDelta($j->totalDebit(), $j->totalCredit(), 0.001);

        $inv = OrderInvoice::fromOrder($order);
        $this->assertSame($grandMinor, $inv->linesMinor() + $inv->taxMinor, 'accounting export: lines + tax = total');
    }

    public function test_exclusive_mixed_rates_taxed_delivery_discount_and_tip(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'tax_delivery_taxable' => '1']);
        $order = $this->order($store, [
            ['Kabsa', 42.5, 2],                                  // 85.00 at the store's 15 %
            ['Water', 2.0, 3, ['tax_rate' => 5]],                // 6.00 at its own 5 %
            ['Paracetamol', 10.0, 1, ['tax_category' => 'Z']],   // 10.00 zero-rated
        ], ['delivery_fee' => 11.5, 'tip_amount' => 5, 'discount_amount' => 10.1]);

        // discount 10.10 pro rata: 8.50 / 0.60 / 1.00 → VAT 76.50×15 % = 11.48, 5.40×5 % = 0.27, 0; delivery 11.50×15 % = 1.73
        $this->assertOneVat($store, $order, 1348, 10100 + 1150 + 500 - 1010 + 1348);

        $snap = TaxEngine::snapshot($order);
        $this->assertSame(['S', 'S', 'Z', 'S', 'O'], array_column($snap['lines'], 'category'));
        $this->assertSame([850, 60, 100, 0, 0], array_column($snap['lines'], 'discount'));

        FiscalDevice::create(['store_id' => $store->id, 'device_key' => 'EGS-1', 'country' => 'SA', 'status' => 'new']);
        $doc = new ZatcaDocumentService(new ZatcaCrypto)->issue($store, OrderFiscalContext::for($store, $order), 'EGS-1');
        $this->assertSame(13.48, (float) $doc->tax_total);
    }

    public function test_inclusive_prices_hold_the_vat(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'tax_prices_include' => '1', 'tax_delivery_taxable' => '1']);
        $order = $this->order($store, [['Kabsa', 23.0, 1]], ['delivery_fee' => 11.5, 'discount_amount' => 2.3]);

        // 20.70 gross → 2.70 VAT; delivery 11.50 gross → 1.50 VAT; nothing added on top
        $this->assertOneVat($store, $order, 420, 2300 + 1150 - 230);
    }

    public function test_fees_not_taxable_unless_the_owner_turns_it_on(): void
    {
        $store = $this->store(['tax_engine' => 'vat']); // J32: OFF by default, even in KSA
        $order = $this->order($store, [['Kabsa', 40.0, 1]], ['delivery_fee' => 10]);
        $this->assertOneVat($store, $order, 600, 4000 + 1000 + 600);
        $this->assertSame('O', TaxEngine::snapshot($order)['lines'][1]['category']);
    }

    public function test_an_unknown_country_does_not_tax_fees_by_default(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'fiscal_country' => 'GB']);
        $order = $this->order($store, [['Pie', 40.0, 1]], ['delivery_fee' => 10]);
        $this->assertSame(600, (int) $order->tax_total_minor);
    }

    public function test_placed_orders_keep_their_rates(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'tax_delivery_taxable' => '1']);
        $order = $this->order($store, [['Kabsa', 40.0, 1, ['tax_rate' => 5]]], ['delivery_fee' => 10]);
        $this->assertSame(200 + 150, (int) $order->tax_total_minor);

        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10');
        Menu::query()->where('store_id', $store->id)->update(['tax_rate' => 20]);
        StoreOption::updateStoreOption($store->id, 'tax_engine', '');
        $order = Order::find($order->id);
        $this->assertSame(3.5, $order->computeMoney()['tax'], 'a placed order is not re-taxed by later settings');
        $order->snapshotTotals();
        $this->assertSame(350, (int) $order->fresh()->tax_total_minor);
    }

    public function test_legacy_store_totals_unchanged(): void
    {
        $store = $this->store(['fiscal_country' => 'JO']); // P6-186 (J44): KSA is always on the engine
        $order = $this->order($store, [['Kabsa', 42.5, 2], ['Water', 2.0, 3, ['tax_rate' => 5]]], ['delivery_fee' => 11.5, 'tip_amount' => 5, 'discount_amount' => 10.1]);

        // legacy (P6-271): 15 % on the items after the discount (91.00 − 10.10), nothing on fees, item rates ignored
        $this->assertSame(12.14, $order->computeMoney()['tax']);
        $this->assertSame(9100 + 1150 + 500 - 1010 + 1214, (int) $order->grand_total_minor);
        $this->assertNull($order->getAttribute('tax_lines'));
        $this->assertFalse(TaxEngine::applies($order));
        $j = OrderJournalPoster::postOrderPaid($order);
        $j->load('lines.account');
        $this->assertSame(12.14, round((float) $j->lines->first(fn ($l) => $l->account->code === '2200')->credit, 2), 'journal VAT == order tax');
    }

    public function test_fixed_tax_stays_legacy_even_when_opted_in(): void
    {
        $store = $this->store(['tax_engine' => 'vat', 'restaurant_tax_type' => 'fixed', 'restaurant_taxx_charge' => '3']);
        $order = $this->order($store, [['Kabsa', 40.0, 1]], ['type' => 'takeaway']);
        $this->assertSame(300, (int) $order->tax_total_minor);
        $this->assertNull($order->getAttribute('tax_lines'));
    }
}
