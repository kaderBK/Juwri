<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;




/**
 * T3: registry of available aggregator providers, keyed by provider name. Real
 * providers (Deliverect, Uber Eats) register here; tests can register a fake.
 */
class AggregatorRegistry
{
    /** @var array<string, AggregatorProvider> */
    private static array $providers = [];

    public static function register(AggregatorProvider $provider): void
    {
        self::$providers[$provider->key()] = $provider;
    }

    public static function get(string $key): ?AggregatorProvider
    {
        if (isset(self::$providers[$key])) {
            return self::$providers[$key];
        }
        // Lazily resolve the built-in providers on first use.
        foreach (self::builtins() as $provider) {
            self::$providers[$provider->key()] = $provider;
        }
        return self::$providers[$key] ?? null;
    }

    /** @return array<string> */
    public static function keys(): array
    {
        foreach (self::builtins() as $provider) {
            self::$providers[$provider->key()] = $provider;
        }
        return array_keys(self::$providers);
    }

    /** @return AggregatorProvider[] */
    private static function builtins(): array
    {
        $out = [];
        foreach ([
            CustomDeliveryProvider::class,
            DeliverectProvider::class,
            UberEatsProvider::class,
            \Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaProvider::class,
            \Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezProvider::class,
            // PARITY-RUN T7 — India. There is no auto-discovery here by design:
            // a driver is only reachable once it is listed in THIS array.
            \Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoProvider::class,
            \Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyProvider::class,
        ] as $class) {
            if (class_exists($class)) {
                $out[] = new $class();
            }
        }
        return $out;
    }
}
