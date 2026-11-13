<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Coordinator review of lane A: an owner switch for driver self-claim (off by default); when on, a driver can claim an
 * unassigned delivery order of THEIR store only.
 */
class DriverSelfClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_driver_app' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R', 'sub_title' => 't',
            'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    /** @return array{0: Driver, 1: string} */
    private function driver(User $owner, Store $store): array
    {
        $raw = Str::random(48);

        return [Driver::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'D', 'token' => hash('sha256', $raw), 'active' => true]), $raw];
    }

    private function delivery(Store $store): Order
    {
        return Order::create(['store_id' => $store->id, 'type' => 'delivery', 'status' => 'new', 'customer_name' => 'C', 'address' => 'x']);
    }

    private function api(string $token, string $method, string $path)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->json($method, '/api/v1/driver/'.$path);
    }

    public function test_self_claim_is_off_by_default_and_the_owner_can_switch_it(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        [, $token] = $this->driver($owner, $store);
        $order = $this->delivery($store);

        $this->api($token, 'GET', 'available')->assertOk()->assertJson(['data' => []]);
        $this->api($token, 'POST', "deliveries/{$order->id}/claim")->assertForbidden();

        $this->actingAs($owner)->post(route('drivers.selfClaim'), ['store_id' => $store->id, 'enabled' => '1'])->assertRedirect();
        $this->actingAs($owner)->get(route('drivers.index'))->assertOk()->assertSee('data-self-claim="'.$store->id.'"', false);
        $this->assertTrue((bool) store_options($store->id, 'driver_self_claim', 0));

        $this->actingAs($owner)->post(route('drivers.selfClaim'), ['store_id' => $store->id, 'enabled' => '0'])->assertRedirect();
        $this->assertFalse((bool) store_options($store->id, 'driver_self_claim', 0));
    }

    public function test_with_self_claim_on_a_driver_sees_and_claims_an_unassigned_order_of_their_store_once(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        [$driver, $token] = $this->driver($owner, $store);
        [, $second] = $this->driver($owner, $store);
        $order = $this->delivery($store);
        $this->actingAs($owner)->post(route('drivers.selfClaim'), ['store_id' => $store->id, 'enabled' => '1']);

        $this->api($token, 'GET', 'available')->assertOk()->assertJsonCount(1, 'data');
        $this->api($token, 'POST', "deliveries/{$order->id}/claim")->assertOk();

        $order->refresh();
        $this->assertSame($driver->id, (int) $order->driver_id);
        $this->assertSame('assigned', $order->delivery_status);
        $this->api($token, 'GET', 'deliveries')->assertOk()->assertJsonPath('data.0.id', $order->id);
        // a second driver of the same store finds it gone
        $this->api($second, 'POST', "deliveries/{$order->id}/claim")->assertStatus(409);
        $this->api($second, 'GET', 'available')->assertOk()->assertJson(['data' => []]);
    }

    public function test_a_driver_of_another_store_cannot_see_or_claim_the_order(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $storeA = $this->store($ownerA);
        $storeB = $this->store($ownerB);
        [, $tokenB] = $this->driver($ownerB, $storeB);
        $orderA = $this->delivery($storeA);
        $this->actingAs($ownerA)->post(route('drivers.selfClaim'), ['store_id' => $storeA->id, 'enabled' => '1']);
        $this->actingAs($ownerB)->post(route('drivers.selfClaim'), ['store_id' => $storeB->id, 'enabled' => '1']);

        $this->api($tokenB, 'GET', 'available')->assertOk()->assertJson(['data' => []]);
        $this->api($tokenB, 'POST', "deliveries/{$orderA->id}/claim")->assertNotFound();
        $this->assertNull($orderA->fresh()->driver_id);
    }

    public function test_another_owner_cannot_switch_this_stores_self_claim(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($intruder)->post(route('drivers.selfClaim'), ['store_id' => $store->id, 'enabled' => '1'])->assertNotFound();
        $this->assertFalse((bool) store_options($store->id, 'driver_self_claim', 0));
    }
}
