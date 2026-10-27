<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P6-302: the logged-in account order history is cursor-paginated (20 per page,
 * newest first) and hides abandoned unpaid orders (unpaid past their payment
 * window). A still-open unpaid order stays visible and is flagged.
 */
class CustomerOrderHistoryP6302Test extends TestCase
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

    private function makeStore(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCustomer(Store $store): Customer
    {
        return Customer::create([
            'store_id' => $store->id, 'name' => 'Sam', 'email' => 'sam@ex.co', 'phone' => '0912',
        ]);
    }

    private function makeOrder(Store $store, Customer $customer, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone_number' => $customer->phone,
            'status' => 'completed',
            'type' => 'delivery',
            'created_at' => now(),
        ], $overrides));
    }

    public function test_abandoned_unpaid_order_is_not_listed_as_placed(): void
    {
        $store = $this->makeStore();
        $customer = $this->makeCustomer($store);

        $placed = $this->makeOrder($store, $customer, ['status' => 'completed']);
        $abandoned = $this->makeOrder($store, $customer, ['status' => 'unpaid', 'created_at' => now()->subHours(1)]);

        $response = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.account', $store->id))
            ->assertOk();

        $ids = collect($response->json('orders'))->pluck('id')->all();

        $this->assertContains($placed->id, $ids, 'a placed order is listed');
        $this->assertNotContains($abandoned->id, $ids, 'an abandoned unpaid order is hidden');
    }

    public function test_still_open_unpaid_order_is_listed_and_flagged(): void
    {
        $store = $this->makeStore();
        $customer = $this->makeCustomer($store);

        $open = $this->makeOrder($store, $customer, ['status' => 'unpaid', 'created_at' => now()]);

        $response = $this->actingAs($customer, 'customer')
            ->getJson(route('customer.account', $store->id))
            ->assertOk();

        $order = collect($response->json('orders'))->firstWhere('id', $open->id);

        $this->assertNotNull($order, 'an unpaid order inside its window is listed');
        $this->assertTrue($order['payment_not_completed']);
    }

    public function test_order_history_is_cursor_paginated_twenty_per_page(): void
    {
        $store = $this->makeStore();
        $customer = $this->makeCustomer($store);

        for ($i = 0; $i < 60; $i++) {
            $this->makeOrder($store, $customer);
        }

        $this->actingAs($customer, 'customer');

        $page1 = $this->getJson(route('customer.account', $store->id))->assertOk();
        $orders1 = $page1->json('orders');
        $this->assertCount(20, $orders1);
        $next1 = $page1->json('next_before');
        $this->assertNotNull($next1);
        $this->assertSame(end($orders1)['id'], $next1);

        $page2 = $this->getJson(route('customer.account', $store->id).'?before='.$next1)->assertOk();
        $orders2 = $page2->json('orders');
        $this->assertCount(20, $orders2);
        $next2 = $page2->json('next_before');
        $this->assertNotNull($next2);
        $this->assertSame(end($orders2)['id'], $next2);

        $page3 = $this->getJson(route('customer.account', $store->id).'?before='.$next2)->assertOk();
        $orders3 = $page3->json('orders');
        $this->assertCount(20, $orders3);
        $this->assertNull($page3->json('next_before'));

        $allIds = collect([...$orders1, ...$orders2, ...$orders3])->pluck('id')->all();

        $this->assertCount(60, $allIds);
        $this->assertCount(60, array_unique($allIds), 'every order is reachable exactly once');

        $desc = $allIds;
        rsort($desc);
        $this->assertSame($desc, $allIds, 'history is returned newest-first across the cursor pages');
    }
}
