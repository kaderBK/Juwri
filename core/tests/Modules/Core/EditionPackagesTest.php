<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Support\LegacyClassAliases;
use Juwri\Core\Support\ModuleMap;
use Juwri\Core\Support\ModuleRoutes;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * P1-13 13i: Community finds optional edition packages only through their Composer manifest (EditionPackages) and
 * merges what they declare — module map entries in their place, split-module halves, alias files — without naming any
 * edition. Plain PHPUnit with a fake package in a temp folder (the real Enterprise package is checked by its own tests).
 */
class EditionPackagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/juwri-edition-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/Modules/Zeta/Database/Migrations', 0777, true);
        file_put_contents($this->dir.'/aliases.php', "<?php return ['aliases' => ['Old\\\\Zeta\\\\Thing' => 'Acme\\\\Zeta\\\\Thing'], 'models' => ['Old\\\\Zeta\\\\Thing']];\n");
    }

    protected function tearDown(): void
    {
        EditionPackages::fake(null);
        ModuleMap::flush();
        $this->resetAliases();
        @unlink($this->dir.'/aliases.php');
        @rmdir($this->dir.'/Modules/Zeta/Database/Migrations');
        @rmdir($this->dir.'/Modules/Zeta/Database');
        @rmdir($this->dir.'/Modules/Zeta');
        @rmdir($this->dir.'/Modules');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_without_an_edition_package_the_map_is_the_community_map(): void
    {
        $this->fakePackages([]);

        $this->assertSame(ModuleMap::community(), ModuleMap::all());
        $this->assertSame([], ModuleMap::parts());
        $this->assertSame([], ModuleMap::packageFolders());
        $this->assertSame([], EditionPackages::providers());
        $this->assertSame([], EditionPackages::aliasFiles());
    }

    public function test_a_package_module_joins_the_map_after_the_module_it_names(): void
    {
        $this->fakePackages([$this->package([
            'providers' => ['Acme\\Zeta\\ZetaServiceProvider', 42],
            'modules' => ['Zeta' => ['after' => 'Tables', 'layer' => 2, 'edition' => 'ee', 'path' => 'Modules/Zeta',
                'namespace' => 'Acme\\Zeta', 'allowed' => ['Core']]],
            'module_parts' => ['AppStore' => ['path' => 'Modules/Zeta', 'namespace' => 'Acme\\AppStoreHalf']],
        ])]);

        $map = ModuleMap::all();
        $keys = array_keys($map);
        $this->assertSame(array_search('Tables', $keys, true) + 1, array_search('Zeta', $keys, true), 'kept in map order');
        $this->assertArrayNotHasKey('after', $map['Zeta']);
        $this->assertSame(realpath($this->dir).'/Modules/Zeta', $map['Zeta']['path'], 'outside core/: absolute');
        $this->assertSame(realpath($this->dir).'/Modules/Zeta', ModuleMap::dir($map['Zeta']['path']));
        $this->assertSame(count(ModuleMap::community()) + 1, count($map));
        $this->assertSame([['path' => realpath($this->dir).'/Modules/Zeta', 'namespace' => 'Acme\\AppStoreHalf']], ModuleMap::parts()['AppStore']);
        $this->assertSame(['Zeta', 'AppStore'], array_column(ModuleMap::packageFolders(), 'module'));
        $this->assertSame(['Acme\\Zeta\\ZetaServiceProvider'], EditionPackages::providers(), 'non-strings are ignored');
    }

    public function test_a_package_never_replaces_a_community_module_and_an_unknown_anchor_appends(): void
    {
        $this->fakePackages([$this->package(['modules' => [
            'Menu' => ['layer' => 5, 'edition' => 'ee', 'path' => 'Modules/Zeta', 'namespace' => 'Acme\\Menu', 'allowed' => []],
            'Zeta' => ['after' => 'NoSuchModule', 'layer' => 5, 'edition' => 'ee', 'path' => 'Modules/Zeta', 'namespace' => 'Acme\\Zeta', 'allowed' => []],
        ]])]);

        $map = ModuleMap::all();
        $this->assertSame(ModuleMap::community()['Menu'], $map['Menu']);
        $this->assertSame('Zeta', array_key_last($map));
    }

    public function test_a_module_path_inside_core_stays_relative_like_the_community_ones(): void
    {
        $core = dirname(__DIR__, 3);
        $this->fakePackages([['name' => 'acme/inside', 'path' => $core, 'manifest' => ['modules' => [
            'Zeta' => ['layer' => 5, 'edition' => 'ee', 'path' => 'app/Core', 'namespace' => 'Acme\\Zeta', 'allowed' => []],
        ]]]]);

        $this->assertSame('app/Core', ModuleMap::all()['Zeta']['path']);
        $this->assertSame(realpath($core).'/app/Core', ModuleMap::dir('app/Core'));
    }

    public function test_route_slots_of_a_module_that_is_not_installed_are_skipped_community_ones_are_not(): void
    {
        $this->fakePackages([]);

        ModuleRoutes::slot('NotInstalledModule/web'); // an edition module without its package: no routes, no error
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        ModuleRoutes::slot('Menu/no-such-slot-file');
    }

    public function test_the_package_alias_file_is_merged_after_the_community_map(): void
    {
        $this->fakePackages([$this->package(['aliases' => $this->dir.'/aliases.php'])]);
        $this->resetAliases();

        $aliases = LegacyClassAliases::aliases();
        $this->assertSame('Acme\\Zeta\\Thing', $aliases['Old\\Zeta\\Thing']);
        $this->assertSame('Old\\Zeta\\Thing', array_key_last($aliases), 'after the Community entries');
        $this->assertSame('Old\\Zeta\\Thing', LegacyClassAliases::oldNameOf('Acme\\Zeta\\Thing'));
        $this->assertSame('Acme\\Zeta\\Thing', LegacyClassAliases::morphMap()['Old\\Zeta\\Thing']);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{name: string, path: string, manifest: array<string, mixed>}
     */
    private function package(array $manifest): array
    {
        return ['name' => 'acme/zeta', 'path' => (string) realpath($this->dir), 'manifest' => $manifest];
    }

    /** @param  list<array{name: string, path: string, manifest: array<string, mixed>}>  $packages */
    private function fakePackages(array $packages): void
    {
        EditionPackages::fake($packages);
        ModuleMap::flush();
    }

    private function resetAliases(): void
    {
        (new ReflectionProperty(LegacyClassAliases::class, 'data'))->setValue(null, null);
        (new ReflectionProperty(LegacyClassAliases::class, 'lower'))->setValue(null, null);
    }
}
