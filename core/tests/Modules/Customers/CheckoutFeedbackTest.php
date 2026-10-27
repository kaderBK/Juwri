<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The /send-order response carries two facts no theme used to read, so a diner
 * was left in the dark: a coupon they entered that did not apply (charged full
 * price), and lines that went sold-out / out-of-window at submit and were left
 * off the order. This pins that the response now reports both — the shared
 * checkout-feedback partial surfaces them.
 */
class CheckoutFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function makeItem(Store $store, string $name = 'Item', bool $soldOut = false): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name,
            'price' => 10.00, 'active' => 1, 'is_sold_out' => $soldOut,
        ]);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function order(Store $store, array $lines, array $extra = []): TestResponse
    {
        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode($lines),
            'name' => 'Feedback Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '555-2000',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    public function test_a_submitted_coupon_that_does_not_apply_is_reported(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);

        $res = $this->order($store, [['id' => $item->id, 'quantity' => 1, 'extras' => []]], [
            'coupon_code' => 'NOPE-'.Str::upper(Str::random(6)),
        ])->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertTrue($res->json('coupon_declined'), 'a non-applying coupon must flag coupon_declined');
    }

    public function test_no_coupon_means_not_declined(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);

        $res = $this->order($store, [['id' => $item->id, 'quantity' => 1, 'extras' => []]])->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertFalse($res->json('coupon_declined'));
        $this->assertSame([], $res->json('dropped_items'));
    }

    public function test_a_sold_out_line_is_reported_in_dropped_items(): void
    {
        $store = $this->makeRestaurant();
        $live = $this->makeItem($store, 'InStock');
        $sold = $this->makeItem($store, 'GoneNow', true);

        $res = $this->order($store, [
            ['id' => $live->id, 'quantity' => 1, 'extras' => []],
            ['id' => $sold->id, 'quantity' => 1, 'extras' => []],
        ])->assertOk();

        $this->assertTrue($res->json('success'), 'the order still places with the in-stock line');
        $this->assertContains('GoneNow', $res->json('dropped_items'));
        $this->assertNotContains('InStock', $res->json('dropped_items'));
    }

    public function test_wallet_ignored_flag_when_no_credit_applies(): void
    {
        $store = $this->makeRestaurant();
        $item = $this->makeItem($store);

        // A guest (no account) ticking "pay with store credit" — none can apply.
        $res = $this->order($store, [['id' => $item->id, 'quantity' => 1, 'extras' => []]], [
            'use_wallet' => '1',
        ])->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertTrue($res->json('wallet_ignored'));
    }

    /** The same silent-drop happens on both order pipelines; cover the unified one. */
    public function test_dropped_items_reported_in_the_unified_pipeline(): void
    {
        config(['settings.storefront_order_pipeline' => 'unified']);

        $store = $this->makeRestaurant();
        $live = $this->makeItem($store, 'InStock');
        $sold = $this->makeItem($store, 'GoneNow', true);

        $res = $this->order($store, [
            ['id' => $live->id, 'quantity' => 1, 'extras' => []],
            ['id' => $sold->id, 'quantity' => 1, 'extras' => []],
        ])->assertOk();

        $this->assertTrue($res->json('success'));
        $this->assertContains('GoneNow', $res->json('dropped_items'));
        $this->assertNotContains('InStock', $res->json('dropped_items'));
    }
}
