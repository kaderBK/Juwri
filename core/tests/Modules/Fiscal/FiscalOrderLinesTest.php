<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDocumentService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-99: Ordering fills per-line fiscal contexts from a real order — item lines with the order's own VAT split over
 * them, the delivery fee (VAT inside), the tip (out of scope), the order discount (document allowance) — and the
 * ZATCA invoice's payable + left-off tip equals the order total exactly. No live fiscal calls.
 */
class FiscalOrderLinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true]);
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
        foreach (['fiscal_country' => 'SA', 'fiscal_legal_name' => 'Riyadh Diner', 'fiscal_vat_number' => '300000000000003', 'currency_code' => 'SAR'] + $options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    /**
     * @param  list<array{0:string,1:float,2:int}>  $items
     * @param  array<string,mixed>  $attrs
     */
    private function order(Store $store, array $items, array $attrs = []): Order
    {
        $order = Order::create($attrs + ['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        foreach ($items as [$name, $price, $qty]) {
            $menu = Menu::create(['store_id' => $store->id, 'name' => $name, 'price' => $price, 'active' => '1']);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty]);
        }
        // P6-186 (J44): a KSA store is on the VAT engine; these tests pin the legacy (P6-99) path, i.e. an order placed
        // before the store moved to the engine (the backfill marks it)
        $order->forceFill(['tax_lines' => TaxEngine::LEGACY_MARKER])->saveQuietly();

        return $order->fresh();
    }

    public function test_delivery_order_with_fee_tip_and_discount(): void
    {
        $store = $this->store(['restaurant_taxx_charge' => 15]);
        $order = $this->order($store, [['Kabsa', 42.5, 2], ['Tea', 3.25, 3]], ['type' => 'delivery', 'delivery_fee' => 11.5, 'tip_amount' => 5, 'discount_amount' => 11.5]);
        $money = $order->computeMoney();
        $this->assertSame(12.49, $money['tax']);
        $grandMinor = (int) round($money['grand'] * 100);

        $ctx = OrderFiscalContext::for($store, $order);
        $this->assertSame(['Kabsa', 'Tea', 'Delivery', 'Tip'], array_map(fn ($l) => $l->name, $ctx->lines));
        $this->assertSame([15.0, 15.0, 15.0, 0.0], array_map(fn ($l) => $l->rate, $ctx->lines));
        $this->assertSame(12.49, round(($ctx->lines[0]->tax ?? 0) + ($ctx->lines[1]->tax ?? 0), 2), "the items' VAT is the order's VAT");
        $this->assertSame(['S', 'S', 'S', 'O'], array_map(fn ($l) => $l->category, $ctx->lines));

        $f = FiscalInvoiceMath::compute($ctx, 2, dropOutOfScope: true);
        $this->assertSame(500, $f['excluded']);
        $this->assertSame(0, $f['rounding']);
        $this->assertSame($grandMinor, $f['payable'] + $f['excluded']);

        FiscalDevice::create(['store_id' => $store->id, 'device_key' => 'EGS-1', 'country' => 'SA', 'status' => 'new']);
        $doc = new ZatcaDocumentService(new ZatcaCrypto)->issue($store, $ctx, 'EGS-1');
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML((string) $doc->payload));
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('cac', UblBuilder::CAC);
        $xp->registerNamespace('cbc', UblBuilder::CBC);
        $this->assertSame(3.0, $xp->evaluate('count(//cac:InvoiceLine)'));
        $this->assertSame(FiscalInvoiceMath::fmt($grandMinor - 500, 2), $xp->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'));
        // P6-271: the 11.50 discount is on the item lines (VAT on what was paid), no document allowance
        $this->assertSame(0.0, $xp->evaluate('count(/*/cac:AllowanceCharge)'));
        $this->assertSame(1150, $f['lines'][0]['discount'] + $f['lines'][1]['discount']);
        $this->assertSame(FiscalInvoiceMath::major($f['tax_total'], 2), (float) $doc->tax_total);
    }

    public function test_untaxed_store_is_all_out_of_scope_and_keeps_the_tip(): void
    {
        $store = $this->store([]);
        $order = $this->order($store, [['Kabsa', 40, 1]], ['tip_amount' => 2]);
        $ctx = OrderFiscalContext::for($store, $order);
        $this->assertSame(['O', 'O'], array_map(fn ($l) => $l->category, $ctx->lines));
        $f = FiscalInvoiceMath::compute($ctx, 2, dropOutOfScope: true);
        $this->assertSame(0, $f['excluded']);
        $this->assertSame(4200, $f['payable']);
        $this->assertSame(0, $f['tax_total']);
    }
}
