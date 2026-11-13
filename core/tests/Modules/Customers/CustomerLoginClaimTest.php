<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\GuestOrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL B1 (rev/guest-order-linking): logging in claims same-session guest
 * orders and reports the count for the UI toast.
 */
class CustomerLoginClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_login_claims_session_order_and_returns_count(): void
    {
        $r = $this->makeRestaurant();
        $me = Customer::create(['store_id' => $r->id, 'name' => 'Member', 'email' => 'm@test.com', 'password' => bcrypt('secret-123')]);
        $order = Order::create(['store_id' => $r->id, 'customer_name' => 'G']); // guest, unowned

        $this->withSession([GuestOrderSession::KEY => [[
            'order_id' => $order->id, 'store_id' => $r->id, 'placed_at' => now()->toIso8601String(),
        ]]])->postJson(route('customer.login', $r), [
            'email' => 'm@test.com', 'password' => 'secret-123', 'store_id' => $r->id,
        ])->assertOk()->assertJson(['success' => true, 'claimed_count' => 1]);

        $this->assertSame($me->id, $order->fresh()->customer_id);
    }

    public function test_login_without_stamps_returns_zero(): void
    {
        $r = $this->makeRestaurant();
        Customer::create(['store_id' => $r->id, 'name' => 'Member', 'email' => 'm@test.com', 'password' => bcrypt('secret-123')]);

        $this->postJson(route('customer.login', $r), [
            'email' => 'm@test.com', 'password' => 'secret-123', 'store_id' => $r->id,
        ])->assertOk()->assertJson(['success' => true, 'claimed_count' => 0]);
    }
}
