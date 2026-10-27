<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\GuestOrderSession;
use App\Services\OrderClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL A2 (rev/guest-order-linking): session-proof claiming — what it claims,
 * and (attacker tests) what it must never claim.
 */
class OrderClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function member(Store $r): Customer
    {
        return Customer::create(['store_id' => $r->id, 'name' => 'Member', 'email' => 'm'.Str::random(6).'@t.com', 'password' => bcrypt('secret-123')]);
    }

    public function test_claims_unowned_stamped_order_and_clears_stamp(): void
    {
        $r = $this->makeRestaurant();
        $order = Order::create(['store_id' => $r->id, 'customer_name' => 'G']);
        GuestOrderSession::stamp($order);
        $me = $this->member($r);

        $this->assertSame(1, OrderClaimService::claimSessionOrders($me));
        $this->assertSame($me->id, $order->fresh()->customer_id);
        $this->assertSame([], GuestOrderSession::entries());
    }

    public function test_claims_shadow_owned_order_by_merging_the_whole_shadow(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Shadow', 'phone' => '555-2222', 'source' => 'checkout']);
        $stamped = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'G']);
        $earlier = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'G']);
        GuestOrderSession::stamp($stamped);
        $me = $this->member($r);

        $this->assertSame(1, OrderClaimService::claimSessionOrders($me));
        // The whole shadow folded in: BOTH orders now mine, shadow row gone.
        $this->assertSame($me->id, $stamped->fresh()->customer_id);
        $this->assertSame($me->id, $earlier->fresh()->customer_id);
        $this->assertDatabaseMissing('customers', ['id' => $shadow->id]);
        $this->assertSame([], GuestOrderSession::entries());
    }

    /** ATTACKER: a stamped order owned by a REAL (passworded) account is never moved. */
    public function test_never_claims_order_owned_by_passworded_account(): void
    {
        $r = $this->makeRestaurant();
        $victim = $this->member($r);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $victim->id, 'customer_name' => 'V']);
        // Attacker somehow holds a stamp for the victim's order id (forged/stale session).
        GuestOrderSession::stamp($order);
        $attacker = $this->member($r);

        $this->assertSame(0, OrderClaimService::claimSessionOrders($attacker));
        $this->assertSame($victim->id, $order->fresh()->customer_id);
        $this->assertDatabaseHas('customers', ['id' => $victim->id]);
        // Stamp dropped so it is not retried forever.
        $this->assertSame([], GuestOrderSession::entries());
    }

    /** ATTACKER: cross-restaurant stamps are not claimable — and stay for their own store. */
    public function test_other_restaurants_stamp_not_claimed_and_retained(): void
    {
        $r = $this->makeRestaurant();
        $r2 = $this->makeRestaurant();
        $order = Order::create(['store_id' => $r2->id, 'customer_name' => 'G']);
        GuestOrderSession::stamp($order);
        $me = $this->member($r);

        $this->assertSame(0, OrderClaimService::claimSessionOrders($me));
        $this->assertNull($order->fresh()->customer_id);
        $this->assertCount(1, GuestOrderSession::entries());

        // ...and the same session claims it at ITS restaurant.
        $me2 = $this->member($r2);
        $this->assertSame(1, OrderClaimService::claimSessionOrders($me2));
        $this->assertSame($me2->id, $order->fresh()->customer_id);
    }

    public function test_expired_stamp_is_dropped_unclaimed(): void
    {
        $r = $this->makeRestaurant();
        $order = Order::create(['store_id' => $r->id, 'customer_name' => 'G']);
        session()->put(GuestOrderSession::KEY, [[
            'order_id' => $order->id,
            'store_id' => $r->id,
            'placed_at' => now()->subHours(GuestOrderSession::TTL_HOURS + 1)->toIso8601String(),
        ]]);
        $me = $this->member($r);

        $this->assertSame(0, OrderClaimService::claimSessionOrders($me));
        $this->assertNull($order->fresh()->customer_id);
        $this->assertSame([], GuestOrderSession::entries());
    }

    public function test_already_owned_stamp_is_a_noop_and_cleared(): void
    {
        $r = $this->makeRestaurant();
        $me = $this->member($r);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $me->id, 'customer_name' => 'M']);
        GuestOrderSession::stamp($order);

        $this->assertSame(0, OrderClaimService::claimSessionOrders($me));
        $this->assertSame($me->id, $order->fresh()->customer_id);
        $this->assertSame([], GuestOrderSession::entries());
    }
}
