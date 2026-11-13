<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Mail\ShopperAccountErased;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P6-91: a shopper's own "download my data" / "delete my account", storefront (web) and customer app (API).
 * Own data only (another customer of the same store and the same person's account in another store untouched),
 * the store's sales records kept with the personal data stripped, sign-ins revoked, re-auth and rate limit enforced.
 */
class ShopperPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function store(string $title): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function customer(Store $store, string $name, string $email, ?string $password = 'secret-pass'): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => $name, 'email' => $email,
            'phone' => UniquePhone::next('+21891'), 'password' => $password ? Hash::make($password) : null]);
    }

    /** One row of the shopper's data in each common table; returns the order. */
    private function seedShopper(Customer $c): Order
    {
        $sid = (int) $c->store_id;
        $acc = (int) DB::table('stores')->where('id', $sid)->value('account_id');
        CustomerAddress::create(['customer_id' => $c->id, 'line' => $c->name.' Street 5', 'city' => 'Tripoli', 'is_default' => 1]);
        $order = Order::create(['store_id' => $sid, 'type' => 'takeaway', 'status' => 'completed', 'customer_id' => $c->id,
            'customer_name' => $c->name, 'phone_number' => (string) $c->phone, 'address' => $c->name.' Street 5', 'message' => 'ring twice']);
        DB::table('order_items')->insert(['order_id' => $order->id, 'store_id' => $sid, 'quantity' => 2, 'order_note' => 'Tea please']);
        $loyalty = DB::table('loyalty_accounts')->insertGetId(['account_id' => $acc, 'store_id' => $sid, 'customer_id' => $c->id, 'balance' => 40, 'created_at' => now()]);
        DB::table('loyalty_ledger_entries')->insert(['account_id' => $acc, 'store_id' => $sid, 'loyalty_account_id' => $loyalty, 'type' => 'earn', 'delta' => 40, 'balance_after' => 40, 'created_at' => now()]);
        DB::table('store_reviews')->insert(['account_id' => $acc, 'store_id' => $sid, 'customer_id' => $c->id, 'reviewer_name' => $c->name, 'stars' => 5, 'body' => 'Great', 'status' => 'approved', 'created_at' => now()]);
        DB::table('store_follows')->insert(['account_id' => $acc, 'store_id' => $sid, 'customer_id' => $c->id, 'created_at' => now()]);
        DB::table('customer_feedback')->insert(['account_id' => $acc, 'store_id' => $sid, 'customer_id' => $c->id, 'token' => Str::random(40), 'comment' => 'msg from '.$c->name, 'created_at' => now()]);
        DB::table('return_requests')->insert(['account_id' => $acc, 'store_id' => $sid, 'order_id' => $order->id, 'customer_id' => $c->id, 'resolution' => 'refund', 'reason' => 'broken, call '.$c->phone, 'lines' => '[]', 'created_at' => now()]);
        CustomerToken::issue($c, 'app');

        return $order;
    }

    /** @return array<string, int> rows of the customer per table */
    private function footprint(int $id): array
    {
        $out = [];
        foreach (['customer_addresses', 'loyalty_accounts', 'store_reviews', 'store_follows', 'customer_feedback', 'customer_tokens', 'orders', 'return_requests'] as $t) {
            $out[$t] = DB::table($t)->where('customer_id', $id)->count();
        }
        $out['customers'] = DB::table('customers')->where('id', $id)->count();

        return $out;
    }

    public function test_web_export_returns_only_own_data_of_this_store_and_is_logged(): void
    {
        $store = $this->store('Shop A');
        $other = $this->store('Shop B');
        $me = $this->customer($store, 'Alice', 'alice@ex.co');
        $neighbour = $this->customer($store, 'Bob', 'bob@ex.co');
        $meElsewhere = $this->customer($other, 'Alice', 'alice@ex.co'); // same person, another store: separate account
        $this->seedShopper($me);
        $this->seedShopper($neighbour);
        $this->seedShopper($meElsewhere);

        $res = $this->actingAs($me, 'customer')->post(route('customer.privacy.export', $store->id), ['password' => 'secret-pass']);
        $res->assertOk();
        $this->assertStringContainsString('attachment;', (string) $res->headers->get('Content-Disposition'));
        $json = json_decode($res->streamedContent(), true);
        $this->assertSame('juwri.shopper-export.v1', $json['format']);
        $this->assertSame((int) $store->id, $json['store']['id']);
        $this->assertSame('Alice', $json['customer']['name']);
        $this->assertArrayNotHasKey('password', $json['customer']);
        $this->assertCount(1, $json['tables']['orders']);
        $this->assertCount(1, $json['tables']['order_items']);
        $this->assertCount(1, $json['tables']['customer_addresses']);
        $this->assertCount(1, $json['tables']['loyalty_ledger_entries']);
        $this->assertSame('msg from Alice', $json['tables']['customer_feedback'][0]['comment']);
        $this->assertArrayNotHasKey('customer_tokens', $json['tables']);
        $this->assertArrayNotHasKey('token', $json['tables']['customer_feedback'][0]);
        $blob = json_encode($json);
        $this->assertStringNotContainsString('Bob', $blob);
        foreach ($json['tables'] as $rows) {
            foreach ($rows as $row) {
                if (isset($row['store_id'])) {
                    $this->assertSame((int) $store->id, (int) $row['store_id']);
                }
            }
        }
        $log = DB::table('activity_logs')->where('action', 'customer.data_export')->sole();
        $this->assertSame((int) $store->id, (int) $log->store_id);
        $this->assertStringNotContainsString('Alice', (string) $log->meta);
        $this->assertStringNotContainsString('alice@', (string) $log->meta);

        // orders CSV
        $csv = $this->actingAs($me, 'customer')->post(route('customer.privacy.export', $store->id), ['password' => 'secret-pass', 'format' => 'csv']);
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('order_id', $body);
        $this->assertStringContainsString(',2', $body);
        $this->assertStringNotContainsString('Bob', $body);
    }

    public function test_export_and_delete_require_re_authentication(): void
    {
        $store = $this->store('Shop A');
        $me = $this->customer($store, 'Alice', 'alice@ex.co');
        $this->seedShopper($me);

        // signed out: refused
        $this->postJson(route('customer.privacy.export', $store->id), ['password' => 'secret-pass'])->assertStatus(401);
        $this->postJson(route('customer.privacy.delete', $store->id), ['confirm' => 'DELETE', 'password' => 'secret-pass'])->assertStatus(401);
        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.export', $store->id), [])->assertStatus(423);
        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.export', $store->id), ['password' => 'wrong'])->assertStatus(423);
        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.delete', $store->id), ['confirm' => 'DELETE', 'password' => 'wrong'])->assertStatus(423);
        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.delete', $store->id), ['confirm' => 'yes', 'password' => 'secret-pass'])->assertStatus(422);
        $this->assertSame(1, DB::table('customers')->where('id', $me->id)->count());
        $this->assertSame(0, DB::table('activity_logs')->whereIn('action', ['customer.data_export', 'customer.self_erased'])->count());

        // another store's customer: refused
        $other = $this->store('Shop B');
        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.export', $other->id), ['password' => 'secret-pass'])->assertStatus(403);
    }

    public function test_passwordless_customer_proves_with_a_phone_code(): void
    {
        $store = $this->store('Shop A');
        $me = $this->customer($store, 'Alice', 'alice@ex.co', null);
        $otp = \Mockery::mock(SmsOtp::class);
        $otp->shouldReceive('request')->once()->andReturn([true, 'sent']);
        $otp->shouldReceive('verify')->andReturnUsing(fn ($phone, $code, OtpScope $scope) => $phone === $me->phone && $code === '123456' && $scope->equals(OtpScope::store((int) $store->id)));
        $this->app->instance(SmsOtp::class, $otp);

        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.code', $store->id))->assertOk();
        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.export', $store->id), ['code' => '000000'])->assertStatus(423);
        $this->actingAs($me, 'customer')->post(route('customer.privacy.export', $store->id), ['code' => '123456'])->assertOk();
    }

    public function test_export_is_rate_limited_to_three_a_day(): void
    {
        $store = $this->store('Shop A');
        $me = $this->customer($store, 'Alice', 'alice@ex.co');
        RateLimiter::clear('shopper-export:'.$me->id);
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($me, 'customer')->post(route('customer.privacy.export', $store->id), ['password' => 'secret-pass'])->assertOk();
        }
        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.export', $store->id), ['password' => 'secret-pass'])->assertStatus(429);
        $this->travel(25)->hours();
        $this->actingAs($me, 'customer')->post(route('customer.privacy.export', $store->id), ['password' => 'secret-pass'])->assertOk();
    }

    public function test_web_delete_erases_own_rows_keeps_sales_records_anonymised_and_leaves_others_untouched(): void
    {
        $store = $this->store('Shop A');
        $other = $this->store('Shop B');
        $me = $this->customer($store, 'Alice', 'alice@ex.co');
        $neighbour = $this->customer($store, 'Bob', 'bob@ex.co');
        $meElsewhere = $this->customer($other, 'Alice', 'alice@ex.co');
        $order = $this->seedShopper($me);
        $this->seedShopper($neighbour);
        $this->seedShopper($meElsewhere);
        DB::table('fiscal_documents')->insert(['account_id' => DB::table('stores')->where('id', $store->id)->value('account_id'), 'store_id' => $store->id,
            'country' => 'SA', 'type' => 'invoice', 'number' => 'INV-1', 'seq' => 1, 'order_id' => $order->id, 'status' => 'accepted', 'payload' => '{}', 'total_minor' => 100, 'tax_total_minor' => 13, 'currency' => 'SAR', 'created_at' => now()]);
        $before = [$this->footprint((int) $neighbour->id), $this->footprint((int) $meElsewhere->id)];

        $res = $this->actingAs($me, 'customer')->postJson(route('customer.privacy.delete', $store->id), ['confirm' => 'DELETE', 'password' => 'secret-pass']);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertFalse($this->isAuthenticated('customer'));

        // own rows gone, customer row gone, app tokens revoked
        $this->assertSame(0, DB::table('customers')->where('id', $me->id)->count());
        foreach (['customer_addresses', 'loyalty_accounts', 'store_reviews', 'store_follows', 'customer_feedback', 'customer_tokens'] as $t) {
            $this->assertSame(0, DB::table($t)->where('customer_id', $me->id)->count(), $t);
        }
        $this->assertSame(0, DB::table('loyalty_ledger_entries')->where('store_id', $store->id)->whereNotIn('loyalty_account_id', DB::table('loyalty_accounts')->select('id'))->count());

        // the tax records are kept, anonymised, not deleted
        $kept = DB::table('orders')->where('id', $order->id)->first();
        $this->assertNotNull($kept);
        $this->assertNull($kept->customer_id);
        $this->assertSame('', (string) $kept->customer_name);
        $this->assertSame('', (string) $kept->phone_number);
        $this->assertSame('', (string) $kept->address);
        $this->assertSame(1, DB::table('order_items')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('fiscal_documents')->where('order_id', $order->id)->count());
        $rr = DB::table('return_requests')->where('order_id', $order->id)->first();
        $this->assertNotNull($rr);
        $this->assertNull($rr->customer_id);
        $this->assertNull($rr->reason);

        // the neighbour and the same person's account in the other store are untouched
        $this->assertSame($before, [$this->footprint((int) $neighbour->id), $this->footprint((int) $meElsewhere->id)]);
        $this->assertSame('Bob', DB::table('orders')->where('customer_id', $neighbour->id)->value('customer_name'));

        // owner-visible audit entry, no personal data; confirmation mail
        $log = DB::table('activity_logs')->where('action', 'customer.self_erased')->sole();
        $this->assertSame((int) $store->id, (int) $log->store_id);
        $this->assertNull($log->subject_id);
        foreach (['Alice', 'alice@', (string) $me->phone] as $pii) {
            $this->assertStringNotContainsString($pii, (string) $log->meta);
        }
        Mail::assertSent(ShopperAccountErased::class, fn ($m) => $m->hasTo('alice@ex.co') && $m->storeName === 'Shop A');
    }

    public function test_delete_is_refused_while_an_order_is_in_progress(): void
    {
        $store = $this->store('Shop A');
        $me = $this->customer($store, 'Alice', 'alice@ex.co');
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'preparing', 'customer_id' => $me->id]);

        $this->actingAs($me, 'customer')->postJson(route('customer.privacy.delete', $store->id), ['confirm' => 'DELETE', 'password' => 'secret-pass'])->assertStatus(409);
        $this->assertSame(1, DB::table('customers')->where('id', $me->id)->count());
    }

    public function test_api_parity_export_and_delete_revoke_every_token(): void
    {
        $store = $this->store('Shop A');
        $me = $this->customer($store, 'Alice', 'alice@ex.co');
        $neighbour = $this->customer($store, 'Bob', 'bob@ex.co');
        $this->seedShopper($me);
        $this->seedShopper($neighbour);
        $raw = CustomerToken::issue($me, 'phone');
        $second = CustomerToken::issue($me, 'tablet');
        $auth = ['Authorization' => 'Bearer '.$raw];

        $this->postJson('/api/v1/customer/privacy/export', [], $auth)->assertStatus(423);
        $res = $this->post('/api/v1/customer/privacy/export', ['password' => 'secret-pass'], $auth);
        $res->assertOk();
        $json = json_decode($res->streamedContent(), true);
        $this->assertSame('Alice', $json['customer']['name']);
        $this->assertStringNotContainsString('Bob', json_encode($json));

        $this->postJson('/api/v1/customer/privacy/delete', ['password' => 'secret-pass'], $auth)->assertStatus(422);
        $this->postJson('/api/v1/customer/privacy/delete', ['confirm' => 'DELETE', 'password' => 'nope'], $auth)->assertStatus(423);
        $this->postJson('/api/v1/customer/privacy/delete', ['confirm' => 'DELETE', 'password' => 'secret-pass'], $auth)->assertOk();

        $this->assertSame(0, DB::table('customer_tokens')->where('customer_id', $me->id)->count());
        $this->getJson('/api/v1/customer/me', $auth)->assertStatus(401);
        $this->getJson('/api/v1/customer/me', ['Authorization' => 'Bearer '.$second])->assertStatus(401);
        $this->assertSame(1, DB::table('customers')->where('id', $neighbour->id)->count());
        $this->assertSame(1, DB::table('customer_tokens')->where('customer_id', $neighbour->id)->count(), "the neighbour's token is kept");
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'customer.self_erased')->count());
    }
}
