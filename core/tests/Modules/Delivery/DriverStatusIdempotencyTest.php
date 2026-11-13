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
 * P6-312: a replayed Idempotency-Key on the driver status call is acknowledged and never re-applied.
 */
class DriverStatusIdempotencyTest extends TestCase
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

    private function assigned(Store $store, Driver $driver): Order
    {
        return Order::create(['store_id' => $store->id, 'type' => 'delivery', 'status' => 'new', 'customer_name' => 'C', 'address' => 'x',
            'driver_id' => $driver->id, 'delivery_status' => 'assigned']);
    }

    private function putStatus(string $token, int $id, string $status, ?string $key)
    {
        $h = ['Authorization' => 'Bearer '.$token] + ($key ? ['Idempotency-Key' => $key] : []);

        return $this->withHeaders($h)->json('PUT', "/api/v1/driver/deliveries/{$id}/status", ['delivery_status' => $status]);
    }

    public function test_duplicate_replay_of_the_same_key_is_acknowledged_and_not_reapplied(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        [$driver, $token] = $this->driver($owner, $store);
        $order = $this->assigned($store, $driver);

        $this->putStatus($token, $order->id, 'picked_up', 'k-1')->assertOk();
        $this->putStatus($token, $order->id, 'out_for_delivery', 'k-2')->assertOk();
        // The queued first tap is replayed late: without a key this would be 422 (backwards); with the seen key it is a no-op 200.
        $this->putStatus($token, $order->id, 'picked_up', 'k-1')->assertOk()->assertJsonPath('delivery_status', 'out_for_delivery');
        $this->putStatus($token, $order->id, 'picked_up', 'k-3')->assertStatus(422);
        $this->assertSame('out_for_delivery', $order->fresh()->delivery_status);
    }

    public function test_a_rejected_attempt_does_not_burn_its_key_and_keys_are_per_driver(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        [$d1, $t1] = $this->driver($owner, $store);
        [$d2, $t2] = $this->driver($owner, $store);
        $o1 = $this->assigned($store, $d1);
        $o2 = $this->assigned($store, $d2);

        $this->putStatus($t1, $o1->id, 'bogus', 'same')->assertStatus(422);
        $this->putStatus($t1, $o1->id, 'picked_up', 'same')->assertOk();
        $this->assertSame('picked_up', $o1->fresh()->delivery_status);
        $this->putStatus($t2, $o2->id, 'picked_up', 'same')->assertOk();
        $this->assertSame('picked_up', $o2->fresh()->delivery_status);
    }
}
