<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Database;

use App\Console\Commands\MigrateGateCommand;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P6-225: juwri:migrate-gate takes a database advisory lock so several containers that share one
 * database never race their migrations, and refuses to start when the database has migrations this
 * image does not (version skew — exit 3). The full-stack docker race lives in docker/tests/migrate-drill.sh.
 */
class MigrateGateCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Temp dir + file of the probe migration (pending test); tearDown removes them even when the test fails. */
    private ?string $tempMigrationDir = null;

    private ?string $tempMigrationFile = null;

    protected function tearDown(): void
    {
        // The pending test writes a probe table, its migration row, and a temp dir. Roll those back
        // explicitly so a failure mid-test never leaks state into the next test in this worker.
        try {
            if (Schema::hasTable('p6225_probe')) {
                Schema::drop('p6225_probe');
            }
        } catch (\Throwable) {
            // Schema check after RefreshDatabase's rollback would already be empty; nothing to do.
        }
        try {
            DB::table('migrations')->where('migration', '2099_01_02_000000_p6225_probe')->delete();
        } catch (\Throwable) {
            // Same: any leftover row is harmless and the next test starts with a fresh transaction.
        }

        // The migrator (and its extra path) belongs to this test's app instance, which is discarded after the test.

        if ($this->tempMigrationFile !== null && is_file($this->tempMigrationFile)) {
            @unlink($this->tempMigrationFile);
        }
        if ($this->tempMigrationDir !== null && is_dir($this->tempMigrationDir)) {
            @rmdir($this->tempMigrationDir);
        }
        $this->tempMigrationFile = null;
        $this->tempMigrationDir = null;

        parent::tearDown();
    }

    public function test_wait_on_a_fully_migrated_database_returns_zero_with_schema_is_current(): void
    {
        $this->artisan('juwri:migrate-gate', ['--wait' => true, '--timeout' => 0])
            ->assertExitCode(0)
            ->expectsOutputToContain('schema is current');
    }

    public function test_wait_returns_three_when_the_database_has_a_migration_this_release_does_not(): void
    {
        // A newer release already ran on this DB; an older release must refuse to start, not silently
        // overwrite the schema. Insert the row in the active transaction so RefreshDatabase rolls it back.
        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_from_newer_release',
            'batch' => 999,
        ]);

        $this->artisan('juwri:migrate-gate', ['--wait' => true, '--timeout' => 0])
            ->assertExitCode(3)
            ->expectsOutputToContain('version skew');
    }

    public function test_run_returns_three_when_the_database_has_a_migration_this_release_does_not(): void
    {
        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_from_newer_release',
            'batch' => 999,
        ]);

        $this->artisan('juwri:migrate-gate', ['--run' => true, '--timeout' => 0])
            ->assertExitCode(3)
            ->expectsOutputToContain('version skew');
    }

    public function test_wait_times_out_when_migrations_are_pending_then_run_applies_them(): void
    {
        $this->registerProbeMigration();
        $this->artisan('juwri:migrate-gate', ['--wait' => true, '--timeout' => 0, '--interval' => 1])
            ->assertExitCode(1)
            ->expectsOutputToContain('pending');

        // After RefreshDatabase, no real migrations are pending, so --run only has to apply our probe.
        $this->artisan('juwri:migrate-gate', ['--run' => true, '--timeout' => 5])
            ->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('p6225_probe'), 'probe table is created by the run');

        // Now the schema is current, --wait returns immediately.
        $this->artisan('juwri:migrate-gate', ['--wait' => true, '--timeout' => 0])
            ->assertExitCode(0)
            ->expectsOutputToContain('schema is current');
    }

    public function test_run_fails_when_another_connection_holds_the_lock(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->markTestSkipped("lock contention test runs on MySQL/MariaDB/PG only (current driver: {$driver})");
        }

        // A second connection to the same database, holding the named lock. GET_LOCK and pg_advisory_lock
        // are per-session, so the default connection's lock attempt times out without ever seeing us.
        config([
            'database.connections.lockholder' => config('database.connections.'.config('database.default')),
        ]);
        $lockholder = DB::connection('lockholder');
        try {
            $this->acquireExternalLock($lockholder);
            try {
                $this->artisan('juwri:migrate-gate', ['--run' => true, '--timeout' => 1])
                    ->assertExitCode(1)
                    ->expectsOutputToContain('migration lock');
            } finally {
                $this->releaseExternalLock($lockholder);
            }
        } finally {
            // Drop the second connection so its PDO does not linger across tests in this worker.
            DB::purge('lockholder');
        }
    }

    /** Build the probe migration file and register its dir with the Migrator. */
    private function registerProbeMigration(): void
    {
        $dir = sys_get_temp_dir().'/juwri-mgtest-'.bin2hex(random_bytes(4));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->markTestSkipped("could not create temp dir for probe migration: {$dir}");
        }
        $file = $dir.DIRECTORY_SEPARATOR.'2099_01_02_000000_p6225_probe.php';
        file_put_contents($file, <<<'PHP'
<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P6-225 test probe — created by MigrateGateCommandTest::test_wait_times_out_when_migrations_are_pending_then_run_applies_them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p6225_probe', function (Blueprint $t) {
            $t->id();
        });
    }

    public function down(): void
    {
        Schema::drop('p6225_probe');
    }
};
PHP);

        $this->tempMigrationDir = $dir;
        $this->tempMigrationFile = $file;

        app('migrator')->path($dir);
    }

    private function acquireExternalLock(Connection $lockholder): void
    {
        $driver = $lockholder->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // GET_LOCK(name, 0) returns 1 if acquired, 0 otherwise. No waiting — the default connection
            // will be the one that times out against us.
            $row = $lockholder->selectOne('SELECT GET_LOCK(?, 0) AS l', [MigrateGateCommand::LOCK_NAME]);
            if ((int) ($row->l ?? 0) !== 1) {
                $this->markTestSkipped('lockholder could not acquire the migration lock (another test holds it?)');
            }

            return;
        }
        if ($driver === 'pgsql') {
            $row = $lockholder->selectOne('SELECT pg_try_advisory_lock(?) AS l', [MigrateGateCommand::PG_LOCK_KEY]);
            if (! (bool) ($row->l ?? false)) {
                $this->markTestSkipped('lockholder could not acquire the migration advisory lock');
            }

            return;
        }
        $this->markTestSkipped("lock contention test does not run on driver '{$driver}'");
    }

    private function releaseExternalLock(Connection $lockholder): void
    {
        $driver = $lockholder->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $lockholder->select('SELECT RELEASE_LOCK(?)', [MigrateGateCommand::LOCK_NAME]);

            return;
        }
        if ($driver === 'pgsql') {
            $lockholder->select('SELECT pg_advisory_unlock(?)', [MigrateGateCommand::PG_LOCK_KEY]);
        }
    }
}
