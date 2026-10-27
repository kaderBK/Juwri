<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * #22 guest order-history lookup. Regression for the schema bug found during
 * the B6 browser pass: the endpoint queried `phone`/`email`/`total`/`token`
 * columns that don't exist (real columns: `phone_number`, `track_token`, a
 * computed `total` accessor, and email only via the linked customer), so every
 * lookup 500'd. B6's account-panel order history rides this route.
 */
class GuestOrderLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin LTR: the API `total` uses store_money_plain, whose order is locale-sensitive
        // (RTL flips to number-first). This test pins the sign+decimals, not RTL position.
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makePost(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_lookup_by_phone_returns_order_with_a_track_url(): void
    {
        $store = $this->makePost();
        StoreOption::updateStoreOption($store->id, 'currency_sign', '£');

        Order::create([
            'store_id' => $store->id, 'phone_number' => '0912345678',
            'status' => 'pending', 'type' => 'takeaway',
            'track_token' => 'TOK123ABC', 'created_at' => now(),
        ]);

        $resp = $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => '0912345678']);

        $resp->assertOk()->assertJson(['success' => true]);
        $resp->assertJsonPath('orders.0.token', 'TOK123ABC');
        $resp->assertJsonPath('orders.0.total', '£ 0.00'); // no items → store_money_plain: sign + space + zeroed accessor
        $resp->assertJsonPath('orders.0.track_url', route('store.orderTrack', 'TOK123ABC'));
    }

    public function test_lookup_by_email_matches_through_the_linked_customer(): void
    {
        $store = $this->makePost();
        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'Diner', 'email' => 'diner@ex.co', 'phone' => '0999',
        ]);
        Order::create([
            'store_id' => $store->id, 'customer_id' => $customer->id, 'phone_number' => '0999',
            'status' => 'completed', 'type' => 'delivery',
            'track_token' => 'TOKEMAIL', 'created_at' => now(),
        ]);

        $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => 'diner@ex.co'])
            ->assertOk()->assertJsonPath('orders.0.token', 'TOKEMAIL');
    }

    public function test_unknown_identifier_reports_no_orders(): void
    {
        $store = $this->makePost();

        $this->postJson(route('store.orderLookup', $store->slug), ['identifier' => 'nobody@nowhere.co'])
            ->assertOk()->assertJson(['success' => false]);
    }
}
