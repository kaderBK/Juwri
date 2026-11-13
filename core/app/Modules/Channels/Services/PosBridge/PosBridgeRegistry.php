<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge;




/**
 * GAPS-RUN-2 B1: registry of available POS providers, keyed by provider name.
 * Real drivers (Square in P2, Clover in P3) register their builtin here; tests can
 * register a fake. Mirrors AggregatorRegistry.
 */
class PosBridgeRegistry
{
    /** @var array<string, PosBridgeProvider> */
    private static array $providers = [];

    public static function register(PosBridgeProvider $provider): void
    {
        self::$providers[$provider->key()] = $provider;
    }

    public static function get(string $key): ?PosBridgeProvider
    {
        if (isset(self::$providers[$key])) {
            return self::$providers[$key];
        }
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

    /** Reset registered providers (test helper). */
    public static function flush(): void
    {
        self::$providers = [];
    }

    /** @return PosBridgeProvider[] */
    private static function builtins(): array
    {
        $out = [];
        // Drivers land in P2 (Square) and P3 (Clover); guarded by class_exists so P1
        // stands alone.
        foreach ([
            \Juwri\Modules\Channels\Services\PosBridge\Square\SquareProvider::class,
            \Juwri\Modules\Channels\Services\PosBridge\Clover\CloverProvider::class,
            \Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsProvider::class,
            \Juwri\Modules\Channels\Services\PosBridge\Marn\MarnProvider::class,
        ] as $class) {
            if (class_exists($class)) {
                $out[] = new $class();
            }
        }
        return $out;
    }
}
