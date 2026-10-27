<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Juwri\Core\Editions\EditionPackages;

/**
 * P1-13 follow-up: Blade views live in resources/views AND in the module Views folders of every installed edition
 * package (namespace '<module>::', declared as `view_roots` in the package's edition manifest). Source sweeps (escaping,
 * CSP, kit and convention ratchets) scan all of them through this helper, so a view that moved into an edition package
 * stays covered. Without an edition package (Community build) only resources/views is scanned.
 */
final class ViewRoots
{
    /** @return list<string> resources/views first, then each edition package's view folder */
    public static function all(): array
    {
        return [self::core().'/resources/views', ...self::ee()];
    }

    /** @return list<string> the installed edition packages' view folders (P1-13 13i: from their manifests) */
    public static function ee(): array
    {
        return EditionPackages::viewRoots();
    }

    /**
     * The same subfolder of every root (e.g. 'owner', 'admin'), existing ones only.
     *
     * @return list<string>
     */
    public static function sub(string $sub): array
    {
        $sub = trim($sub, '/');

        return array_values(array_filter(
            array_map(fn (string $root) => $sub === '' ? $root : $root.'/'.$sub, self::all()),
            'is_dir'
        ));
    }

    /** core/ — from this file's location, so plain PHPUnit tests (no application) can use the helper too. */
    private static function core(): string
    {
        return dirname(__DIR__, 2);
    }

    /** A view file's path relative to its root; an edition package's views as "ee:<Module>/<path>". */
    public static function relative(string $path): string
    {
        $views = self::core().'/resources/views/';
        if (str_starts_with($path, $views)) {
            return substr($path, strlen($views));
        }
        foreach (EditionPackages::all() as $package) {
            if (preg_match('#^'.preg_quote($package['path'], '#').'/Modules/([^/]+)/Views/(.+)$#', $path, $m)) {
                return 'ee:'.$m[1].'/'.$m[2];
            }
        }

        return $path;
    }
}
