<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Juwri\Core\Editions\EditionPackages;

/**
 * P1-13 13i: the module map = Community's app/Modules/dependencies.php plus the modules that installed edition packages
 * declare in their manifest ({@see EditionPackages}). Works without the application (plain PHPUnit tests use it too).
 *
 * A package module keeps the map's shape: `path` is relative to core/ when the package sits inside it (e.g. installed
 * into a sub-folder), absolute otherwise ({@see dir()}); `after` names the module it follows, so the map order (seeders
 * run in it) is the same whether a module ships with Community or with a package.
 */
final class ModuleMap
{
    /** @var array<string, array{layer: int, edition: string, path: string, namespace: string, allowed: list<string>}>|null */
    private static ?array $all = null;

    /** @var array<string, list<array{path: string, namespace: string}>>|null */
    private static ?array $parts = null;

    /** @return array<string, array{layer: int, edition: string, path: string, namespace: string, allowed: list<string>}> */
    public static function all(): array
    {
        if (self::$all === null) {
            self::load();
        }

        return self::$all ?? [];
    }

    /** @return array<string, array{layer: int, edition: string, path: string, namespace: string, allowed: list<string>}> the Community map only */
    public static function community(): array
    {
        return require self::core().'/app/Modules/dependencies.php';
    }

    /**
     * Package halves of Community "split" modules (their own folders and namespaces).
     *
     * @return array<string, list<array{path: string, namespace: string}>>
     */
    public static function parts(): array
    {
        if (self::$parts === null) {
            self::load();
        }

        return self::$parts ?? [];
    }

    /**
     * Every module folder a package contributes (whole modules and split-module halves).
     *
     * @return list<array{module: string, path: string, namespace: string}>
     */
    public static function packageFolders(): array
    {
        $community = self::community();
        $out = [];
        foreach (self::all() as $name => $def) {
            if (! isset($community[$name])) {
                $out[] = ['module' => $name, 'path' => $def['path'], 'namespace' => $def['namespace']];
            }
        }
        foreach (self::parts() as $name => $parts) {
            foreach ($parts as $part) {
                $out[] = ['module' => $name] + $part;
            }
        }

        return $out;
    }

    /** Absolute folder of a map `path` (relative to core/, or already absolute). */
    public static function dir(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1 ? $path : self::core().'/'.$path;
    }

    /** Forget the merged map (tests that fake edition packages). */
    public static function flush(): void
    {
        self::$all = null;
        self::$parts = null;
    }

    private static function load(): void
    {
        $map = self::community();
        $parts = [];
        foreach (EditionPackages::all() as $package) {
            foreach ((array) ($package['manifest']['modules'] ?? []) as $name => $def) {
                if (! is_string($name) || ! is_array($def) || isset($map[$name])) {
                    continue; // a package never replaces a Community module
                }
                $after = $def['after'] ?? null;
                unset($def['after']);
                $def['path'] = self::relative($package['path'].'/'.ltrim((string) ($def['path'] ?? ''), '/'));
                $map = self::insertAfter($map, is_string($after) ? $after : null, $name, $def);
            }
            foreach ((array) ($package['manifest']['module_parts'] ?? []) as $name => $part) {
                if (is_string($name) && is_array($part) && isset($part['path'], $part['namespace'])) {
                    $parts[$name][] = [
                        'path' => self::relative($package['path'].'/'.ltrim((string) $part['path'], '/')),
                        'namespace' => (string) $part['namespace'],
                    ];
                }
            }
        }
        /** @var array<string, array{layer: int, edition: string, path: string, namespace: string, allowed: list<string>}> $map */
        self::$all = $map;
        self::$parts = $parts;
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private static function insertAfter(array $map, ?string $after, string $name, array $def): array
    {
        if ($after === null || ! array_key_exists($after, $map)) {
            $map[$name] = $def;

            return $map;
        }
        $out = [];
        foreach ($map as $key => $value) {
            $out[$key] = $value;
            if ($key === $after) {
                $out[$name] = $def;
            }
        }

        return $out;
    }

    private static function relative(string $path): string
    {
        $real = realpath($path);
        $path = $real === false ? $path : $real;
        $core = self::core().'/';

        return str_starts_with($path, $core) ? substr($path, strlen($core)) : $path;
    }

    /** core/ — from this file's location, so it works without the application. */
    private static function core(): string
    {
        static $core = null;

        return $core ??= (realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
    }
}
