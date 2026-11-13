<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

/**
 * P6-225: safe migrations for several containers sharing one database (docker/entrypoint.sh).
 *
 *   --run   Take a database advisory lock (MySQL GET_LOCK / PostgreSQL pg_advisory_lock), run `migrate --force`, release.
 *           Only one container migrates; the others wait on the lock, then find nothing to do. The lock lives in the
 *           database itself, so it works across containers and hosts whatever CACHE_DRIVER is (file cache locks do not).
 *   --wait  Wait (bounded by --timeout) until no migration is pending. Workers / scheduler / web replicas that do not
 *           migrate use this so they never run against a half-migrated database.
 *
 * Both refuse version skew: the database lists migrations this code does not have (newer release ran on it) → exit 3.
 * Exit codes: 0 ok, 1 timeout / lock not obtained, 2 database unreachable, 3 version skew, 4 migrate failed.
 */
class MigrateGateCommand extends Command
{
    public const LOCK_NAME = 'juwri_migrate';

    /** Fixed 64-bit key for pg_advisory_lock (crc32 of LOCK_NAME). */
    public const PG_LOCK_KEY = 1813441027;

    protected $signature = 'juwri:migrate-gate
        {--run : Take the database lock and run pending migrations}
        {--wait : Wait until no migration is pending}
        {--timeout=600 : Seconds to wait for the lock / for migrations}
        {--interval=3 : Seconds between checks in --wait}';

    protected $description = 'Run migrations under a database lock, or wait for them (multi-container deploys).';

    public function handle(Migrator $migrator): int
    {
        $timeout = max(0, (int) $this->option('timeout'));
        $interval = max(1, (int) $this->option('interval'));
        $deadline = time() + $timeout;

        // The database container may still be starting: retry the connection inside the same budget.
        while (true) {
            try {
                DB::connection()->getPdo();
                break;
            } catch (\Throwable $e) {
                if (time() >= $deadline) {
                    $this->error('juwri: database not reachable: '.$e->getMessage());

                    return 2;
                }
                sleep($interval);
            }
        }

        if ($this->option('run')) {
            return $this->runLocked($migrator, $timeout);
        }

        while (true) {
            [$pending, $unknown] = $this->state($migrator);
            if ($unknown !== []) {
                return $this->skew($unknown);
            }
            if ($pending === []) {
                $this->info('juwri: database schema is current.');

                return self::SUCCESS;
            }
            if (time() >= $deadline) {
                $this->error('juwri: '.count($pending).' migration(s) still pending after '.$timeout.'s (first: '.$pending[0].'). Run them once: JUWRI_MIGRATE=1 on one web container, or `php artisan juwri:migrate-gate --run`.');

                return 1;
            }
            $this->line('juwri: waiting for '.count($pending).' pending migration(s)...');
            sleep($interval);
        }
    }

    private function runLocked(Migrator $migrator, int $timeout): int
    {
        $conn = DB::connection();
        if (! $this->acquire($conn, $timeout)) {
            $this->error('juwri: could not get the migration lock within '.$timeout.'s (another container is migrating).');

            return 1;
        }

        try {
            [, $unknown] = $this->state($migrator);
            if ($unknown !== []) {
                return $this->skew($unknown);
            }
            $code = $this->call('migrate', ['--force' => true]);

            return $code === 0 ? self::SUCCESS : 4;
        } finally {
            $this->release($conn);
        }
    }

    /** @return array{0: list<string>, 1: list<string>} [pending in code, ran in DB but unknown to code] */
    private function state(Migrator $migrator): array
    {
        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $files = array_keys($migrator->getMigrationFiles($paths));

        $repo = $migrator->getRepository();
        $ran = $repo->repositoryExists() ? $repo->getRan() : [];

        return [
            array_values(array_diff($files, $ran)),
            array_values(array_diff($ran, $files)),
        ];
    }

    /** @param list<string> $unknown */
    private function skew(array $unknown): int
    {
        $this->error('juwri: version skew: the database has '.count($unknown).' migration(s) this release does not know (first: '.$unknown[0].'). A newer release already ran on this database; start that image version, never an older one.');

        return 3;
    }

    private function acquire(Connection $conn, int $timeout): bool
    {
        return match ($conn->getDriverName()) {
            'mysql', 'mariadb' => (int) $conn->selectOne('SELECT GET_LOCK(?, ?) AS l', [self::LOCK_NAME, $timeout])->l === 1,
            'pgsql' => $this->pgAcquire($conn, $timeout),
            default => true, // sqlite: single file, single host; migrate's own transaction suffices
        };
    }

    private function pgAcquire(Connection $conn, int $timeout): bool
    {
        $deadline = time() + $timeout;
        do {
            if ((bool) $conn->selectOne('SELECT pg_try_advisory_lock(?) AS l', [self::PG_LOCK_KEY])->l) {
                return true;
            }
            sleep(1);
        } while (time() < $deadline);

        return false;
    }

    private function release(Connection $conn): void
    {
        try {
            match ($conn->getDriverName()) {
                'mysql', 'mariadb' => $conn->select('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]),
                'pgsql' => $conn->select('SELECT pg_advisory_unlock(?)', [self::PG_LOCK_KEY]),
                default => null,
            };
        } catch (\Throwable) {
            // Session end releases the lock anyway.
        }
    }
}
