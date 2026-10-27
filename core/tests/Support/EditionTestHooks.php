<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Juwri\Core\Editions\EditionPackages;

/** P1-13 13i: the {@see EditionTestHook}s of the installed edition packages (none in a Community build). */
final class EditionTestHooks
{
    /** @var list<class-string<EditionTestHook>>|null */
    private static ?array $hooks = null;

    /** @return list<class-string<EditionTestHook>> */
    public static function all(): array
    {
        return self::$hooks ??= array_values(array_filter(
            EditionPackages::testHooks(),
            fn (string $class): bool => class_exists($class) && is_subclass_of($class, EditionTestHook::class),
        ));
    }

    /** @return list<class-string> the installed editions' middleware for withoutMiddleware() (none without a package). */
    public static function middleware(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (string $hook): array => $hook::middleware(), self::all()))));
    }

    /** @return array<string, list<string>> same-layer module edges the installed editions document */
    public static function sameLayerEdges(): array
    {
        $out = [];
        foreach (self::all() as $hook) {
            foreach ($hook::sameLayerEdges() as $module => $edges) {
                $out[$module] = array_values(array_unique(array_merge($out[$module] ?? [], $edges)));
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> the installed editions' module-boundary baseline entries, merged per class */
    public static function boundaryBaseline(): array
    {
        $out = [];
        foreach (self::all() as $hook) {
            foreach ($hook::boundaryBaseline() as $class => $targets) {
                $out[$class] = array_values(array_unique(array_merge($out[$class] ?? [], $targets)));
            }
        }

        return $out;
    }

    /** @return array{banned_functions: list<string>, laravel: list<string>} */
    public static function archPresetIgnores(): array
    {
        $out = ['banned_functions' => [], 'laravel' => []];
        foreach (self::all() as $hook) {
            $ignores = $hook::archPresetIgnores();
            $out['banned_functions'] = array_merge($out['banned_functions'], $ignores['banned_functions']);
            $out['laravel'] = array_merge($out['laravel'], $ignores['laravel']);
        }

        return $out;
    }

    /** @return array<string, string> the installed editions' rows of the route-tenant-scope manifest */
    public static function routeScopeManifest(): array
    {
        $out = [];
        foreach (self::all() as $hook) {
            $out = array_merge($out, $hook::routeScopeManifest());
        }

        return $out;
    }
}
