<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * P1-11: every module lists its seeders from its service provider ({@see register()}); DatabaseSeeder runs the
 * `default` ones layer by layer (L0 → L5, the order of the module map, {@see ModuleMap}), `optional` seeders (showcases,
 * E2E fixtures, price flips) run on demand: `php artisan db:seed --class=<ShortName>` (old `Database\Seeders\X`
 * names keep resolving through the legacy alias map).
 */
final class ModuleSeeders
{
    /** @var array<string, array{default: list<class-string>, demo: list<class-string>, optional: list<class-string>}> */
    private static array $modules = [];

    /**
     * @param  list<class-string>  $default  reference data every install needs
     * @param  list<class-string>  $demo  demo account / demo content (DatabaseSeeder runs them outside production)
     * @param  list<class-string>  $optional  everything else, on demand
     */
    public static function register(string $module, array $default = [], array $demo = [], array $optional = []): void
    {
        self::$modules[$module] = ['default' => $default, 'demo' => $demo, 'optional' => $optional];
    }

    /** @return list<class-string> seeders of one kind, modules in dependency order (Core first). */
    public static function ordered(string $kind): array
    {
        $order = array_keys(ModuleMap::all());
        $out = [];
        foreach ($order as $module) {
            foreach (self::$modules[$module][$kind] ?? [] as $class) {
                $out[] = $class;
            }
        }

        return $out;
    }

    /** The registered seeder class with this short name (any kind), or null. */
    public static function find(string $shortName): ?string
    {
        foreach (self::$modules as $kinds) {
            foreach ($kinds as $classes) {
                foreach ($classes as $class) {
                    if (class_basename($class) === $shortName) {
                        return $class;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, list<class-string>> module => all its registered seeders (for the integrity test) */
    public static function all(): array
    {
        return array_map(fn (array $k) => array_merge($k['default'], $k['demo'], $k['optional']), self::$modules);
    }
}
