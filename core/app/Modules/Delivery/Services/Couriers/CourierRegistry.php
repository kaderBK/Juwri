<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers;


/**
 * T4: registry of courier providers. Own-fleet is always present; third-party couriers
 * (Uber Direct, DoorDash) register when their classes exist.
 */
class CourierRegistry
{
    /** @var array<string, CourierProvider> */
    private static array $providers = [];

    public static function register(CourierProvider $provider): void
    {
        self::$providers[$provider->key()] = $provider;
    }

    public static function get(string $key): ?CourierProvider
    {
        self::boot();
        return self::$providers[$key] ?? null;
    }

    /** @return CourierProvider[] */
    public static function all(): array
    {
        self::boot();
        return array_values(self::$providers);
    }

    private static function boot(): void
    {
        if (!isset(self::$providers['own'])) {
            self::$providers['own'] = new OwnFleetProvider();
        }
        foreach ([UberDirectProvider::class, DoorDashProvider::class] as $class) {
            if (class_exists($class)) {
                $p = new $class();
                self::$providers[$p->key()] = $p;
            }
        }
    }
}
