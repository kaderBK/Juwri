<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\Cuisine;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Database\Factories\CuisineFactory;
use Juwri\Core\Database\Factories\StoreFactory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ModuleSeeders;
use Tests\TestCase;

/** P1-11: seeders and factories live in their module; `db:seed` gives a demo account; old names keep resolving. */
class ModuleSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_module_seeder_class_is_registered_by_its_provider(): void
    {
        $registered = array_merge(...array_values(ModuleSeeders::all()));
        $files = [];
        foreach (glob(app_path('Modules/*/Database/Seeders/*Seeder.php')) ?: [] as $file) {
            $module = basename(dirname($file, 3));
            $files[] = "Juwri\\Modules\\{$module}\\Database\\Seeders\\".basename($file, '.php');
        }
        // P1-13 13d follow-up: Core's own reference data (the cuisine taxonomy).
        foreach (glob(app_path('Core/Database/Seeders/*Seeder.php')) ?: [] as $file) {
            $files[] = 'Juwri\\Core\\Database\\Seeders\\'.basename($file, '.php');
        }
        // P1-13 13c: the modules of an installed edition package (and its halves of split modules) — their providers
        // register them when the edition is active (the suite runs licensed). None in the Community mirror.
        foreach (\Tests\Support\EditionFolders::modules() as $folder) {
            foreach (glob($folder['dir'].'/Database/Seeders/*Seeder.php') ?: [] as $file) {
                $files[] = $folder['namespace'].'\\Database\\Seeders\\'.basename($file, '.php');
            }
        }
        $this->assertGreaterThan(20, count($files));
        $this->assertSame([], array_values(array_diff($files, $registered)), 'seeder not registered in ModuleSeeders (module provider)');
        $this->assertSame([], array_values(array_diff($registered, $files)), 'registered seeder class has no file');
        $this->assertFileDoesNotExist(database_path('seeders/ThemePackDemoSeeder.php'), 'seeders live in the modules');
    }

    public function test_old_seeder_and_factory_names_still_resolve(): void
    {
        $this->assertTrue(class_exists('Database\\Seeders\\ThemePackDemoSeeder'));
        $this->assertTrue(class_exists('Database\\Seeders\\E2ePlatformSeeder'));
        $this->assertTrue(class_exists('Database\\Factories\\PostFactory'));
        $this->assertSame('E2ePlatformSeeder', class_basename(ModuleSeeders::find('E2ePlatformSeeder')));
    }

    public function test_models_find_their_factories_in_the_modules(): void
    {
        $this->assertInstanceOf(Factory::class, User::factory());
        $this->assertSame(StoreFactory::class, get_class(Store::factory()));
        $this->assertStringStartsWith('Juwri\\Modules\\Ordering\\Database\\Factories\\', get_class(Order::factory()));
        $this->assertSame(Store::class, (new StoreFactory)->modelName());
        // P1-13 13d follow-up: the cuisine taxonomy is Community — its factory is Core's.
        $this->assertSame(CuisineFactory::class, get_class(Cuisine::factory()));
    }
}
