<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Database;

use App\Support\MySqlDialect;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Tests\Support\EditionFolders;
use Tests\TestCase;

/**
 * P6-135: `ALTER TABLE .. DROP CHECK name` is MySQL-only. MariaDB (the usual cPanel database) answers with a syntax error and
 * the install stops half-migrated. Migrations must build that statement with MySqlDialect::dropCheckSql(), which picks
 * `DROP CONSTRAINT` on MariaDB. The scan needs no database.
 */
class MariaDbMigrationSyntaxTest extends TestCase
{
    public function test_no_migration_spells_drop_check_itself(): void
    {
        // P6-162: every place a migration can live: module migrations under app/, the module folders of every installed
        // edition package, plugin migrations, and the framework migrations in database/migrations.
        $core = dirname(__DIR__, 3);
        $offenders = [];
        $users = 0;
        $scanned = 0;
        $roots = [$core.'/app', $core.'/plugins', $core.'/database/migrations'];
        foreach (EditionFolders::dirs() as $dir) {
            $roots[] = $dir;
        }
        foreach ($roots as $root) {
            $this->assertDirectoryExists($root);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                $path = str_replace('\\', '/', $file->getPathname());
                if (stripos($path, '/migrations/') === false || $file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                $src = (string) file_get_contents($path);
                $users += substr_count($src, 'MySqlDialect::dropCheckSql(');
                if (preg_match('/DROP\s+CHECK/i', $src) === 1) {
                    $offenders[] = substr($path, strlen($core) + 1);
                }
            }
        }
        $this->assertGreaterThan(100, $scanned, 'the scan found too few migrations: did the folders move?');
        $this->assertGreaterThanOrEqual(5, $users, 'the guard found fewer dropCheckSql() callers than expected: did the migrations move?');
        $this->assertSame([], $offenders, 'DROP CHECK spelled in a migration breaks MariaDB installs; use MySqlDialect::dropCheckSql()');
    }

    public function test_the_helper_picks_drop_constraint_on_mariadb_and_drop_check_on_mysql(): void
    {
        $maria = \Mockery::mock(MySqlConnection::class);
        $maria->shouldReceive('isMaria')->andReturn(true);
        $mysql = \Mockery::mock(MySqlConnection::class);
        $mysql->shouldReceive('isMaria')->andReturn(false);
        DB::shouldReceive('connection')->times(3)->andReturn($maria, $mysql, \Mockery::mock(SQLiteConnection::class));

        $this->assertSame('ALTER TABLE orders DROP CONSTRAINT orders_payment_mode_check', MySqlDialect::dropCheckSql('orders', 'orders_payment_mode_check'));
        $this->assertSame('ALTER TABLE orders DROP CHECK orders_payment_mode_check', MySqlDialect::dropCheckSql('orders', 'orders_payment_mode_check'));
        $this->assertSame('ALTER TABLE orders DROP CHECK x', MySqlDialect::dropCheckSql('orders', 'x'), 'any other driver keeps the MySQL spelling (callers only run it for mysql/mariadb)');
    }
}
