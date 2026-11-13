<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use RuntimeException;

/**
 * P1-09: route definitions live in app/Modules/<Module>/Routes/<name>.php. A module provider registers its
 * directory ({@see register()}); the skeleton route files (routes/web.php, routes/apiv1.php, …) keep the groups
 * (prefix, namespace, middleware) and call {@see slot()} at the exact position a block used to be, so the route
 * table (order, names, middleware) is unchanged. The included file runs inside the calling group.
 */
final class ModuleRoutes
{
    /** @var array<string, string> slot name ('<Module>/<file stem>') => file */
    private static array $slots = [];

    public static function register(string $module, string $dir): void
    {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            self::$slots[$module.'/'.basename($file, '.php')] = $file;
        }
    }

    public static function slot(string $name): void
    {
        $file = self::$slots[$name] ?? null;
        if ($file === null) {
            // P1-13: a module of an edition package that is not installed (not in the module map at all) or not
            // licensed (edition 'ee') registers no slots; its routes are simply absent. So does the edition half of a
            // split module: its slot files are named `ee-*` (13h, e.g. AppStore/ee-web-1). A Community module's
            // missing slot is still a wiring error (and the licensed suite checks every called slot is registered).
            if (self::isEditionSlot($name)) {
                return;
            }

            throw new RuntimeException("Route slot [$name] is not registered: is the module's service provider listed in config/app.php?");
        }

        (static function () use ($file): void {
            require $file;
        })();
    }

    /** @return list<string> every registered slot (for the integrity test) */
    public static function slots(): array
    {
        return array_keys(self::$slots);
    }

    /**
     * Does this slot belong to edition code: a module the module map does not list (its edition package is not
     * installed), an edition module's slot (edition 'ee'), or an `ee-*` slot of a split module (its edition half)?
     */
    private static function isEditionSlot(string $name): bool
    {
        $editions = array_map(fn (array $def) => $def['edition'], ModuleMap::all());
        [$module, $file] = array_pad(explode('/', $name, 2), 2, '');

        return match ($editions[$module] ?? null) {
            null, 'ee' => true,
            'split' => str_starts_with($file, 'ee-'),
            default => false,
        };
    }
}
