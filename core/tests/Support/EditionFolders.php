<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Support\ModuleMap;

/**
 * P1-13 13i part 2b: the module folders an installed edition package contributes (whole modules and its halves of split
 * modules), read from the module map — never from a hard-coded package path. Source sweeps in the Community test suite
 * (every route file, migration folder, seeder, view…) call this next to their own `app/Modules` scan, so the same test
 * covers the package when one is installed and runs unchanged — and finds nothing extra — in the Community mirror.
 */
final class EditionFolders
{
    /** @return list<string> absolute module folders of the installed edition packages */
    public static function dirs(): array
    {
        return array_values(array_unique(array_map(
            fn (array $folder): string => ModuleMap::dir($folder['path']),
            ModuleMap::packageFolders(),
        )));
    }

    /**
     * The root PSR-4 namespaces (trailing backslash) of the installed edition packages, from their composer.json —
     * Community code must never reference any of them.
     *
     * @return list<string>
     */
    public static function namespaces(): array
    {
        $out = [];
        foreach (EditionPackages::all() as $package) {
            $json = json_decode((string) @file_get_contents($package['path'].'/composer.json'), true);
            foreach (array_keys((array) ($json['autoload']['psr-4'] ?? [])) as $prefix) {
                $out[] = (string) $prefix;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<array{module: string, dir: string, namespace: string}> */
    public static function modules(): array
    {
        return array_map(
            fn (array $folder): array => ['module' => $folder['module'], 'dir' => ModuleMap::dir($folder['path']), 'namespace' => $folder['namespace']],
            ModuleMap::packageFolders(),
        );
    }

    /**
     * glob() inside every package module folder (a pattern relative to the module folder, e.g. 'Routes/*.php').
     *
     * @return list<string>
     */
    public static function glob(string $pattern, int $flags = 0): array
    {
        $out = [];
        foreach (self::dirs() as $dir) {
            array_push($out, ...(glob($dir.'/'.$pattern, $flags) ?: []));
        }

        return $out;
    }

    /** The package's module folder for a module name, when installed (whole module or the half of a split one). */
    public static function dirOf(string $module): ?string
    {
        foreach (ModuleMap::packageFolders() as $folder) {
            if ($folder['module'] === $module) {
                return ModuleMap::dir($folder['path']);
            }
        }

        return null;
    }
}
