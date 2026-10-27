<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Driver;
use App\Services\Marketplace\FleetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Marketplace T4-1: fleet schema + registry.
 */
class FleetRegistryTest extends TestCase
{
    use RefreshDatabase;

    private FleetRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new FleetRegistry();
    }

    private function fleetDriver(array $overrides = []): Driver
    {
        return Driver::create(array_merge([
            'owner_id' => null,
            'store_id' => null,
            'name' => 'Fleet Driver',
            'token' => hash('sha256', uniqid('', true)),
            'active' => true,
            'is_fleet' => true,
            'city_id' => 1,
            'shift_status' => 'online',
            'capacity' => 2,
            'active_load' => 0,
        ], $overrides));
    }

    public function test_fleet_scope_excludes_store_drivers(): void
    {
        $store = Driver::create([
            'owner_id' => 5,
            'store_id' => 9,
            'name' => 'Store Driver',
            'token' => hash('sha256', uniqid('', true)),
            'active' => true,
        ]);
        $fleet = $this->fleetDriver();

        $ids = Driver::fleet()->pluck('id');
        $this->assertTrue($ids->contains($fleet->id));
        $this->assertFalse($ids->contains($store->id));

        // Store driver defaults: not fleet, offline.
        $this->assertFalse($store->fresh()->is_fleet);
        $this->assertSame('offline', $store->fresh()->shift_status);
    }

    public function test_register_creates_platform_driver_without_owner(): void
    {
        $driver = $this->registry->register([
            'name' => 'Amal',
            'city_id' => 3,
            'vehicle_type' => 'bike',
            'capacity' => 4,
        ]);

        $this->assertTrue($driver->is_fleet);
        $this->assertNull($driver->owner_id);
        $this->assertNull($driver->store_id);
        $this->assertSame(3, (int) $driver->city_id);
        $this->assertSame('bike', $driver->vehicle_type);
        $this->assertSame(4, $driver->capacity);
        $this->assertSame('offline', $driver->shift_status);
        $this->assertSame(0, $driver->active_load);
    }

    public function test_shift_transitions(): void
    {
        $driver = $this->fleetDriver(['shift_status' => 'offline']);

        $this->registry->setShift($driver, 'online');
        $this->assertSame('online', $driver->fresh()->shift_status);

        $this->registry->setShift($driver, 'break');
        $this->assertSame('break', $driver->fresh()->shift_status);

        $this->registry->setShift($driver, 'offline');
        $this->assertSame('offline', $driver->fresh()->shift_status);

        $this->expectException(InvalidArgumentException::class);
        $this->registry->setShift($driver, 'lunch');
    }

    public function test_is_available_helper(): void
    {
        $ok = $this->fleetDriver(['capacity' => 2, 'active_load' => 1]);
        $full = $this->fleetDriver(['capacity' => 2, 'active_load' => 2]);
        $offline = $this->fleetDriver(['shift_status' => 'offline']);
        $inactive = $this->fleetDriver(['active' => false]);

        $this->assertTrue($ok->isAvailable());
        $this->assertFalse($full->isAvailable());
        $this->assertFalse($offline->isAvailable());
        $this->assertFalse($inactive->isAvailable());
    }

    public function test_nearest_available_orders_by_distance(): void
    {
        // Query point: Riyadh-ish 24.7136, 46.6753
        $near = $this->fleetDriver(['name' => 'Near', 'last_lat' => 24.7200, 'last_lng' => 46.6800]);
        $mid = $this->fleetDriver(['name' => 'Mid', 'last_lat' => 24.8000, 'last_lng' => 46.7500]);
        $far = $this->fleetDriver(['name' => 'Far', 'last_lat' => 25.5000, 'last_lng' => 47.5000]);

        $result = $this->registry->nearestAvailable(24.7136, 46.6753, 1);

        $this->assertSame(
            [$near->id, $mid->id, $far->id],
            $result->pluck('id')->all()
        );
        $this->assertGreaterThan(0, $result->first()->distance_km);
        $this->assertLessThan($result->last()->distance_km, $result->first()->distance_km);
    }

    public function test_nearest_available_excludes_full_offline_and_no_location(): void
    {
        $available = $this->fleetDriver(['name' => 'Free', 'last_lat' => 24.71, 'last_lng' => 46.67]);
        $full = $this->fleetDriver(['name' => 'Full', 'last_lat' => 24.71, 'last_lng' => 46.67, 'capacity' => 1, 'active_load' => 1]);
        $offline = $this->fleetDriver(['name' => 'Off', 'last_lat' => 24.71, 'last_lng' => 46.67, 'shift_status' => 'offline']);
        $noLocation = $this->fleetDriver(['name' => 'NoLoc']);

        $ids = $this->registry->nearestAvailable(24.7136, 46.6753, 1)->pluck('id');

        $this->assertTrue($ids->contains($available->id));
        $this->assertFalse($ids->contains($full->id));
        $this->assertFalse($ids->contains($offline->id));
        $this->assertFalse($ids->contains($noLocation->id));
    }

    public function test_nearest_available_scopes_by_city_and_limits(): void
    {
        $city1 = $this->fleetDriver(['name' => 'C1', 'city_id' => 1, 'last_lat' => 24.71, 'last_lng' => 46.67]);
        $city2 = $this->fleetDriver(['name' => 'C2', 'city_id' => 2, 'last_lat' => 24.71, 'last_lng' => 46.67]);

        $ids = $this->registry->nearestAvailable(24.7136, 46.6753, 1)->pluck('id');
        $this->assertTrue($ids->contains($city1->id));
        $this->assertFalse($ids->contains($city2->id));

        // No city filter returns both; limit caps results.
        $all = $this->registry->nearestAvailable(24.7136, 46.6753, null);
        $this->assertCount(2, $all);
        $this->assertCount(1, $this->registry->nearestAvailable(24.7136, 46.6753, null, 1));
    }

    public function test_nearest_available_ties_break_on_active_load(): void
    {
        $busy = $this->fleetDriver(['name' => 'Busy', 'last_lat' => 24.71, 'last_lng' => 46.67, 'capacity' => 3, 'active_load' => 2]);
        $idle = $this->fleetDriver(['name' => 'Idle', 'last_lat' => 24.71, 'last_lng' => 46.67, 'capacity' => 3, 'active_load' => 0]);

        $result = $this->registry->nearestAvailable(24.7136, 46.6753, 1);

        $this->assertSame([$idle->id, $busy->id], $result->pluck('id')->all());
    }

    public function test_update_validates_vehicle_type(): void
    {
        $driver = $this->fleetDriver();

        $this->registry->update($driver, ['vehicle_type' => 'van', 'capacity' => 0, 'city_id' => 7]);
        $fresh = $driver->fresh();
        $this->assertSame('van', $fresh->vehicle_type);
        $this->assertSame(1, $fresh->capacity); // capacity floored to 1
        $this->assertSame(7, (int) $fresh->city_id);

        $this->expectException(InvalidArgumentException::class);
        $this->registry->update($driver, ['vehicle_type' => 'rocket']);
    }
}
