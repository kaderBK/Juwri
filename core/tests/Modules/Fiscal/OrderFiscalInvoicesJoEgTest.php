<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;
use Juwri\Modules\Fiscal\Services\Eta\FakeEtaClient;
use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\FakeJofotaraClient;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-01 follow-up (coordinator): Jordan (JoFotara) and Egypt (ETA) issue at payment and refund through the same path as
 * ZATCA — a paid order's e-invoice, a refund's credit note / return receipt referencing it, each submitted by the
 * country's FiscalAdapter. Before: their document services existed but nothing called them.
 */
class OrderFiscalInvoicesJoEgTest extends TestCase
{
    use RefreshDatabase;

    private FakeJofotaraClient $jo;

    private FakeEtaClient $eta;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_jo' => true, 'settings.admin_allow_fiscal_eg' => true,
            'fiscal.adapters.JO.enabled' => true, 'fiscal.adapters.EG.enabled' => true]);
        $this->jo = new FakeJofotaraClient;
        $this->eta = new FakeEtaClient;
        $this->app->instance(JofotaraClient::class, $this->jo);
        $this->app->instance(EtaClient::class, $this->eta);
    }

    private function store(string $country, int $tax): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['fiscal_country' => $country, 'fiscal_vat_number' => '12345678', 'fiscal_legal_name' => 'Diner Co', 'restaurant_taxx_charge' => $tax] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function paidOrder(Store $store): Order
    {
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mansaf', 'price' => 10, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 10]);
        $order->fresh()->update(['is_paid' => 1]);

        return $order->fresh();
    }

    private function refund(Order $order, float $amount): OrderAdjustment
    {
        return OrderAdjustment::create(['order_id' => $order->id, 'type' => 'refund', 'amount' => $amount, 'method' => 'cash', 'reason' => 'Cold food']);
    }

    /** @return Collection<int, FiscalDocument> */
    private function docs(Store $store): Collection
    {
        return FiscalDocument::where('store_id', $store->id)->orderBy('id')->get();
    }

    public function test_jordan_issues_the_invoice_at_payment_and_a_credit_note_at_refund(): void
    {
        $store = $this->store('JO', 16);
        Credential::create(['store_id' => $store->id, 'feature' => 'jofotara', 'provider' => 'jofotara',
            'secrets' => ['client_id' => 'c', 'secret' => 's', 'activity_number' => 'a'], 'active' => true]);
        $order = $this->paidOrder($store); // 2 × 10 + 16 % = 23.20

        $inv = $this->docs($store)->sole();
        $this->assertSame(['JO', 'simplified', 'order:'.$order->id], [$inv->country, $inv->type, $inv->source_ref]);
        $this->assertEqualsWithDelta(23.20, (float) $inv->total, 0.001);
        $this->assertNotSame('draft', $inv->status, 'queued for the JoFotara adapter');
        $this->assertContains('submit', $this->jo->calls, 'submitted by the JoFotara adapter');

        $adj = $this->refund($order, 11.60);
        $note = FiscalDocument::where('store_id', $store->id)->where('type', 'credit_note')->sole();
        $this->assertSame(['JO', 'refund:'.$adj->id], [$note->country, $note->source_ref]);
        $this->assertSame($inv->number, $note->authority_response['envelope']['reference'] ?? null, 'references the invoice');
        $this->assertSame('381', $note->authority_response['envelope']['invoiceType'] ?? null);
        $this->assertEqualsWithDelta(1.60, (float) $note->tax_total, 0.001, 'tax pro rata: 11.60 × 3.20 / 23.20');
    }

    public function test_jordan_without_the_stores_own_credentials_issues_nothing(): void
    {
        $store = $this->store('JO', 16);
        Credential::create(['store_id' => null, 'feature' => 'jofotara', 'provider' => 'jofotara',
            'secrets' => ['client_id' => 'platform', 'secret' => 's', 'activity_number' => 'a'], 'active' => true]);
        $order = $this->paidOrder($store);
        $this->refund($order, 5);

        $this->assertCount(0, $this->docs($store), 'a platform credential never issues in a store\'s name');
    }

    public function test_egypt_issues_the_sale_receipt_at_payment_and_a_return_receipt_at_refund(): void
    {
        $store = $this->store('EG', 14);
        Credential::create(['store_id' => $store->id, 'feature' => 'eta', 'provider' => 'eta',
            'secrets' => ['rin' => '123456789', 'client_id' => 'cid', 'secret' => 'sec'], 'active' => true]);
        FiscalDevice::create(['store_id' => $store->id, 'device_key' => 'POS-9', 'serial' => 'POS-9', 'country' => 'EG', 'status' => 'registered', 'meta' => ['branchCode' => '3']]);
        $order = $this->paidOrder($store);

        $sale = $this->docs($store)->sole();
        $this->assertSame(['EG', 'simplified', 'POS-9', 'order:'.$order->id], [$sale->country, $sale->type, $sale->device_key, $sale->source_ref]);
        $receipt = json_decode((string) $sale->payload, true);
        $this->assertSame('s', $receipt['documentType']['receiptType']);
        $this->assertSame('3', $receipt['seller']['branchCode']);
        $this->assertSame('123456789', $receipt['seller']['rin']);

        $adj = $this->refund($order, 11.40);
        $ret = FiscalDocument::where('store_id', $store->id)->where('type', 'credit_note')->sole();
        $this->assertSame('refund:'.$adj->id, $ret->source_ref);
        $r = json_decode((string) $ret->payload, true);
        $this->assertSame('r', $r['documentType']['receiptType']);
        $this->assertSame($sale->uuid, $r['header']['referenceOldUUID'], 'the return references the sale receipt');
        $this->assertSame('11.40', $r['totalAmount']);
    }

    public function test_egypt_without_a_registered_device_issues_nothing(): void
    {
        $store = $this->store('EG', 14);
        Credential::create(['store_id' => $store->id, 'feature' => 'eta', 'provider' => 'eta', 'secrets' => ['rin' => '1'], 'active' => true]);
        $this->paidOrder($store);
        $this->assertCount(0, $this->docs($store));
    }
}
