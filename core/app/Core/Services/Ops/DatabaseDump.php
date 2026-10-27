<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Ops;

use InvalidArgumentException;

/**
 * Bug 5 (2026-10-01): the shell commands behind `platform:db-backup` and `platform:db-restore-check`, chosen by the
 * connection's driver. MySQL / MariaDB use mysqldump + mysql; PostgreSQL uses pg_dump (plain SQL, so the artifact
 * stays a gzipped `db-*.sql.gz` with a completion trailer) + psql into a scratch database. Any other driver is
 * refused with a clear reason — SQLite is backed up by copying its database file.
 *
 * Credentials never reach argv (a process list would show them): the password travels in MYSQL_PWD / PGPASSWORD.
 * Every value interpolated into a command line goes through escapeshellarg().
 */
final class DatabaseDump
{
    /** Drivers with a dump path. */
    public const DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /**
     * @param  array<string, mixed>  $conn  a `database.connections.*` config array
     */
    private function __construct(public readonly string $driver, private readonly array $conn) {}

    /**
     * @param  array<string, mixed>  $conn
     *
     * @throws InvalidArgumentException with the operator-facing reason when the driver has no dump path
     */
    public static function for(array $conn): self
    {
        $driver = (string) ($conn['driver'] ?? '');
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(self::unsupportedReason($driver, $conn));
        }

        return new self($driver, $conn);
    }

    public static function supports(string $driver): bool
    {
        return in_array($driver, self::DRIVERS, true);
    }

    /** @param  array<string, mixed>  $conn */
    public static function unsupportedReason(string $driver, array $conn): string
    {
        if ($driver === 'sqlite') {
            $path = (string) ($conn['database'] ?? '');

            return 'sqlite has no dump step: back up by copying the database file'
                .($path !== '' && $path !== ':memory:' ? ' ('.$path.')' : '')
                .' while the app is stopped, or with `sqlite3 <file> ".backup <copy>"`';
        }

        return 'driver ['.($driver === '' ? 'none' : $driver).'] is not supported: supported drivers are '
            .implode(', ', self::DRIVERS);
    }

    public function isPostgres(): bool
    {
        return $this->driver === 'pgsql';
    }

    /**
     * P6-119a: dump/restore through PhpMysqlDump instead of the mysqldump/mysql binaries. Config `backup.dump_engine`:
     * `php` forces it, `native` never uses it, `auto` (default) uses it when proc_open is unavailable or the dump
     * binary is not found. PostgreSQL has no PHP path (it always needs pg_dump).
     */
    public function usePhpFallback(): bool
    {
        if ($this->isPostgres()) {
            return false;
        }

        return match ((string) config('backup.dump_engine', 'auto')) {
            'php' => true,
            'native' => false,
            default => ! self::procOpenAvailable() || ! self::binaryAvailable((string) (config('backup.mysqldump_path') ?: 'mysqldump')),
        };
    }

    /** proc_open exists and is not listed in disable_functions (shared hosts often disable it). */
    public static function procOpenAvailable(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return ! in_array('proc_open', $disabled, true);
    }

    /** A configured path that is executable, or a bare name found on PATH. Never spawns a process. */
    public static function binaryAvailable(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && @is_file($dir.'/'.$binary) && @is_executable($dir.'/'.$binary)) {
                return true;
            }
        }

        return false;
    }

    /** The dump tool's name, for messages. */
    public function dumpTool(): string
    {
        return $this->isPostgres() ? 'pg_dump' : 'mysqldump';
    }

    /** The client that loads a dump, for messages. */
    public function clientTool(): string
    {
        return $this->isPostgres() ? 'psql' : 'mysql';
    }

    public function database(): string
    {
        return (string) ($this->conn['database'] ?? '');
    }

    /**
     * The last line a COMPLETE dump writes. mysqldump: `-- Dump completed on …` (or bare with --skip-dump-date);
     * pg_dump plain format: `-- PostgreSQL database dump complete`.
     */
    public function completionMarker(): string
    {
        return $this->isPostgres() ? '-- PostgreSQL database dump complete' : '-- Dump completed';
    }

    /**
     * Environment for every process: the password (never argv) and, for PostgreSQL, the SSL mode.
     *
     * @return array<string, string>
     */
    public function env(): array
    {
        $password = (string) ($this->conn['password'] ?? '');
        if (! $this->isPostgres()) {
            return ['MYSQL_PWD' => $password];
        }

        $env = ['PGPASSWORD' => $password];
        if (($this->conn['sslmode'] ?? '') !== '') {
            $env['PGSSLMODE'] = (string) $this->conn['sslmode'];
        }

        return $env;
    }

    /** The whole database as plain SQL on stdout. */
    public function dumpCommand(): string
    {
        if ($this->isPostgres()) {
            $binary = (string) (config('backup.pg_dump_path') ?: 'pg_dump');

            // --no-owner/--no-privileges: the dump restores as whichever role loads it (the restore drill's
            // scratch database is owned by the app user); data, schema, sequences and RLS policies are all kept.
            return escapeshellarg($binary)
                .' --format=plain --no-owner --no-privileges --no-password'
                .$this->pgConnectionArgs()
                .' --dbname='.escapeshellarg($this->database());
        }

        $binary = (string) (config('backup.mysqldump_path') ?: 'mysqldump');

        return escapeshellarg($binary)
            .' --single-transaction --quick --routines --triggers --events'
            .$this->mysqlConnectionArgs()
            .' '.escapeshellarg($this->database());
    }

    /** Drop (if present) and create the scratch database. Runs against the live database's server. */
    public function recreateScratchCommand(string $scratch): string
    {
        if ($this->isPostgres()) {
            // Two -c options: psql runs each in its own transaction (DROP/CREATE DATABASE refuse a transaction block).
            return $this->psqlBase($this->database())
                .' -c '.escapeshellarg('DROP DATABASE IF EXISTS '.$this->pgIdent($scratch))
                .' -c '.escapeshellarg('CREATE DATABASE '.$this->pgIdent($scratch));
        }

        return $this->mysqlBase()
            .' -e '.escapeshellarg('DROP DATABASE IF EXISTS '.$this->mysqlIdent($scratch).'; CREATE DATABASE '.$this->mysqlIdent($scratch).';');
    }

    public function dropScratchCommand(string $scratch): string
    {
        if ($this->isPostgres()) {
            return $this->psqlBase($this->database())
                .' -c '.escapeshellarg('DROP DATABASE IF EXISTS '.$this->pgIdent($scratch));
        }

        return $this->mysqlBase().' -e '.escapeshellarg('DROP DATABASE IF EXISTS '.$this->mysqlIdent($scratch).';');
    }

    /** A client reading SQL from stdin into $scratch, stopping at the first error. */
    public function loadCommand(string $scratch): string
    {
        if ($this->isPostgres()) {
            return $this->psqlBase($scratch).' --quiet';
        }

        return $this->mysqlBase().' '.escapeshellarg($scratch);
    }

    /**
     * Count of base tables in the connection's own schema (MySQL: the database; PostgreSQL: its search_path schema).
     *
     * @return array{0: string, 1: list<string>} SQL and bindings
     */
    public function tableCountQuery(string $database): array
    {
        if ($this->isPostgres()) {
            $schema = (string) ($this->conn['search_path'] ?? $this->conn['schema'] ?? 'public');
            $schema = trim(explode(',', $schema)[0], " \"'") ?: 'public';

            return ["SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_catalog = ? AND table_schema = ? AND table_type = 'BASE TABLE'", [$database, $schema]];
        }

        return ['SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?', [$database]];
    }

    private function mysqlConnectionArgs(): string
    {
        return ' --host='.escapeshellarg((string) ($this->conn['host'] ?? '127.0.0.1'))
            .' --port='.escapeshellarg((string) ($this->conn['port'] ?? '3306'))
            .' --user='.escapeshellarg((string) ($this->conn['username'] ?? 'root'));
    }

    private function pgConnectionArgs(): string
    {
        return ' --host='.escapeshellarg((string) ($this->conn['host'] ?? '127.0.0.1'))
            .' --port='.escapeshellarg((string) ($this->conn['port'] ?? '5432'))
            .' --username='.escapeshellarg((string) ($this->conn['username'] ?? 'postgres'));
    }

    private function mysqlBase(): string
    {
        return escapeshellarg((string) (config('backup.mysql_path') ?: 'mysql')).$this->mysqlConnectionArgs();
    }

    private function psqlBase(string $database): string
    {
        return escapeshellarg((string) (config('backup.psql_path') ?: 'psql'))
            .' --no-psqlrc --no-password --set=ON_ERROR_STOP=1'
            .$this->pgConnectionArgs()
            .' --dbname='.escapeshellarg($database);
    }

    private function pgIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function mysqlIdent(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
