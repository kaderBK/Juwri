<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDocumentService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-08b follow-up (coordinator, owner rule "fix every bug"): the fiscal document must carry exactly what the
 * customer was charged. An order line priced from a snapshot (happy-hour `unit_price`, the modifier's `price`
 * snapshot) after the live menu price changed: the order charges 2 × 6.00 + 2 × 1.00 = 14.00 + 15 % = 16.10.
 */
class FiscalOrderSnapshotTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true]);
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
    }

    private function store(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['fiscal_country' => 'SA', 'fiscal_vat_number' => '300000000000003', 'fiscal_legal_name' => 'Diner Co', 'restaurant_taxx_charge' => 15] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    /** One line: menu now 10.00 (sold at the 6.00 happy-hour snapshot) × 2, modifier now 3.00 (snapshot 1.00). */
    private function snapshotOrder(Store $store): Order
    {
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mojito', 'price' => 10, 'active' => '1']);
        $extra = MenuExtra::create(['store_id' => $store->id, 'title' => 'Mint', 'price' => 3]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 6]);
        OrderItemExtra::create(['order_item_id' => $line->id, 'extra_id' => $extra->id, 'price' => 1]);

        return $order->fresh();
    }

    public function test_snapshot_equal_to_live_price_keeps_the_same_numbers(): void
    {
        // Byte-identical guarantee for the common case (also pinned in full by FiscalDocumentParityTest).
        $store = $this->store();
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 10, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 10]);
        $ctx = OrderFiscalContext::for($store, $order->fresh());
        $this->assertEqualsWithDelta(23.00, $ctx->total, 1e-9);
        $this->assertEqualsWithDelta(3.00, $ctx->taxAmount, 1e-9);
    }

    public function test_the_fiscal_tax_is_the_tax_the_order_charged(): void
    {
        $store = $this->store();
        $order = $this->snapshotOrder($store);
        $charged = $order->computeMoney();
        $this->assertEqualsWithDelta(16.10, $charged['grand'], 1e-9);
        $this->assertEqualsWithDelta(2.10, $charged['tax'], 1e-9);

        $ctx = OrderFiscalContext::for($store, $order);
        $this->assertEqualsWithDelta(16.10, $ctx->total, 1e-9);
        // before: 3.90 — tax recomputed at the LIVE prices (2 × 10.00 + 2 × 3.00 = 26.00 × 15 %), so the document's
        // net (12.20) and tax (3.90) did not add up to what the customer paid for.
        $this->assertEqualsWithDelta(2.10, $ctx->taxAmount, 1e-9);

        FiscalDevice::create(['store_id' => $store->id, 'device_key' => 'EGS-1', 'country' => 'SA', 'status' => 'new']);
        $doc = new ZatcaDocumentService(new ZatcaCrypto)->issue($store, $ctx, 'EGS-1');
        $this->assertSame(1610, $doc->total_minor);
        $this->assertSame(210, $doc->tax_total_minor); // before: 390
        $this->assertStringContainsString('<cbc:TaxAmount currencyID="SAR">2.10</cbc:TaxAmount>', $doc->payload); // before: 3.90
        $this->assertStringContainsString('<cbc:LineExtensionAmount currencyID="SAR">14.00</cbc:LineExtensionAmount>', $doc->payload); // net, before: 12.20
    }
}
