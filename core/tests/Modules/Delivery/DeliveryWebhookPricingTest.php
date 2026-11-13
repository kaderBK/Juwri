<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Coupons & Wallets P7-3c [decision C, safe passthrough]: the generic delivery
 * webhook applies an OWNER coupon only when a first-party payload sends one, and
 * NEVER spends store credit (unauthenticated surface, D3).
 */
class DeliveryWebhookPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        // T3: the secure custom-delivery webhook requires a per-restaurant secret.
        Credential::create([
            'store_id' => $r->id, 'feature' => 'custom', 'provider' => 'custom',
            'secrets' => ['webhook_secret' => 'shh-secret'], 'active' => true,
        ]);

        return [$r, $item, $owner];
    }

    /** Post a signed custom-delivery webhook (HMAC-SHA256 over the raw JSON body). */
    private function signedPost(array $payload)
    {
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, 'shh-secret');

        return $this->call('POST', route('webhook.delivery.inbound', 'custom'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGGREGATOR_SIGNATURE' => $sig,
        ], $body);
    }

    public function test_delivery_webhook_applies_owner_coupon_when_sent(): void
    {
        [$r, $item, $owner] = $this->storeFixture();
        Coupon::create([
            'code' => 'DLV25', 'percentage' => 25, 'limit' => 5, 'used' => 0,
            'user_id' => $owner->id, 'coupon_type' => 'percentage',
            'issuer_scope' => 'owner', 'applies_to' => 'food_order',
        ]);

        $res = $this->signedPost([
            'id' => 'ext-1', 'store_id' => $r->id,
            'coupon_code' => 'DLV25',
            'items' => [['item_id' => $item->id, 'quantity' => 2]], // subtotal 20
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertEqualsWithDelta(5.0, (float) $order->discount_amount, 0.001, '25% of 20');
    }

    public function test_unsigned_webhook_is_rejected(): void
    {
        [$r, $item] = $this->storeFixture();
        // Same payload, no signature header → 401, no order created.
        $this->postJson(route('webhook.delivery.inbound', 'custom'), [
            'id' => 'ext-x', 'store_id' => $r->id,
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])->assertStatus(401);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    public function test_delivery_webhook_never_spends_store_credit(): void
    {
        [$r, $item, $owner] = $this->storeFixture();
        // A customer with credit at the masked phone — the unauthenticated webhook
        // must never touch it even if use_wallet is smuggled into the payload.
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => '999']);
        $acct = CustomerWalletService::accountFor($customer);
        $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
        LedgerService::post([
            ['account' => $clr, 'amount' => -50, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
            ['account' => $acct, 'amount' => 50, 'ref' => "seed:{$customer->id}:usr"],
        ]);

        $res = $this->signedPost([
            'id' => 'ext-2', 'store_id' => $r->id,
            'customer_phone' => '999', 'use_wallet' => true, 'customer_id' => $customer->id,
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertEqualsWithDelta(0.0, (float) ($order->wallet_applied ?? 0), 0.001, 'no credit spent on webhook');
        $this->assertEqualsWithDelta(50.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001);
    }

    public function test_delivery_webhook_no_coupon_is_inert(): void
    {
        [$r, $item] = $this->storeFixture();

        $res = $this->signedPost([
            'id' => 'ext-3', 'store_id' => $r->id,
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertEqualsWithDelta(0.0, (float) ($order->discount_amount ?? 0), 0.001);
    }
}
