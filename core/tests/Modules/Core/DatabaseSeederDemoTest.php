<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * P1-11: `migrate:fresh --seed` gives a demo account with a store, menu items and orders.
 * Runs `artisan migrate:fresh --seed` in a SUBPROCESS against its own throw-away sqlite file: the seeders commit and
 * models resolve their connection once per process, so seeding in-process would leak rows into the suite's shared
 * in-memory database. (MySQL / PostgreSQL: tools/p103/runner_migrate_seed.sh.)
 */
class DatabaseSeederDemoTest extends TestCase
{
    public function test_migrate_fresh_seed_creates_a_demo_account_with_store_menu_and_orders(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'juwri-seed-').'.sqlite';
        touch($file);
        try {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=2G', base_path('artisan'), 'migrate:fresh', '--seed', '--force'], base_path(), [
                'APP_ENV' => 'local', 'APP_INSTALLED' => 'true', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'DB_PREFIX' => '', 'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $file, 'DB_URL' => '', 'DB_FOREIGN_KEYS' => 'true',
            ]);
            $process->setTimeout(600)->run();
            $this->assertTrue($process->isSuccessful(), substr($process->getOutput().$process->getErrorOutput(), -2000));

            $pdo = new PDO('sqlite:'.$file);
            foreach (['users', 'stores', 'items', 'orders'] as $table) {
                $this->assertGreaterThan(0, (int) $pdo->query("select count(*) from $table")->fetchColumn(), "$table is empty after migrate:fresh --seed");
            }
        } finally {
            @unlink($file);
        }
    }
}
