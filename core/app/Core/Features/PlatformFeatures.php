<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Features;

use App\Services\FeatureRegistry;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Settings\PlatformSettingKeys;
use Juwri\Core\Settings\StoreSettings;
use Laravel\Pennant\Feature;

/**
 * P1-07 (J25-5): the feature registry on Laravel Pennant. Every platform `admin_allow_<key>` switch
 * (PlatformSettingKeys::FEATURE_SWITCHES, 86 of them) is a Pennant feature named `<key>`; any other
 * key asked for is defined on first use, so a `feature:<key>` route can never hit an unknown flag.
 *
 * Resolution (kill wins, nothing else can grant):
 *  1. platform switch — the stored `admin_allow_<key>` value when present, else the FeatureRegistry
 *     default, else ON (exactly what FeatureRegistry::isEnabled always answered);
 *  2. store scope — a store can only be switched OFF for itself: `stores.settings.features.<key> =
 *     false`. It never enables what the platform switch disabled;
 *  3. account scope — resolves through the platform switch today (plan / edition gating lands with
 *     P1-13 on the same registry).
 *
 * The switches live in config('settings.*') (hydrated per request from platform_settings) and tests
 * rewrite them mid-request, so results are not memoised: the Pennant in-memory cache is flushed
 * before each check.
 */
final class PlatformFeatures
{
    public const PREFIX = 'admin_allow_';

    /** Scope used for a platform-wide check. */
    public const PLATFORM = 'platform';

    /** @var array<int, array<string, true>> per-app set of defined feature names */
    private static array $defined = [];

    /** Define every platform switch as a Pennant feature. */
    public static function register(): void
    {
        foreach (array_keys(PlatformSettingKeys::FEATURE_SWITCHES) as $switch) {
            self::define(self::keyOf($switch));
        }
    }

    /**
     * The feature keys backed by a platform switch, without the `admin_allow_` prefix.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(self::keyOf(...), array_keys(PlatformSettingKeys::FEATURE_SWITCHES));
    }

    /**
     * Is $key on? Platform-wide by default; pass a Store (Post model) to also apply that store's own
     * off-switch, or an Account for account scope.
     */
    public static function active(string $key, Model|string|null $scope = null): bool
    {
        self::define($key);
        Feature::flushCache();

        return (bool) Feature::for($scope ?? self::PLATFORM)->active($key);
    }

    /**
     * The platform switch alone: the stored value, else the registry default, else ON.
     * (The previous FeatureRegistry::isEnabled body.)
     */
    public static function platformEnabled(string $key): bool
    {
        $configKey = 'settings.'.self::PREFIX.$key;
        if (config()->has($configKey)) {
            return (bool) config($configKey);
        }
        $entry = FeatureRegistry::keyed()[$key] ?? null;

        return $entry ? $entry['default'] : true;
    }

    /**
     * True only when the switch is stored AND off — a missing switch is never a veto (the registry
     * default does not veto commercial grants). The one implementation behind Entitlements::vetoed().
     */
    public static function switchedOff(string $key): bool
    {
        $configKey = 'settings.'.self::PREFIX.$key;

        return config()->has($configKey) && ! config($configKey);
    }

    /** Resolver: the platform switch, then the store's own off-switch. */
    public static function resolve(string $key, mixed $scope): bool
    {
        if (! self::platformEnabled($key)) {
            return false;
        }
        if ($scope instanceof Model && $scope->getTable() === 'stores' && $scope->getKey()) {
            $override = StoreSettings::all((int) $scope->getKey())['features'][$key] ?? null;

            return $override !== false && $override !== 0 && $override !== '0';
        }

        return true;
    }

    private static function define(string $key): void
    {
        $app = \Juwri\Core\Support\RequestEpoch::id();
        if (isset(self::$defined[$app][$key])) {
            return;
        }
        // Keep the set for the CURRENT app only (a new app instance defines afresh).
        self::$defined = [$app => (self::$defined[$app] ?? []) + [$key => true]];
        Feature::define($key, fn (mixed $scope = null) => self::resolve($key, $scope));
    }

    private static function keyOf(string $switch): string
    {
        return substr($switch, strlen(self::PREFIX));
    }
}
