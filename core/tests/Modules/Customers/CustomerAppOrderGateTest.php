<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Menu\Models\Menu;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P6-348: POST /api/v1/customer/orders passes the same intake gate as the web
 * checkout (OrderIntakeGate) — closed store, paused branch, minimum basket — while
 * a scheduled pre-order may still be placed for when the store opens.
 */
class CustomerAppOrderGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    /** @return array{0: Store, 1: Menu, 2: string} */
    private function shop(float $price = 3.0): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 9, 'allow_ordering' => '1']]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'g-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'G', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1']);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Tea', 'price' => $price, 'active' => 1]);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('56-'), 'total_spent' => 0, 'orders_count' => 0]);

        return [$store, $item, CustomerToken::issue($customer)];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function place(string $token, Menu $item, int $qty = 1, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/customer/orders', array_merge([
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => $qty]],
        ], $extra), ['Authorization' => 'Bearer '.$token]);
    }

    public function test_open_store_without_minimum_takes_the_order(): void
    {
        [$store, $item, $token] = $this->shop();

        $this->place($token, $item)->assertCreated();
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_manually_closed_store_refuses(): void
    {
        [$store, $item, $token] = $this->shop();
        StoreOption::updateStoreOption($store->id, 'open_close_store', '0');

        $this->place($token, $item)->assertStatus(422)->assertJsonPath('error', 'store_closed');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    public function test_paused_branch_refuses_with_its_closed_message(): void
    {
        [$store, $item, $token] = $this->shop();
        $branch = Branch::firstOrCreate(['store_id' => $store->id], ['name' => 'Main']);
        $branch->forceFill(['active' => true, 'accepting_orders' => false, 'closed_message' => 'Back at 6pm'])->save();

        $this->place($token, $item)->assertStatus(422)
            ->assertJsonPath('error', 'store_closed')
            ->assertJsonPath('message', 'Back at 6pm');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    public function test_below_minimum_refuses_and_at_minimum_is_taken(): void
    {
        [$store, $item, $token] = $this->shop(3.0);
        StoreOption::updateStoreOption($store->id, 'min_order_value', '15');

        $this->place($token, $item, 1)->assertStatus(422)->assertJsonPath('error', 'below_minimum');
        $this->assertSame(0, Order::where('store_id', $store->id)->count());

        $this->place($token, $item, 5)->assertCreated();
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_scheduled_pre_order_is_not_refused_as_closed(): void
    {
        [$store, $item, $token] = $this->shop();
        StoreOption::updateStoreOption($store->id, 'open_close_store', '0');

        $res = $this->place($token, $item, 1, ['scheduled_at' => now()->addDay()->setTime(12, 0)->toIso8601String()]);

        $this->assertNotSame('store_closed', $res->json('error'));
    }
}
