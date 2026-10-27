<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Marketplace;

use App\Models\Driver;
use Juwri\Core\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Marketplace T4-1: registry for platform fleet drivers.
 *
 * Fleet drivers live in the shared drivers table flagged is_fleet=1 with a
 * city_id; they have no owner_id/store_id. Proximity search is done in
 * PHP (prefix-safe — no raw SQL trig): pluck candidate coordinates, sort by
 * haversine distance then active_load.
 */
class FleetRegistry
{
    public const SHIFT_STATUSES = ['offline', 'online', 'break'];
    public const VEHICLE_TYPES = ['bike', 'car', 'van'];

    /**
     * Register a new platform fleet driver.
     *
     * @param array{name:string,city_id?:int|null,phone?:string|null,email?:string|null,vehicle_type?:string|null,capacity?:int} $attributes
     */
    public function register(array $attributes): Driver
    {
        return Driver::create([
            'owner_id' => null,
            'store_id' => null,
            'name' => $attributes['name'],
            'phone' => $attributes['phone'] ?? null,
            'email' => $attributes['email'] ?? null,
            'token' => hash('sha256', Str::random(40)),
            'active' => (bool) ($attributes['active'] ?? true),
            'is_fleet' => true,
            'city_id' => $attributes['city_id'] ?? null,
            'shift_status' => 'offline',
            'vehicle_type' => $this->vehicleType($attributes['vehicle_type'] ?? null),
            'capacity' => max(1, (int) ($attributes['capacity'] ?? 1)),
            'active_load' => 0,
        ]);
    }

    /**
     * Update mutable fleet fields on an existing fleet driver.
     */
    public function update(Driver $driver, array $attributes): Driver
    {
        $data = array_intersect_key($attributes, array_flip([
            'name', 'phone', 'email', 'active', 'city_id', 'vehicle_type', 'capacity',
        ]));

        if (array_key_exists('vehicle_type', $data)) {
            $data['vehicle_type'] = $this->vehicleType($data['vehicle_type']);
        }
        if (array_key_exists('capacity', $data)) {
            $data['capacity'] = max(1, (int) $data['capacity']);
        }

        $driver->fill($data)->save();

        return $driver;
    }

    /**
     * Transition a driver's shift status (offline | online | break).
     */
    public function setShift(Driver $driver, string $status): Driver
    {
        if (!in_array($status, self::SHIFT_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid shift status [{$status}].");
        }

        $driver->shift_status = $status;
        $driver->save();

        return $driver;
    }

    /**
     * Nearest available fleet drivers to a point, optionally scoped to a city.
     *
     * Availability = active + online + active_load < capacity + has a known
     * location. Sorted by haversine distance (Juwri\Core\Support\Geo, computed in
     * PHP) then by active_load ascending.
     *
     * @return Collection<int, Driver> each driver has a float distance_km attribute set
     */
    public function nearestAvailable(float $lat, float $lng, ?int $cityId = null, int $limit = 10): Collection
    {
        $query = Driver::query()
            ->fleet()
            ->online()
            ->where('active', true)
            ->whereColumn('active_load', '<', 'capacity')
            ->whereNotNull('last_lat')
            ->whereNotNull('last_lng');

        if ($cityId !== null) {
            $query->inCity($cityId);
        }

        return $query->get()
            ->map(function (Driver $driver) use ($lat, $lng) {
                $driver->setAttribute(
                    'distance_km',
                    Geo::distanceKm($lat, $lng, (float) $driver->last_lat, (float) $driver->last_lng)
                );

                return $driver;
            })
            ->sort(function (Driver $a, Driver $b) {
                return [$a->distance_km, (int) $a->active_load] <=> [$b->distance_km, (int) $b->active_load];
            })
            ->take($limit)
            ->values();
    }

    private function vehicleType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        if (!in_array($type, self::VEHICLE_TYPES, true)) {
            throw new InvalidArgumentException("Invalid vehicle type [{$type}].");
        }

        return $type;
    }
}
