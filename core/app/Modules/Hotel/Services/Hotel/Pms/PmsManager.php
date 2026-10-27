<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel\Pms;

use App\Services\Hotel\Pms\OperaCloudConnector;
use Juwri\Core\Models\Credential;

/**
 * FEATURES-RUN-4 T1 / H10 — resolves the PMS connector for a restaurant.
 *
 * Config lives in a {@see Credential} row with feature='pms': the `provider`
 * column selects the driver (opera_cloud|mews|manual|null) and `secrets` holds
 * the endpoint/keys. Mirrors {@see \Juwri\Modules\Channels\Services\PosBridge\PosBridgeRegistry}:
 * builtins register themselves (guarded by class_exists so this seam stands
 * alone), tests can register a fake, and an unknown/absent provider falls back
 * to {@see NullPmsConnector} so the module never hard-fails on missing config.
 *
 * The available provider keys (opera_cloud, mews) are DEFERRED-LIVE: the picker
 * is present in the owner UI but the live drivers are not written — they need
 * OPERA Cloud / Mews sandbox credentials.
 */
class PmsManager
{
    public const FEATURE = 'pms';

    /** Provider keys offered in the owner config dropdown. */
    public const PROVIDERS = [
        'null' => 'None (local folio only)',
        'manual' => 'Manual (staff enter guests)',
        'opera_cloud' => 'Oracle OPERA Cloud (deferred-live)',
        'mews' => 'Mews (deferred-live)',
    ];

    /** @var array<string, PmsConnectorInterface> */
    private static array $drivers = [];

    /** Register (or override, for tests) a driver by its key. */
    public static function register(PmsConnectorInterface $driver): void
    {
        self::$drivers[$driver->key()] = $driver;
    }

    /** Reset registered drivers (test helper). */
    public static function flush(): void
    {
        self::$drivers = [];
    }

    /** Resolve a driver by key, falling back to the Null connector. */
    public static function driver(?string $key): PmsConnectorInterface
    {
        $key = $key ?: 'null';
        self::bootBuiltins();

        return self::$drivers[$key] ?? self::$drivers['null'];
    }

    /**
     * The configured connector for a restaurant. Reads the feature='pms'
     * Credential row (merchant override, else platform default) for its provider,
     * and returns that driver — Null when nothing is configured.
     */
    public static function for(int $storeId): PmsConnectorInterface
    {
        $provider = null;
        try {
            $cred = Credential::where('store_id', $storeId)
                ->where('feature', self::FEATURE)
                ->where('active', true)
                ->first();
            $provider = $cred?->provider;
        } catch (\Throwable $e) {
            $provider = null;
        }

        return self::driver($provider);
    }

    /** Whether a non-null PMS driver is actually configured for a restaurant. */
    public static function configured(int $storeId): bool
    {
        return self::for($storeId)->key() !== 'null';
    }

    private static function bootBuiltins(): void
    {
        if (! isset(self::$drivers['null'])) {
            self::$drivers['null'] = new NullPmsConnector;
        }
        // Live drivers (OperaCloudConnector, MewsConnector) register here once written.
        foreach ([
            OperaCloudConnector::class,
            MewsConnector::class,
        ] as $class) {
            if (class_exists($class)) {
                $driver = new $class;
                self::$drivers[$driver->key()] = $driver;
            }
        }
    }
}
