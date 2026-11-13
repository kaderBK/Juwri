<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Tests\Modules\Pos\MysqlLanePosRefundLockTest;
use Tests\TestCase;

/**
 * A RefreshDatabase test that transacts no connection on sqlite :memory: migrates the worker's in-memory DB
 * without caching its PDO (Laravel caches it per transacted connection) yet marks it migrated, so every later
 * test in that worker gets an empty DB ("no such table: plans"). It hit the Pos folder in parallel / random order
 * whenever MysqlLanePosRefundLockTest ran first in a worker. Opting out of the per-test transaction is only safe on
 * a server DB: do it through a connectionsToTransact() method that keeps the default connection on :memory:.
 */
class InMemoryTransactConnectionsTest extends TestCase
{
    public function test_no_test_class_turns_the_transaction_off_with_a_plain_property(): void
    {
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests'), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match('/\$connectionsToTransact\s*=\s*(\[\s*\]|array\(\s*\))/', $src)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, "these tests empty \$connectionsToTransact unconditionally (breaks sqlite :memory: workers):\n".implode("\n", $offenders));
    }

    public function test_the_mysql_lock_test_keeps_the_default_connection_on_an_in_memory_db(): void
    {
        if (config('database.connections.'.config('database.default').'.database') !== ':memory:') {
            $this->markTestSkipped('only meaningful on sqlite :memory:');
        }

        $m = new ReflectionMethod(MysqlLanePosRefundLockTest::class, 'connectionsToTransact');
        $connections = $m->invoke(new MysqlLanePosRefundLockTest('test_x'));

        $this->assertSame([config('database.default')], $connections);
    }
}
