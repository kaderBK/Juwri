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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Integrations\Models\OdooSyncRecord;
use Juwri\Modules\Integrations\Services\Odoo\OdooClient;
use Juwri\Modules\Integrations\Services\Odoo\OdooOrderMapper;
use Juwri\Modules\Integrations\Services\Odoo\OdooSyncQueue;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Tests\Support\FakeOdoo;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P4-21 Odoo connector (one-way Juwri → Odoo) against an in-memory Odoo JSON-RPC server: exact money (minor units,
 * the tax line, refunds as credit notes), idempotency by the order's UUIDv7, the retry queue with backoff, the
 * vault, the owner-only settings screen and its cross-store refusals.
 */
class OdooConnectorTest extends TestCase
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

    public function test_an_order_taxed_after_its_discount_reaches_odoo_exactly(): void
    {
        // P6-271: (19.99 − 2.50) × 15 % = 2.62 tax; 19.99 + 2.62 + 1.00 tip − 2.50 = 21.11. The discount line is taxed.
        $store = $this->store($this->owner());
        $order = $this->order($store, ['tax_pre_discount' => null]);
        $order->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertSame('synced', $rec->status, (string) $rec->last_error);
        $inv = $this->invoice();
        $this->assertSame(21.11, $inv['amount_total']);
        $this->assertSame(2.62, $inv['amount_tax']);
    }

    public function test_a_paid_order_reaches_odoo_exactly(): void
    {
        $store = $this->store($this->owner());
        $order = $this->order($store);
        $order->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertSame('synced', $rec->status, (string) $rec->last_error);
        $inv = $this->invoice();
        $this->assertSame($order->fresh()->public_id, $inv['ref'], 'the order UUIDv7 is the Odoo reference');
        $this->assertSame('posted', $inv['state']);
        $this->assertSame(1, $inv['currency_id']); // SAR
        $this->assertSame(21.49, $inv['amount_total']);
        $this->assertSame(3.0, $inv['amount_tax']);
        $this->assertEqualsWithDelta(21.49, Order::find($order->id)->computeMoney()['grand'], 0.000001);

        $lines = array_map(fn ($c) => $c[2], $inv['invoice_line_ids']);
        $this->assertSame([['Cake', 3, 3.33, [10]], ['Tea', 2, 5.0, [10]], ['Tip', 1, 1.0, []], ['Discount', 1, -2.5, []]],
            array_map(fn ($l) => [$l['name'], $l['quantity'], $l['price_unit'], $l['tax_ids'][0][2]], $lines));
        $this->assertSame(40, $lines[1]['product_id'], 'the SKU maps to the Odoo product');

        $partner = $this->odoo->rows['res.partner'][$inv['partner_id']];
        $this->assertSame(['Sara', Customer::find($order->customer_id)->email, 'juwri:'.$store->id.':c:'.$order->customer_id], [$partner['name'], $partner['email'], $partner['ref']]);

        $pay = $this->odoo->all('account.payment');
        $this->assertCount(1, $pay);
        $this->assertSame([20, 21.49, 'inbound'], [$pay[0]['journal_id'], $pay[0]['amount'], $pay[0]['payment_type']]); // cash journal

        $picking = $this->odoo->all('stock.picking');
        $this->assertCount(1, $picking);
        $this->assertSame(['done', $order->fresh()->public_id, 30], [$picking[0]['state'], $picking[0]['origin'], $picking[0]['picking_type_id']]);
        $this->assertSame([[40, 2]], array_map(fn ($m) => [$m[2]['product_id'], $m[2]['product_uom_qty']], $picking[0]['move_ids']), 'only SKU products move stock');
    }

    public function test_the_sync_is_idempotent_by_the_order_reference(): void
    {
        $store = $this->store($this->owner());
        $order = $this->order($store);
        $order->update(['is_paid' => 1]);
        $rec = OdooSyncRecord::query()->sole();

        // the same event again, the row run again, and a row that lost its ids: still one of each in Odoo
        $order->update(['is_paid' => 0]);
        $order->update(['is_paid' => 1]);
        app(OdooSyncQueue::class)->retry($rec->fresh());
        $rec->refresh()->forceFill(['status' => 'pending', 'result' => null])->save();
        app(OdooSyncQueue::class)->run((int) $rec->id);

        $this->assertSame(1, OdooSyncRecord::query()->count());
        $this->invoice();
        $this->assertSame(1, $this->odoo->created('account.move'));
        $this->assertSame(1, $this->odoo->created('res.partner'));
        $this->assertCount(1, $this->odoo->all('account.payment'));
        $this->assertCount(1, $this->odoo->all('stock.picking'));
    }

    public function test_a_partial_refund_is_a_credit_note_for_exactly_the_refunded_amount(): void
    {
        $store = $this->store($this->owner());
        $order = $this->order($store);
        $order->update(['is_paid' => 1]);
        $adj = OrderAdjustment::create(['store_id' => $store->id, 'order_id' => $order->id, 'type' => 'refund', 'amount_minor' => 500, 'currency_code' => 'SAR',
            'method' => 'card', 'reason' => 'Cold tea']);

        $rec = OdooSyncRecord::query()->where('kind', 'refund')->sole();
        $this->assertSame('synced', $rec->status, (string) $rec->last_error);
        $this->assertSame($order->fresh()->public_id.'/refund/'.$adj->id, $rec->reference);
        $credit = array_values(array_filter($this->odoo->all('account.move'), fn ($m) => $m['move_type'] === 'out_refund'));
        $this->assertCount(1, $credit);
        $this->assertSame([5.0, 0.65, 'posted', $this->invoice()['id']], [$credit[0]['amount_total'], $credit[0]['amount_tax'], $credit[0]['state'], $credit[0]['reversed_entry_id']]);
        $out = array_values(array_filter($this->odoo->all('account.payment'), fn ($p) => $p['payment_type'] === 'outbound'));
        $this->assertSame([[21, 5.0]], array_map(fn ($p) => [$p['journal_id'], $p['amount']], $out)); // card → bank journal

        // a full refund credits the invoice's own lines
        OrderAdjustment::create(['store_id' => $store->id, 'order_id' => $order->id, 'type' => 'void', 'amount_minor' => 2149, 'currency_code' => 'SAR', 'reason' => 'All']);
        $full = array_values(array_filter($this->odoo->all('account.move'), fn ($m) => $m['move_type'] === 'out_refund' && $m['amount_total'] === 21.49));
        $this->assertCount(1, $full);
        $this->assertSame(3.0, $full[0]['amount_tax']);
    }

    public function test_a_partial_refund_is_exact_for_every_amount_and_common_rates(): void
    {
        $mapper = new OdooOrderMapper;
        foreach ([15.0, 5.0, 14.0, 10.0, 7.5] as $rate) {
            foreach ([1, 7, 99, 500, 1234, 99999] as $gross) {
                $sale = ['currency' => 'SAR', 'lines' => [], 'untaxed_minor' => 100000, 'tax_minor' => 15000, 'total_minor' => 115000, 'fixed_tax' => false];
                $adj = new OrderAdjustment(['type' => 'refund', 'amount_minor' => $gross, 'reason' => 'x']);
                $credit = $mapper->refund($adj, $sale, $rate);
                $odooTotal = 0;
                foreach ($credit['lines'] as $l) {
                    $odooTotal += $l['price_minor'] * $l['quantity'] + ($l['taxed'] ? OdooOrderMapper::taxOf($l['price_minor'] * $l['quantity'], $rate) : 0);
                }
                $this->assertSame($gross, $odooTotal, "rate {$rate}, gross {$gross}: Odoo's total of the credit note");
                $this->assertSame($gross, $credit['total_minor']);
            }
        }
    }

    public function test_a_failure_is_retried_with_backoff_and_then_succeeds(): void
    {
        $store = $this->store($this->owner());
        $order = $this->order($store);
        $this->odoo->failNext = 1;
        $order->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertSame(['retrying', 1], [$rec->status, $rec->attempts]);
        $this->assertStringContainsString('503', (string) $rec->last_error);
        $this->assertEqualsWithDelta(now()->addSeconds(OdooSyncQueue::BACKOFF[0])->timestamp, $rec->next_attempt_at->timestamp, 2);

        $this->artisan('odoo:sync-due')->expectsOutputToContain('0 Odoo sync row(s)')->assertSuccessful();
        $this->travel(OdooSyncQueue::BACKOFF[0] + 1)->seconds();
        $this->artisan('odoo:sync-due')->expectsOutputToContain('1 Odoo sync row(s)')->assertSuccessful();
        $this->assertSame('synced', $rec->fresh()->status);
        $this->invoice();

        // after the last attempt the row fails and waits for the owner's Retry
        $other = $this->order($store);
        $this->odoo->failNext = 1000;
        $other->update(['is_paid' => 1]);
        $row = OdooSyncRecord::query()->where('order_id', $other->id)->sole();
        for ($i = 0; $i < count(OdooSyncQueue::BACKOFF); $i++) {
            app(OdooSyncQueue::class)->run((int) $row->id);
        }
        $this->assertSame(['failed', count(OdooSyncQueue::BACKOFF) + 1, null], [$row->fresh()->status, $row->fresh()->attempts, $row->fresh()->next_attempt_at]);
    }

    public function test_a_wrong_tax_mapping_posts_nothing_and_says_why(): void
    {
        $store = $this->store($this->owner());
        StoreSettings::set((int) $store->id, 'odoo_tax_id', '11'); // Odoo 10% for a 15% store
        $this->order($store)->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertSame('retrying', $rec->status);
        $this->assertStringContainsString('Juwri charged 21.49', (string) $rec->last_error);
        $this->assertSame([], $this->odoo->all('account.move'), 'the mismatching draft was deleted, nothing posted');
    }

    public function test_nothing_is_queued_for_a_store_that_is_not_connected_or_switched_off(): void
    {
        $store = $this->store($this->owner(), connect: false);
        $this->order($store)->update(['is_paid' => 1]);
        $off = $this->store($this->owner());
        StoreSettings::set((int) $off->id, 'odoo_enabled', '0');
        $this->order($off)->update(['is_paid' => 1]);

        $this->assertSame(0, OdooSyncRecord::query()->count());
        $this->assertSame([], $this->odoo->calls);
    }

    public function test_settings_keep_the_key_in_the_vault_and_only_the_owner_of_the_store_can_act(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice, connect: false);
        $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id, 'auth.password_confirmed_at' => time()])
            ->post(route('integrations.odoo.save'), ['url' => FakeOdoo::URL, 'database' => FakeOdoo::DB, 'username' => FakeOdoo::USER,
                'api_key' => FakeOdoo::KEY, 'enabled' => '1', 'odoo_tax_id' => 10, 'odoo_journal_cash_id' => 20])
            ->assertRedirect()->assertSessionHas('success');

        $raw = (string) DB::table('credentials')->where('store_id', $store->id)->where('feature', 'odoo')->value('secrets');
        $this->assertStringNotContainsString(FakeOdoo::KEY, $raw, 'the API key is encrypted at rest');
        $page = (string) $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id])->get(route('integrations.odoo.show'))->assertOk()->getContent();
        $this->assertStringNotContainsString(FakeOdoo::KEY, $page, 'the API key is never sent back');
        $this->assertStringContainsString('VAT 15%', $page, 'the mapping lists come from Odoo');

        // saving again with an empty key keeps it
        $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id, 'auth.password_confirmed_at' => time()])
            ->post(route('integrations.odoo.save'), ['url' => FakeOdoo::URL, 'database' => FakeOdoo::DB, 'username' => FakeOdoo::USER, 'api_key' => '', 'enabled' => '1'])
            ->assertSessionHas('success');

        // another owner: cannot retry Alice's row; their own dashboard store is theirs only
        $this->order($store)->update(['is_paid' => 1]);
        $row = OdooSyncRecord::query()->sole();
        $bob = $this->owner();
        $bobStore = $this->store($bob, connect: false);
        $this->app['auth']->forgetGuards();
        $this->actingAs($bob)->withSession(['dashboard_store_id' => $bobStore->id])->post(route('integrations.odoo.retry', $row->id))->assertNotFound();
        // pointing the dashboard at Alice's store falls back to Bob's own: nothing of Alice's connection or rows
        $bobPage = (string) $this->actingAs($bob)->withSession(['dashboard_store_id' => $store->id])->get(route('integrations.odoo.show'))->assertOk()->getContent();
        $this->assertStringNotContainsString(FakeOdoo::URL, $bobPage);
        $this->assertStringNotContainsString('data-odoo-record="'.$row->id.'"', $bobPage);
    }

    public function test_in_production_private_addresses_are_refused_even_with_a_stale_switch(): void
    {
        $this->app['env'] = 'production';
        putenv('ODOO_ALLOW_PRIVATE_HOSTS=true'); // the removed blanket switch, left in an old .env
        config(['services.odoo.allow_private_hosts' => true, 'services.odoo.private_hosts' => '']);
        foreach (['http://127.0.0.1:8069', 'https://127.0.0.1', 'http://10.0.0.5:8069', 'https://169.254.169.254/latest', 'http://[::1]:8069',
            'file:///etc/passwd', 'gopher://10.0.0.5', 'https://user:pw@93.184.216.34'] as $url) {
            $this->assertFalse(OdooClient::urlAllowed($url), $url);
        }

        // the save path refuses it too
        $alice = $this->owner();
        $store = $this->store($alice, connect: false);
        $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id, 'auth.password_confirmed_at' => time()])
            ->post(route('integrations.odoo.save'), ['url' => 'http://127.0.0.1:8069', 'database' => 'x', 'username' => 'y', 'api_key' => 'z'])
            ->assertSessionHasErrors('url');
        $this->assertNull(Credential::query()->where('store_id', $store->id)->first());
        putenv('ODOO_ALLOW_PRIVATE_HOSTS');
    }

    public function test_only_an_exact_allowlist_opens_a_private_host_and_the_connection_is_pinned(): void
    {
        $this->app['env'] = 'production';
        foreach (['*', '10.0.0.0/8', '10.*', ' , '] as $wildcard) {
            config(['services.odoo.private_hosts' => $wildcard]);
            $this->assertFalse(OdooClient::urlAllowed('http://10.0.0.5:8069'), "allowlist {$wildcard}");
        }
        config(['services.odoo.private_hosts' => 'odoo.lan, 10.0.0.5']);
        $this->assertSame(['odoo.lan', '10.0.0.5'], OdooClient::privateHosts());
        $this->assertSame([CURLOPT_RESOLVE => ['10.0.0.5:8069:10.0.0.5']], OdooClient::target('http://10.0.0.5:8069')['curl'] ?? null);
        $this->assertFalse(OdooClient::urlAllowed('http://10.0.0.6:8069'), 'a neighbour of a listed IP');
        $this->assertFalse(OdooClient::urlAllowed('http://127.0.0.1:8069'));
    }

    public function test_a_public_odoo_needs_https_so_the_key_never_travels_in_clear(): void
    {
        $this->app['env'] = 'production';
        config(['services.odoo.private_hosts' => '']);
        $this->assertFalse(OdooClient::urlAllowed('http://93.184.216.34'));
        $this->assertSame([CURLOPT_RESOLVE => ['93.184.216.34:443:93.184.216.34']], OdooClient::target('https://93.184.216.34')['curl'] ?? null);

        $alice = $this->owner();
        $store = $this->store($alice, connect: false);
        $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id, 'auth.password_confirmed_at' => time()])
            ->post(route('integrations.odoo.save'), ['url' => 'http://93.184.216.34', 'database' => 'x', 'username' => 'y', 'api_key' => 'z'])
            ->assertSessionHasErrors('url');
    }

    public function test_the_address_is_checked_again_when_sending(): void
    {
        $store = $this->store($this->owner());
        Credential::query()->where('store_id', $store->id)->sole()->update(['secrets' => ['url' => 'http://10.0.0.5:8069', 'database' => FakeOdoo::DB,
            'username' => FakeOdoo::USER, 'api_key' => FakeOdoo::KEY]]);
        $this->app['env'] = 'production'; // saved while it was allowed; not allowed any more
        config(['services.odoo.private_hosts' => '']);
        $this->order($store)->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertSame('retrying', $rec->status);
        $this->assertStringContainsString('not allowed', (string) $rec->last_error);
        Http::assertNothingSent();
    }

    public function test_a_refused_key_is_reported_without_the_key(): void
    {
        $store = $this->store($this->owner());
        Credential::query()->where('store_id', $store->id)->sole()->update(['secrets' => ['url' => FakeOdoo::URL, 'database' => FakeOdoo::DB, 'username' => FakeOdoo::USER, 'api_key' => 'wrong-key-999']]);
        $this->order($store)->update(['is_paid' => 1]);

        $rec = OdooSyncRecord::query()->sole();
        $this->assertSame('retrying', $rec->status);
        $this->assertStringContainsString('refused', (string) $rec->last_error);
        $this->assertStringNotContainsString('wrong-key-999', (string) $rec->last_error);
    }

    public function test_the_mapping_screen_tests_the_connection_shows_sku_matches_and_filters_the_history(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $this->order($store)->update(['is_paid' => 1]); // Tea has SKU TEA-1 (in Odoo); a second item has one that Odoo lacks
        Menu::query()->where('store_id', $store->id)->where('name', 'Cake')->update(['external_sku' => 'CAKE-X']);
        $ctx = ['dashboard_store_id' => $store->id];

        $this->actingAs($alice)->withSession($ctx)->post(route('integrations.odoo.test'))->assertRedirect()->assertSessionHas('success');
        $page = (string) $this->actingAs($alice)->withSession($ctx)->get(route('integrations.odoo.show'))->assertOk()->getContent();
        $this->assertStringContainsString('data-odoo-skus', $page);
        $this->assertStringContainsString('CAKE-X', $page, 'a SKU Odoo does not know is listed');
        $this->assertStringContainsString('data-odoo-group="payments"', $page);
        $this->assertStringContainsString('data-odoo-seen-methods', $page);

        OdooSyncRecord::query()->update(['status' => 'failed']);
        $failed = (string) $this->actingAs($alice)->withSession($ctx)->get(route('integrations.odoo.show', ['status' => 'failed']))->assertOk()->getContent();
        $this->assertStringContainsString('data-status="failed"', $failed);
        $synced = (string) $this->actingAs($alice)->withSession($ctx)->get(route('integrations.odoo.show', ['status' => 'synced']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-odoo-record=', $synced);

        // a refused key says so, without the key
        Credential::query()->where('store_id', $store->id)->sole()->update(['secrets' => ['url' => FakeOdoo::URL, 'database' => FakeOdoo::DB, 'username' => FakeOdoo::USER, 'api_key' => 'wrong-key-999']]);
        $res = $this->actingAs($alice)->withSession($ctx)->post(route('integrations.odoo.test'))->assertRedirect()->assertSessionHas('error');
        $this->assertStringNotContainsString('wrong-key-999', (string) session('error'));

        // another owner cannot see or use it
        $bob = $this->owner();
        $bobStore = $this->store($bob, connect: false);
        $this->app['auth']->forgetGuards();
        $this->actingAs($bob)->withSession(['dashboard_store_id' => $bobStore->id])->post(route('integrations.odoo.test'))->assertSessionHas('error', ___('Save the connection first.'));
    }
}
