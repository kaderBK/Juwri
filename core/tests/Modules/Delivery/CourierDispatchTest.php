<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Modules\Delivery\Services\Couriers\CourierRegistry;
use Juwri\Modules\Delivery\Services\Couriers\DispatchService;
use Juwri\Modules\Delivery\Services\Couriers\OwnFleetProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

class CourierDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(float $deliveryCharge = 5.0): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'restaurant_delivery_charge', $deliveryCharge);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');

        return $r;
    }

    private function deliveryOrder(Store $r): Order
    {
        return Order::create([
            'store_id' => $r->id, 'type' => 'delivery', 'status' => 'new',
            'delivery_status' => 'pending', 'created_at' => now(),
        ]);
    }

    public function test_own_fleet_is_always_available(): void
    {
        $r = $this->storeFixture();
        $providers = DispatchService::availableProviders($r->id);
        $keys = array_map(fn ($p) => $p->key(), $providers);
        $this->assertContains('own', $keys);
    }

    public function test_own_fleet_quote_uses_flat_delivery_charge(): void
    {
        $r = $this->storeFixture(7.5);
        $order = $this->deliveryOrder($r);
        $quote = new OwnFleetProvider()->quote($order);
        $this->assertSame('own', $quote->provider);
        $this->assertEqualsWithDelta(7.5, $quote->fee, 0.001);
    }

    public function test_quote_all_returns_own_fleet(): void
    {
        $r = $this->storeFixture(4.0);
        $order = $this->deliveryOrder($r);
        $quotes = DispatchService::quoteAll($order);
        $this->assertNotEmpty($quotes);
        $own = collect($quotes)->firstWhere('provider', 'own');
        $this->assertEqualsWithDelta(4.0, $own['fee'], 0.001);
    }

    public function test_dispatch_to_own_fleet_keeps_delivery_pending_and_no_courier_row(): void
    {
        $r = $this->storeFixture();
        $order = $this->deliveryOrder($r);

        $courierDelivery = DispatchService::dispatch($order, 'own');
        $this->assertNull($courierDelivery); // own fleet does not create a courier row
        $this->assertSame('pending', $order->fresh()->delivery_status);
        $this->assertSame(0, CourierDelivery::count());
    }

    public function test_unknown_provider_falls_back_to_own(): void
    {
        $r = $this->storeFixture();
        $order = $this->deliveryOrder($r);
        // A genuinely unregistered provider key → falls back to own fleet (no throw, no row).
        $this->assertNull(DispatchService::dispatch($order, 'nonexistent_courier'));
        $this->assertSame(0, CourierDelivery::count());
    }

    public function test_registry_get_own(): void
    {
        $this->assertSame('own', CourierRegistry::get('own')->key());
        $this->assertNull(CourierRegistry::get('nope'));
    }
}
