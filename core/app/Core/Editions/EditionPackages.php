<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions;

use Composer\InstalledVersions;
use Juwri\Core\Legal\CookieRegistry;
use Juwri\Core\Pci\PaymentScriptInventory;
use Juwri\Core\Services\Tenancy\TenantTables;
use Juwri\Core\Services\Tenancy\TenantTablesGenerator;
use Juwri\Core\Support\ModuleMap;

/**
 * P1-13 13i: optional edition packages installed with Composer.
 *
 * Community knows no edition by name. An edition is a Composer package of type `laravel-library` (so composer/installers
 * can place it in the application folder) that ships an edition manifest, {@see MANIFEST}, at its root. The manifest is
 * plain data (no side effects) and may declare:
 *
 *  - `providers`    service providers, registered by {@see EditionLoader} at the end of Core's register(), in order
 *                   (the edition checks its own licence there);
 *  - `modules`      module map entries (same shape as app/Modules/dependencies.php, `path` relative to the package,
 *                   plus `after` = the module it follows in the map), merged by {@see ModuleMap};
 *  - `module_parts` edition halves of Community "split" modules: module => {path, namespace};
 *  - `aliases`      a legacy class alias file (same shape as app/Core/Support/legacy_class_aliases.php);
 *  - `view_roots`   glob of the package's Blade view folders (relative to the package), for source sweeps in tests;
 *  - `test_hooks`   classes implementing Tests\Support\EditionTestHook (test harness only);
 *  - `cookies`      a PHP file returning the package's cookie registry entries ({@see CookieRegistry});
 *  - `tenant_tables` the package's tenant-table list (generated, {@see TenantTablesGenerator}), merged by {@see TenantTables};
 *  - `payment_scripts` a PHP file returning the package's payment pages + authorised scripts (PCI DSS 6.4.3), merged by
 *                   {@see \Juwri\Core\Pci\PaymentScriptInventory}.
 *
 * Discovery reads Composer's runtime package list (vendor/composer/installed.php, no app needed), so plain PHPUnit
 * tests and bootstrap code see the same packages as the application.
 */
final class EditionPackages
{
    public const MANIFEST = 'juwri-edition.php';

    public const PACKAGE_TYPE = 'laravel-library';

    /** @var list<array{name: string, path: string, manifest: array<string, mixed>}>|null */
    private static ?array $packages = null;

    /** @return list<array{name: string, path: string, manifest: array<string, mixed>}> installed edition packages */
    public static function all(): array
    {
        if (self::$packages !== null) {
            return self::$packages;
        }
        $found = [];
        if (class_exists(InstalledVersions::class)) {
            foreach (array_unique(InstalledVersions::getInstalledPackagesByType(self::PACKAGE_TYPE)) as $name) {
                $path = InstalledVersions::getInstallPath($name);
                $real = $path === null ? false : realpath($path);
                if ($real === false || ! is_file($real.'/'.self::MANIFEST)) {
                    continue;
                }
                $manifest = (static fn (string $file): mixed => require $file)($real.'/'.self::MANIFEST);
                if (is_array($manifest)) {
                    $found[] = ['name' => $name, 'path' => $real, 'manifest' => $manifest];
                }
            }
        }

        return self::$packages = $found;
    }

    /** @return list<string> provider classes of every installed edition, in package order */
    public static function providers(): array
    {
        return self::strings('providers');
    }

    /** @return list<string> test hook classes of every installed edition */
    public static function testHooks(): array
    {
        return self::strings('test_hooks');
    }

    /** @return list<string> absolute paths of the editions' alias files */
    public static function aliasFiles(): array
    {
        $files = [];
        foreach (self::all() as $package) {
            $file = $package['manifest']['aliases'] ?? null;
            if (is_string($file) && is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The installed editions' tenant-table lists (manifest key `tenant_tables`: a PHP file returning
     * {tables, parents, user_owned}).
     *
     * @return list<array{tables: array<string, array{store: bool, account: bool, required: bool, account_required: bool}>, parents: array<string, list<array{0: string, 1: string}>>, user_owned: list<string>}>
     */
    public static function tenantTables(): array
    {
        $out = [];
        foreach (self::all() as $package) {
            $file = $package['manifest']['tenant_tables'] ?? null;
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }
            $data = (static fn (string $f): mixed => require $f)($file);
            if (is_array($data)) {
                $out[] = [
                    'tables' => (array) ($data['tables'] ?? []),
                    'parents' => (array) ($data['parents'] ?? []),
                    'user_owned' => array_values((array) ($data['user_owned'] ?? [])),
                ];
            }
        }

        return $out;
    }

    /**
     * Package name => package root of every installed edition that has a tenant-table list (the generator's input).
     *
     * @return array<string, string>
     */
    public static function tenantTablePackages(): array
    {
        $out = [];
        foreach (self::all() as $package) {
            if (is_string($package['manifest']['tenant_tables'] ?? null)) {
                $out[$package['name']] = $package['path'];
            }
        }

        return $out;
    }

    /** @return list<string> absolute view folders of every installed edition */
    public static function viewRoots(): array
    {
        $roots = [];
        foreach (self::all() as $package) {
            foreach ((array) ($package['manifest']['view_roots'] ?? []) as $glob) {
                foreach (glob($package['path'].'/'.ltrim((string) $glob, '/'), GLOB_ONLYDIR) ?: [] as $dir) {
                    $roots[] = $dir;
                }
            }
        }

        return $roots;
    }

    /**
     * Test seam: pretend these packages are installed (null = discover again).
     *
     * @param  list<array{name: string, path: string, manifest: array<string, mixed>}>|null  $packages
     */
    public static function fake(?array $packages): void
    {
        self::$packages = $packages;
        CookieRegistry::flush();
        TenantTables::flush();
        PaymentScriptInventory::flush();
    }

    /** @return list<string> */
    private static function strings(string $key): array
    {
        $out = [];
        foreach (self::all() as $package) {
            foreach ((array) ($package['manifest'][$key] ?? []) as $value) {
                if (is_string($value) && $value !== '') {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }
}
