<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Juwri\Core\Support\ModuleSeeders;

/**
 * P1-11: every module lists its seeders from its provider (ModuleSeeders). `migrate:fresh --seed` runs, layer by layer
 * (L0 → L5): the `default` reference seeders of every module, then — outside production — the `demo` seeders
 * (a demo owner with a store, menu items and orders). `optional` seeders run on demand:
 * `php artisan db:seed --class=E2ePlatformSeeder` (the short name resolves to the module class via the alias map).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Under the test runner nothing is seeded: RefreshDatabase seeds once per process, outside any transaction, so
        // seeded rows (cuisines, plans, demo content) would leak into every later test of the worker. The real
        // `migrate:fresh --seed` is covered by DatabaseSeederDemoTest, in a subprocess on its own database.
        if (app()->runningUnitTests()) {
            return;
        }

        foreach (ModuleSeeders::ordered('default') as $class) {
            $this->call($class);
        }

        // Demo content: never in production.
        if (! app()->isProduction()) {
            foreach (ModuleSeeders::ordered('demo') as $class) {
                $this->call($class);
            }
        }
    }
}
