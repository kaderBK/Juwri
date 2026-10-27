<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Services\Ops\DatabaseDump;
use Tests\TestCase;

/**
 * Bug 5 (2026-10-01): `platform:db-backup` / `platform:db-restore-check` only knew mysqldump / mysql. The dump path is
 * now chosen by the connection's driver (DatabaseDump): mysqldump + mysql on MySQL/MariaDB, pg_dump (plain SQL) +
 * psql into a scratch database on PostgreSQL; sqlite is a no-op that says to copy the file; any other driver fails
 * with a clear reason. The password only ever travels in MYSQL_PWD / PGPASSWORD, never on a command line.
 * No real dump runs: Process::fake stands in for the tools.
 */
class PlatformDbDumpDriverTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'pa ss$w0rd!';

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // A private storage root: the shared backups/platform dir is asserted empty by PlatformDbBackupTest,
        // which a parallel worker may be running right now.
        $this->storage = sys_get_temp_dir().'/bug5-'.uniqid();
        @mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);

        config([
            'database.connections.pgfake' => [
                'driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5433', 'database' => 'juwri',
                'username' => 'juwri_app', 'password' => self::SECRET, 'search_path' => 'public', 'sslmode' => 'require',
            ],
            'database.connections.myfake' => [
                'driver' => 'mysql', 'host' => 'db.internal', 'port' => '3307', 'database' => 'juwri',
                'username' => 'juwri_app', 'password' => self::SECRET,
            ],
            'database.connections.mssqlfake' => ['driver' => 'sqlsrv', 'database' => 'juwri', 'password' => self::SECRET],
            'filesystems.disks.s3.key' => null,
        ]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));
        parent::tearDown();
    }

    /** @return list<string> every command line the builder can produce */
    private function allCommands(DatabaseDump $dump): array
    {
        return [
            $dump->dumpCommand(),
            $dump->recreateScratchCommand('juwri_restore_check'),
            $dump->dropScratchCommand('juwri_restore_check'),
            $dump->loadCommand('juwri_restore_check'),
        ];
    }

    public function test_postgres_commands_use_pg_tools_and_keep_the_password_in_the_environment(): void
    {
        $dump = DatabaseDump::for(config('database.connections.pgfake'));

        $this->assertTrue($dump->isPostgres());
        $this->assertSame(
            "'pg_dump' --format=plain --no-owner --no-privileges --no-password --host='db.internal' --port='5433'"
            ." --username='juwri_app' --dbname='juwri'",
            $dump->dumpCommand()
        );
        $this->assertSame(
            "'psql' --no-psqlrc --no-password --set=ON_ERROR_STOP=1 --host='db.internal' --port='5433' --username='juwri_app'"
            ." --dbname='juwri' -c 'DROP DATABASE IF EXISTS \"juwri_restore_check\"' -c 'CREATE DATABASE \"juwri_restore_check\"'",
            $dump->recreateScratchCommand('juwri_restore_check')
        );
        $this->assertStringEndsWith("--dbname='juwri_restore_check' --quiet", $dump->loadCommand('juwri_restore_check'));
        $this->assertSame('-- PostgreSQL database dump complete', $dump->completionMarker());
        $this->assertSame(['PGPASSWORD' => self::SECRET, 'PGSSLMODE' => 'require'], $dump->env());
        foreach ($this->allCommands($dump) as $cmd) {
            $this->assertStringNotContainsString(self::SECRET, $cmd);
        }

        [$sql, $bindings] = $dump->tableCountQuery('juwri');
        $this->assertStringContainsString('table_catalog = ?', $sql);
        $this->assertSame(['juwri', 'public'], $bindings);
    }

    public function test_mysql_commands_keep_the_password_in_mysql_pwd(): void
    {
        $dump = DatabaseDump::for(config('database.connections.myfake'));

        $this->assertSame(
            "'mysqldump' --single-transaction --quick --routines --triggers --events --host='db.internal' --port='3307' --user='juwri_app' 'juwri'",
            $dump->dumpCommand()
        );
        $this->assertSame('-- Dump completed', $dump->completionMarker());
        $this->assertSame(['MYSQL_PWD' => self::SECRET], $dump->env());
        foreach ($this->allCommands($dump) as $cmd) {
            $this->assertStringNotContainsString(self::SECRET, $cmd);
        }
        $this->assertTrue(DatabaseDump::for(['driver' => 'mariadb', 'database' => 'x'])->dumpTool() === 'mysqldump');
    }

    public function test_unsupported_drivers_get_a_clear_reason(): void
    {
        try {
            DatabaseDump::for(['driver' => 'sqlite', 'database' => '/srv/juwri/database.sqlite']);
            $this->fail('sqlite must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('copying the database file (/srv/juwri/database.sqlite)', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('driver [sqlsrv] is not supported: supported drivers are mysql, mariadb, pgsql');
        DatabaseDump::for(['driver' => 'sqlsrv']);
    }

    public function test_backup_on_postgres_runs_pg_dump_and_publishes_a_validated_artifact(): void
    {
        Process::fake(function (PendingProcess $process) {
            $cmd = (string) $process->command;
            if (str_contains($cmd, 'pg_dump') && preg_match("/echo \\$\\? > '([^']+)'; } \\| gzip > '([^']+)'/", $cmd, $m)) {
                file_put_contents($m[1], "0\n");
                file_put_contents($m[2], gzencode("CREATE TABLE x ();\n--\n-- PostgreSQL database dump complete\n--\n"));
            }

            return Process::result();
        });

        $this->artisan('platform:db-backup', ['--connection' => 'pgfake'])->assertExitCode(0);

        Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "'pg_dump' --format=plain")
            && ($p->environment['PGPASSWORD'] ?? null) === self::SECRET
            && ! str_contains((string) $p->command, self::SECRET));
        $this->assertCount(1, glob($this->storage.'/app/backups/platform/db-*.sql.gz') ?: []);
        $run = PlatformBackupRun::latestOf('backup');
        $this->assertTrue((bool) $run->ok);
    }

    public function test_backup_rejects_a_postgres_dump_without_the_pg_trailer(): void
    {
        Process::fake(function (PendingProcess $process) {
            $cmd = (string) $process->command;
            if (preg_match("/echo \\$\\? > '([^']+)'; } \\| gzip > '([^']+)'/", $cmd, $m)) {
                file_put_contents($m[1], "0\n");
                // A MySQL trailer is not a PostgreSQL one: the per-driver marker must be the one checked.
                file_put_contents($m[2], gzencode("CREATE TABLE x ();\n-- Dump completed on 2026-10-01\n"));
            }

            return Process::result();
        });

        $this->artisan('platform:db-backup', ['--connection' => 'pgfake'])->assertExitCode(1);

        $this->assertSame([], glob($this->storage.'/app/backups/platform/db-*.sql.gz') ?: []);
        $this->assertStringContainsString('PostgreSQL database dump complete', (string) PlatformBackupRun::latestOf('backup')->error);
    }

    public function test_backup_on_mysql_still_runs_mysqldump_with_mysql_pwd(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (preg_match("/echo \\$\\? > '([^']+)'; }/", (string) $process->command, $m)) {
                file_put_contents($m[1], "2\n"); // mysqldump died
            }

            return Process::result();
        });

        $this->artisan('platform:db-backup', ['--connection' => 'myfake'])->assertExitCode(1);

        Process::assertRan(fn (PendingProcess $p) => str_starts_with((string) $p->command, "{ 'mysqldump' --single-transaction")
            && ($p->environment['MYSQL_PWD'] ?? null) === self::SECRET
            && ! str_contains((string) $p->command, self::SECRET));
        $this->assertStringStartsWith('mysqldump exited 2', (string) PlatformBackupRun::latestOf('backup')->error);
    }

    public function test_backup_and_restore_check_fail_clearly_on_an_unsupported_driver(): void
    {
        Process::fake();

        $this->artisan('platform:db-backup', ['--connection' => 'mssqlfake'])
            ->expectsOutputToContain('driver [sqlsrv] is not supported')
            ->assertExitCode(1);
        $this->artisan('platform:db-restore-check', ['--connection' => 'mssqlfake', '--force' => true])
            ->expectsOutputToContain('driver [sqlsrv] is not supported')
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_sqlite_backup_is_a_no_op_that_says_to_copy_the_file(): void
    {
        Process::fake();
        config(['database.connections.litefake' => ['driver' => 'sqlite', 'database' => '/srv/juwri/database.sqlite']]);

        $this->artisan('platform:db-backup', ['--connection' => 'litefake'])
            ->expectsOutputToContain('copying the database file (/srv/juwri/database.sqlite)')
            ->assertExitCode(0);

        Process::assertNothingRan();
    }

    public function test_restore_check_on_postgres_loads_with_psql_and_drops_the_scratch_database(): void
    {
        $file = $this->storage.'/dump.sql.gz';
        file_put_contents($file, gzencode("-- PostgreSQL database dump complete\n"));
        Process::fake(function (PendingProcess $process) {
            if (str_contains((string) $process->command, 'gzip -dc')) {
                return Process::result(errorOutput: 'ERROR:  relation "x" already exists', exitCode: 3);
            }

            return Process::result();
        });

        $this->artisan('platform:db-restore-check', ['--connection' => 'pgfake', '--force' => true, '--file' => $file])
            ->expectsOutputToContain('psql refused the dump')
            ->assertExitCode(1);

        Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "-c 'CREATE DATABASE \"juwri_restore_check\"'")
            && ($p->environment['PGPASSWORD'] ?? null) === self::SECRET);
        Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "| 'psql' --no-psqlrc")
            && str_contains((string) $p->command, "--dbname='juwri_restore_check' --quiet"));
        Process::assertRan(fn (PendingProcess $p) => str_ends_with((string) $p->command, "-c 'DROP DATABASE IF EXISTS \"juwri_restore_check\"'"));
        Process::assertDidntRun(fn (PendingProcess $p) => str_contains((string) $p->command, self::SECRET));
        $this->assertFalse((bool) PlatformBackupRun::latestOf('restore_check')->ok);
    }
}
