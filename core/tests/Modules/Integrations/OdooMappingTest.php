<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Integrations\Models\OdooSyncRecord;
use Juwri\Modules\Integrations\Services\Odoo\OdooMapping;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\FakeOdoo;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P4-21 per-item Odoo mapping (per tax / payment method / order type) on top of the
 * P4-21 Odoo connector (one-way Juwri → Odoo) against an in-memory Odoo JSON-RPC server: exact money (minor units,
 * the tax line, refunds as credit notes), idempotency by the order's UUIDv7, the retry queue with backoff, the
 * vault, the owner-only settings screen and its cross-store refusals.
 */
class OdooMappingTest extends TestCase
{
    use RefreshDatabase;

    private FakeOdoo $odoo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->odoo = FakeOdoo::install();
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner, bool $connect = true): Store
    {
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'odoo-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Odoo shop',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '15');
        StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', 'percentage');
        if ($connect) {
            Credential::create(['store_id' => $store->id, 'feature' => 'odoo', 'provider' => 'odoo', 'active' => true,
                'secrets' => ['url' => FakeOdoo::URL, 'database' => FakeOdoo::DB, 'username' => FakeOdoo::USER, 'api_key' => FakeOdoo::KEY]]);
            foreach (['odoo_enabled' => '1', 'odoo_tax_id' => '10', 'odoo_journal_cash_id' => '20', 'odoo_journal_bank_id' => '21', 'odoo_picking_type_id' => '30'] as $k => $v) {
                StoreSettings::set((int) $store->id, $k, $v);
            }
        }

        return $store;
    }

    /** 3 × 3.33 + 2 × 5.00 (SKU TEA-1) = 19.99; VAT 15% = 3.00; tip 1.00; discount 2.50 → 21.49 SAR. */
    private function order(Store $store, array $extra = []): Order
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Main', 'active' => 1]);
        $cake = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Cake', 'price' => 3.33, 'active' => 1]);
        $tea = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Tea', 'price' => 5, 'active' => 1, 'external_sku' => 'TEA-1']);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Sara', 'phone' => UniquePhone::next('05'), 'email' => 'sara'.random_int(1000, 999999).'@example.test']);
        $order = Order::create(array_merge(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'is_paid' => 0, 'currency_code' => 'SAR',
            'track_token' => Str::random(32), 'customer_name' => 'Sara', 'phone_number' => '0501112222', 'customer_id' => $customer->id,
            'tip_amount_minor' => 100, 'discount_amount_minor' => 250, 'payment_method' => 'cash',
            // the amounts below are the pre-P6-271 rule (tax before the discount); the new rule has its own test
            'tax_pre_discount' => true], $extra));
        OrderItem::create(['order_id' => $order->id, 'item_id' => $cake->id, 'quantity' => 3]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $tea->id, 'quantity' => 2]);

        return $order->fresh();
    }

    private function invoice(): array
    {
        $moves = array_values(array_filter($this->odoo->all('account.move'), fn ($m) => $m['move_type'] === 'out_invoice'));
        $this->assertCount(1, $moves, 'exactly one invoice in Odoo');

        return $moves[0];
    }

    private function extras(): void
    {
        $this->odoo->rows['account.tax'][12] = ['id' => 12, 'name' => 'VAT 15% takeaway', 'amount' => 15.0, 'amount_type' => 'percent', 'price_include' => false, 'type_tax_use' => 'sale'];
        $this->odoo->rows['account.tax'][13] = ['id' => 13, 'name' => 'Zero', 'amount' => 0.0, 'amount_type' => 'percent', 'price_include' => false, 'type_tax_use' => 'sale'];
        $this->odoo->rows['account.journal'][22] = ['id' => 22, 'name' => 'Mada', 'type' => 'bank'];
        $this->odoo->rows['stock.picking.type'][31] = ['id' => 31, 'name' => 'Takeaway out', 'code' => 'outgoing', 'default_location_src_id' => [8, 'WH/Stock'], 'default_location_dest_id' => [5, 'Customers']];
    }

    public function test_mapped_payment_order_type_tax_and_picking_are_used(): void
    {
        $this->extras();
        $store = $this->store($this->owner());
        OdooMapping::write((int) $store->id, ['tax' => ['untaxed' => 13], 'payment' => ['cash' => 22], 'order_type' => ['takeaway' => ['tax_id' => 12, 'picking_type_id' => 31]]]);
        $this->order($store)->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertSame('synced', $rec->status, (string) $rec->last_error);
        $inv = $this->invoice();
        $this->assertSame(21.49, $inv['amount_total']);
        $this->assertSame([[12], [12], [13], [13]], array_map(fn ($c) => $c[2]['tax_ids'][0][2], $inv['invoice_line_ids']));
        $this->assertSame(22, $this->odoo->all('account.payment')[0]['journal_id']);
        $this->assertSame(31, $this->odoo->all('stock.picking')[0]['picking_type_id']);
    }

    public function test_store_tax_and_fallbacks_apply_when_the_order_type_is_not_mapped(): void
    {
        $this->extras();
        $store = $this->store($this->owner());
        OdooMapping::write((int) $store->id, ['tax' => ['store' => 12], 'payment' => ['card' => 22], 'order_type' => ['delivery' => ['picking_type_id' => 31]]]);
        $this->order($store)->update(['is_paid' => 1]);

        $this->assertSame('synced', OdooSyncRecord::query()->sole()->status);
        $this->assertSame([[12], [12], [], []], array_map(fn ($c) => $c[2]['tax_ids'][0][2], $this->invoice()['invoice_line_ids']));
        $this->assertSame(20, $this->odoo->all('account.payment')[0]['journal_id'], 'cash falls back to the cash journal');
        $this->assertSame(30, $this->odoo->all('stock.picking')[0]['picking_type_id']);
    }

    public function test_an_untaxed_mapping_to_a_non_zero_tax_fails_permanently(): void
    {
        $store = $this->store($this->owner());
        OdooMapping::write((int) $store->id, ['tax' => ['untaxed' => 11]]);
        $this->order($store)->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertNotSame('synced', $rec->status);
        $this->assertStringContainsString('0 %', (string) $rec->last_error);
        $this->assertSame([], array_values(array_filter($this->odoo->all('account.move'), fn ($m) => $m['state'] === 'posted')));
    }

    public function test_clean_drops_bad_keys_and_ids(): void
    {
        $this->assertSame(['tax' => ['store' => 5], 'payment' => ['mada' => 7], 'order_type' => ['takeaway' => ['tax_id' => 9]]], OdooMapping::clean([
            'tax' => ['store' => '5', 'untaxed' => 0, 'other' => 3],
            'payment' => ['mada' => 7, 'Bad Key' => 8, 'x' => '-1', 'y' => '1e3'],
            'order_type' => ['takeaway' => ['tax_id' => 9, 'picking_type_id' => 'abc', 'junk' => 1], 'empty' => []],
            'evil' => 1,
        ]));
    }

    public function test_mapping_endpoints_owner_only_validated_and_password_confirmed(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $ctx = ['dashboard_store_id' => $store->id, 'auth.password_confirmed_at' => time()];

        $json = $this->actingAs($alice)->withSession($ctx)->getJson(route('integrations.odoo.mapping'))->assertOk()->json();
        $this->assertSame(['tax' => [], 'payment' => [], 'order_type' => []], $json['map']);
        $this->assertContains('takeaway', $json['order_types']);
        $this->assertContains('cash', $json['payment_methods']);

        $payload = ['tax' => ['store' => 12], 'payment' => ['mada' => 22], 'order_type' => ['takeaway' => ['tax_id' => 12, 'picking_type_id' => 31]]];
        $this->actingAs($alice)->withSession($ctx)->putJson(route('integrations.odoo.mapping.save'), $payload)->assertOk()
            ->assertJsonPath('map.payment.mada', 22);
        $this->assertSame(31, OdooMapping::read((int) $store->id)['order_type']['takeaway']['picking_type_id']);

        foreach ([
            ['tax' => ['vat' => 1], 'payment' => [], 'order_type' => []],
            ['tax' => [], 'payment' => ['Bad Key' => 1], 'order_type' => []],
            ['tax' => [], 'payment' => ['mada' => -3], 'order_type' => []],
            ['tax' => [], 'payment' => [], 'order_type' => ['spaceship' => ['tax_id' => 1]]],
            ['tax' => [], 'payment' => [], 'order_type' => ['takeaway' => ['evil' => 1]]],
        ] as $bad) {
            $this->actingAs($alice)->withSession($ctx)->putJson(route('integrations.odoo.mapping.save'), $bad)->assertStatus(422);
        }
        $this->assertSame(22, OdooMapping::read((int) $store->id)['payment']['mada'], 'refused saves change nothing');

        // without a fresh password confirmation the save is refused
        $this->assertContains('password.confirm', app('router')->getRoutes()->getByName('integrations.odoo.mapping.save')->gatherMiddleware());

        // another owner cannot read or write this store's mapping through their own session
        $bob = $this->owner();
        $bobStore = $this->store($bob, false);
        $this->actingAs($bob)->withSession(['dashboard_store_id' => $bobStore->id, 'auth.password_confirmed_at' => time()])
            ->putJson(route('integrations.odoo.mapping.save'), ['tax' => [], 'payment' => ['x' => 1], 'order_type' => []])->assertOk();
        $this->assertSame(22, OdooMapping::read((int) $store->id)['payment']['mada']);
        $this->assertSame(['x' => 1], OdooMapping::read((int) $bobStore->id)['payment']);
    }
}
