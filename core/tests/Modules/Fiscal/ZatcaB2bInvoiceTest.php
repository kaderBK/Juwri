<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Http\Requests\StoreSettingsRules;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Contracts\FiscalInvoices;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Issuing\ZatcaIssuer;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaPartiesMissing;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-176: a KSA merchant issues the standard (B2B) tax invoice of a paid order for a business buyer. The buyer is kept
 * on the document, the sale's simplified invoice is cancelled by a full credit note, ZATCA clears the standard invoice,
 * and a later refund credits it as a standard note with the same buyer. Missing seller / buyer details stop it before
 * any number is taken. The order's amounts never change.
 */
class ZatcaB2bInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeZatcaClient $fake;

    private User $owner;

    private const BUYER = [
        'buyer_name' => 'Acme Trading Co', 'buyer_vat' => '311111111100003', 'buyer_street' => 'Prince Sultan',
        'buyer_building_number' => '2322', 'buyer_additional_number' => '8228', 'buyer_district' => 'Al-Murabba',
        'buyer_city' => 'Riyadh', 'buyer_postal_code' => '23333',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true]);
        $this->fake = new FakeZatcaClient;
        $this->app->instance(ZatcaClient::class, $this->fake);
    }

    private function store(bool $address = true): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $this->owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $this->owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $opts = ['fiscal_country' => 'SA', 'fiscal_vat_number' => '300000000000003', 'fiscal_legal_name' => 'Diner Co', 'fiscal_cr_number' => '1010101010', 'restaurant_taxx_charge' => 15];
        if ($address) {
            $opts += ['fiscal_street' => 'King Fahd Road', 'fiscal_building_number' => '1234', 'fiscal_additional_number' => '5678',
                'fiscal_district' => 'Al Olaya', 'fiscal_city' => 'Riyadh', 'fiscal_postal_code' => '12211'];
        }
        foreach ($opts as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $svc = new ZatcaOnboarding($this->fake, new ZatcaCrypto);
        $device = $svc->ensureDevice($store->id, 'EGS-1');
        $svc->requestComplianceCsid($device, '123456', ['vat' => '300000000000003', 'country' => 'SA', 'common_name' => 'EGS-1']);
        $svc->runComplianceChecks($device->fresh(), array_fill(0, 6, ['xml' => '<Invoice/>']));
        $svc->requestProductionCsid($device->fresh());

        return $store;
    }

    /** 2 × 10.00 + 15 % = 23.00 (tax 3.00), paid → its simplified invoice. */
    private function paidOrder(Store $store): Order
    {
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 10, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 10]);
        $order->update(['is_paid' => 1]);

        return $order->fresh();
    }

    private function docs(Store $store): Collection
    {
        return FiscalDocument::where('store_id', $store->id)->orderBy('id')->get();
    }

    private function issue(Order $order, array $data = self::BUYER)
    {
        $this->withoutMiddleware();
        app()->forgetInstance('__dash_ids_map');

        return $this->actingAs($this->owner)->from('/order/'.$order->id)->post(route('stores.orders.taxInvoice', $order->id), $data);
    }

    public function test_owner_issues_a_b2b_tax_invoice_that_replaces_the_simplified_one(): void
    {
        $store = $this->store();
        $order = $this->paidOrder($store);
        $before = $order->computeMoney();
        $simplified = $this->docs($store)->sole();
        $this->assertSame('simplified', $simplified->type);

        $this->issue($order)->assertRedirect()->assertSessionHas('success');

        [, $credit, $standard] = $this->docs($store)->all();
        // the simplified invoice is cancelled by a full (simplified) credit note …
        $this->assertSame('credit_note', $credit->type);
        $this->assertSame('order:'.$order->id.':b2b-credit', $credit->source_ref);
        $this->assertSame(2300, $credit->total_minor);
        $this->assertStringContainsString('name="0200000">381', $credit->payload);
        $this->assertStringContainsString('<cbc:ID>'.$simplified->number.'</cbc:ID>', $credit->payload);
        $this->assertNull($credit->buyer);
        // … and the standard invoice names both parties, keeps the buyer, is cleared, same amounts
        $this->assertSame('standard', $standard->type);
        $this->assertSame('order:'.$order->id.':standard', $standard->source_ref);
        $this->assertSame(2300, $standard->total_minor);
        $this->assertSame(300, $standard->tax_total_minor);
        $this->assertStringContainsString('name="0100000">388', $standard->payload);
        $this->assertStringContainsString('<cbc:CompanyID>311111111100003</cbc:CompanyID>', $standard->payload);
        $this->assertStringContainsString('<cbc:PostalZone>12211</cbc:PostalZone>', $standard->payload);
        $this->assertStringContainsString('<cbc:PostalZone>23333</cbc:PostalZone>', $standard->payload);
        $this->assertSame('Acme Trading Co', $standard->buyer['name']);
        $this->assertSame('SA', $standard->buyer['country']);
        $this->assertContains('clearInvoice', $this->fake->calls);
        $this->assertSame($before, $order->fresh()->computeMoney(), 'charged amounts never change');

        // idempotent: a second submit and the paid hook replay issue nothing new
        $this->issue($order)->assertSessionHas('success');
        $again = app(FiscalInvoices::class)->invoice($store, OrderFiscalContext::for($store, $order), 'order:'.$order->id);
        $this->assertSame($simplified->id, $again->id);
        $this->assertCount(3, $this->docs($store));

        // a refund now credits the standard invoice: a standard note with the same buyer, cleared
        $calls = count(array_keys($this->fake->calls, 'clearInvoice', true));
        OrderAdjustment::create(['order_id' => $order->id, 'type' => 'refund', 'amount' => 11.50, 'method' => 'cash', 'reason' => 'Wrong item']);
        $note = $this->docs($store)->last();
        $this->assertSame('credit_note', $note->type);
        $this->assertStringContainsString('name="0100000">381', $note->payload);
        $this->assertStringContainsString('<cbc:ID>'.$standard->number.'</cbc:ID>', $note->payload);
        $this->assertSame('311111111100003', $note->buyer['vat']);
        $this->assertSame($calls + 1, count(array_keys($this->fake->calls, 'clearInvoice', true)), 'a standard note is cleared');
    }

    public function test_missing_seller_address_blocks_the_invoice_and_takes_no_number(): void
    {
        $store = $this->store(address: false);
        $order = $this->paidOrder($store);
        $count = $this->docs($store)->count();

        $this->issue($order)->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'The tax invoice was not issued')
            && str_contains((string) $m, 'Postal code'));
        $this->assertCount($count, $this->docs($store), 'no credit note and no standard document');

        // the service path refuses the same way (no invented data, no invalid XML)
        $this->expectException(ZatcaPartiesMissing::class);
        app(FiscalInvoices::class)->standardInvoice($store, OrderFiscalContext::for($store, $order), ['name' => 'X', 'vat' => '311111111100003']);
    }

    public function test_buyer_details_are_validated_and_an_unpaid_order_waits(): void
    {
        $store = $this->store();
        $order = $this->paidOrder($store);
        $this->issue($order, ['buyer_vat' => '211111111100003', 'buyer_building_number' => '23', 'buyer_postal_code' => '2333', 'buyer_additional_number' => '1'] + self::BUYER)
            ->assertSessionHasErrors(['buyer_vat', 'buyer_building_number', 'buyer_postal_code', 'buyer_additional_number']);
        $this->issue($order, ['buyer_name' => ''] + self::BUYER)->assertSessionHasErrors(['buyer_name']);
        $this->assertSame(['simplified'], $this->docs($store)->pluck('type')->all());

        $unpaid = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $this->issue($unpaid)->assertSessionHas('error');
        $this->assertSame(0, FiscalDocument::where('order_id', $unpaid->id)->count());
    }

    public function test_a_retry_after_the_replacing_note_does_not_credit_twice(): void
    {
        $store = $this->store();
        $order = $this->paidOrder($store);
        $simplified = $this->docs($store)->sole();
        // the B2B issue failed after the simplified invoice was already credited (e.g. a signing error): the note exists
        app(ZatcaIssuer::class)->creditNote($store, OrderFiscalContext::for($store, $order),
            'order:'.$order->id.':b2b-credit', 'Replaced by a tax invoice for a business buyer', $simplified,
            ['key' => FiscalDevice::where('store_id', $store->id)->value('device_key')], null);

        $this->issue($order)->assertSessionHas('success');
        $this->assertSame(['simplified', 'credit_note', 'standard'], $this->docs($store)->pluck('type')->all());
    }

    public function test_a_partly_credited_sale_is_not_replaced(): void
    {
        $store = $this->store();
        $order = $this->paidOrder($store);
        OrderAdjustment::create(['order_id' => $order->id, 'type' => 'refund', 'amount' => 5, 'method' => 'cash', 'reason' => 'x']);
        $count = $this->docs($store)->count();

        $this->issue($order)->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'credit note'));
        $this->assertCount($count, $this->docs($store));
    }

    public function test_store_settings_check_saudi_formats_for_ksa_only(): void
    {
        $store = $this->store();
        $rules = StoreSettingsRules::rules($store, ['tax']);
        $bad = ['fiscal_country' => 'SA', 'fiscal_vat_number' => '300000000000002', 'fiscal_building_number' => '123',
            'fiscal_additional_number' => '12345', 'fiscal_postal_code' => '1221'];
        $v = Validator::make($bad, $rules);
        $this->assertTrue($v->fails());
        $this->assertEqualsCanonicalizing(['fiscal_vat_number', 'fiscal_building_number', 'fiscal_additional_number', 'fiscal_postal_code'], array_keys($v->errors()->toArray()));

        $good = ['fiscal_country' => 'SA', 'fiscal_vat_number' => '300000000000003', 'fiscal_building_number' => '1234',
            'fiscal_additional_number' => '', 'fiscal_postal_code' => '12211', 'fiscal_street' => 'King Fahd Road', 'fiscal_city' => 'Riyadh', 'fiscal_district' => 'Al Olaya'];
        $this->assertFalse(Validator::make($good, $rules)->fails());
        // another country keeps the old shape check only
        $this->assertFalse(Validator::make(['fiscal_country' => 'AE', 'fiscal_vat_number' => '100000000000003', 'fiscal_postal_code' => '00000-1'], $rules)->fails());
        // a partial save without the country uses the stored one (SA)
        $this->assertTrue(Validator::make(['fiscal_postal_code' => '1221'], $rules)->fails());
    }

    public function test_settings_screen_shows_the_national_address_for_ksa(): void
    {
        $store = $this->store();
        $this->assertSame('12211', (string) store_options($store->id, 'fiscal_postal_code'));
        $ctx = OrderFiscalContext::for($store, $this->paidOrder($store));
        $this->assertSame(['street' => 'King Fahd Road', 'building_number' => '1234', 'additional_number' => '5678', 'district' => 'Al Olaya',
            'city' => 'Riyadh', 'postal_code' => '12211', 'country' => 'SA'], $ctx->sellerAddress);
    }
}
