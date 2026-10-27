<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

/**
 * P1-04 slice 5 / P1-23 follow-up: generates the tenant-table lists from the migrations, one list per source.
 *
 *  - Community: app/Core/Services/Tenancy/TenantTables.php from the Community migrations (app/Core, app/Modules,
 *    database/migrations) — so the public mirror's list is exactly its own tables.
 *  - Each installed edition package: <package>/tenant_tables.php (a plain data file named by its manifest key
 *    `tenant_tables`) from the package's own migrations; TenantTables merges it at run time.
 *
 * `tables` (every table created with a store_id and/or account_id column, with its flags) is generated; `parents`
 * (child tables whose store_id is copied from a parent) and `user_owned` (store-less tables whose user_id is the
 * owning account's user) are decisions, hand-maintained in the generated file and kept by the generator, which
 * checks them against the schema. A table a later `*_drop_*` migration drops (in up()) is not listed.
 *
 * Plain PHP (no framework), so it also runs without an installed app. Run: `php artisan tenancy:tables`
 * (`--check` = exit 1 when a committed list differs); TenantTablesGeneratorTest fails on drift.
 */
final class TenantTablesGenerator
{
    /** Not tenant-owned even though a column is called account_id/store_id: the account/user rows themselves. */
    private const SKIP = ['accounts', 'users'];

    public const PACKAGE_FILE = 'tenant_tables.php';

    /** Blueprint calls in an alter that name a column without adding it (keys, drops, renames). */
    private const NOT_A_COLUMN = 'drop|foreign|index|unique|primary|fullText|spatialIndex|rename';

    /**
     * @param  string  $core  the application root (core/)
     * @param  array<string, string>  $packages  edition package name => absolute package root
     * @param  array{parents: array<string, list<array{0: string, 1: string}>>, user_owned: list<string>}|null  $hints  the Community
     *                                                                                                                  decisions (default: the committed TenantTables::PARENTS / USER_OWNED)
     */
    public function __construct(private string $core, private array $packages = [], private ?array $hints = null) {}

    /** @return array<string, string> absolute path => expected contents of every generated file */
    public function files(): array
    {
        $drops = [];
        foreach ([$this->communityMigrations(), ...array_map(fn (string $p) => $this->packageMigrations($p), $this->packages)] as $files) {
            foreach ($files as $file) {
                $drops = [...$drops, ...self::drops($file)];
            }
        }

        [$community, $communityCols] = self::scan($this->communityMigrations(), $drops);
        $parents = $this->hints['parents'] ?? TenantTables::PARENTS;
        $userOwned = $this->hints['user_owned'] ?? TenantTables::USER_OWNED;
        self::validate('Community', $community, $communityCols, $parents, $userOwned);

        $out = [$this->core.'/app/Core/Services/Tenancy/TenantTables.php' => self::renderCommunity($community, $parents, $userOwned)];
        foreach ($this->packages as $name => $path) {
            [$tables, $cols] = self::scan($this->packageMigrations($path), $drops);
            foreach (array_keys($tables) as $table) {
                if (isset($community[$table])) {
                    throw new \RuntimeException("{$name}: {$table} is created by a Community migration and by the package");
                }
            }
            $file = $path.'/'.self::PACKAGE_FILE;
            $current = is_file($file) ? (static fn (string $f): mixed => require $f)($file) : [];
            $current = is_array($current) ? $current : [];
            /** @var array<string, list<array{0: string, 1: string}>> $pkgParents */
            $pkgParents = (array) ($current['parents'] ?? []);
            /** @var list<string> $pkgUserOwned */
            $pkgUserOwned = array_values((array) ($current['user_owned'] ?? []));
            // A package row may point at a Community parent table (the package extends Community data).
            self::validate($name, $tables + $community, $cols + $communityCols, $pkgParents, $pkgUserOwned, array_keys($tables));
            $out[$file] = self::renderPackage($name, $tables, $pkgParents, $pkgUserOwned);
        }

        return $out;
    }

    /** @return list<string> files whose committed contents differ from the generator's */
    public function stale(): array
    {
        $stale = [];
        foreach ($this->files() as $file => $contents) {
            if (! is_file($file) || file_get_contents($file) !== $contents) {
                $stale[] = $file;
            }
        }

        return $stale;
    }

    /** @return list<string> the files written */
    public function write(): array
    {
        $written = [];
        foreach ($this->files() as $file => $contents) {
            if (! is_file($file) || file_get_contents($file) !== $contents) {
                file_put_contents($file, $contents);
                $written[] = $file;
            }
        }

        return $written;
    }

    /** @return list<string> */
    private function communityMigrations(): array
    {
        $files = [];
        foreach (['app/Core', 'app/Modules', 'database/migrations'] as $base) {
            $files = [...$files, ...self::migrationFiles($this->core.'/'.$base)];
        }

        return $files;
    }

    /** @return list<string> */
    private function packageMigrations(string $path): array
    {
        return self::migrationFiles($path);
    }

    /** @return list<string> every .php file below a folder named Migrations / migrations under $root (tests and vendor excluded) */
    private static function migrationFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $rel = '/'.basename($root).substr($path, strlen($root));
            if (! str_ends_with($path, '.php') || ! preg_match('~/(Migrations|migrations)/~', $rel)
                || preg_match('~/(tests|vendor|node_modules)/~', $rel)) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);

        return $files;
    }

    /** @return list<string> tables dropped in up() by a `*_drop_*` migration */
    private static function drops(string $file): array
    {
        if (! str_contains(basename($file), '_drop_')) {
            return [];
        }
        $up = explode('function down', (string) file_get_contents($file))[0];
        preg_match_all("~Schema::drop(?:IfExists)?\\('([a-z0-9_]+)'\\)~", $up, $m);

        return $m[1];
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $drops
     * @return array{0: array<string, array{store_id?: bool, account_id?: bool}>, 1: array<string, list<string>>} tenant tables (column => NOT NULL) and every table's columns
     */
    private static function scan(array $files, array $drops): array
    {
        $tables = [];
        $cols = [];
        $alters = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            // D-P1-21-15: a later migration may add the tenant keys to an existing table (Schema::table in up()).
            preg_match_all("~Schema::table\\('([a-z0-9_]+)',\\s*function.*?\\n(.*?)\\n\\s*\\}\\);~s", explode('function down', $src)[0], $am, PREG_SET_ORDER);
            foreach ($am as [, $table, $body]) {
                $alters[] = [$table, $body];
            }
            preg_match_all("~Schema::create\\('([a-z0-9_]+)',\\s*function.*?\\n(.*?)\\n\\s*\\}\\);~s", $src, $m, PREG_SET_ORDER);
            foreach ($m as [, $table, $body]) {
                if (in_array($table, self::SKIP, true)) {
                    continue;
                }
                $flags = [];
                foreach (['store_id', 'account_id'] as $column) {
                    if (preg_match("~\\\$table->[^;]*\\('{$column}'\\)[^;]*;~", $body, $c)) {
                        $flags[$column] = ! str_contains($c[0], 'nullable');
                    }
                }
                if ($flags !== []) {
                    $tables[$table] = $flags;
                }
                if (! isset($cols[$table])) {
                    preg_match_all("~\\\$table->\\w+\\('([a-z0-9_]+)'~", $body, $cm);
                    $cols[$table] = array_values(array_unique($cm[1]));
                }
            }
        }
        // Alters after every create (a module may alter a table another module creates; files are sorted by path).
        foreach ($alters as [$table, $body]) {
            if (in_array($table, self::SKIP, true) || ! isset($cols[$table])) {
                continue;
            }
            foreach (['store_id', 'account_id'] as $column) {
                if (preg_match('~\\$table->(?!'.self::NOT_A_COLUMN.")[^;]*\\('{$column}'\\)[^;]*;~", $body, $c)) {
                    $tables[$table][$column] = ! str_contains($c[0], 'nullable');
                }
            }
            preg_match_all('~\\$table->(?!'.self::NOT_A_COLUMN.")\\w+\\('([a-z0-9_]+)'~", $body, $cm);
            $cols[$table] = array_values(array_unique([...$cols[$table], ...$cm[1]]));
        }
        foreach ($drops as $table) {
            unset($tables[$table], $cols[$table]);
        }
        ksort($tables);

        return [$tables, $cols];
    }

    /**
     * @param  array<string, array{store_id?: bool, account_id?: bool}>  $tables
     * @param  array<string, list<string>>  $cols
     * @param  array<string, list<array{0: string, 1: string}>>  $parents
     * @param  list<string>  $userOwned
     * @param  list<string>|null  $own  the tables this source lists (null = all of $tables)
     */
    private static function validate(string $source, array $tables, array $cols, array $parents, array $userOwned, ?array $own = null): void
    {
        $own ??= array_keys($tables);
        foreach ($parents as $table => $candidates) {
            if (! in_array($table, $own, true)) {
                throw new \RuntimeException("{$source}: parents names {$table}, which is not one of its tenant tables");
            }
            foreach ($candidates as [$fk, $parent]) {
                if (! in_array($fk, $cols[$table] ?? [], true)) {
                    throw new \RuntimeException("{$source}: {$table}.{$fk} missing");
                }
                if (! isset($tables[$parent]['store_id'])) {
                    throw new \RuntimeException("{$source}: parent {$parent} of {$table} has no store_id");
                }
            }
        }
        foreach ($userOwned as $table) {
            // A nullable store_id is allowed (transaction/billing_receipts: order payments carry the store, the rest
            // the paying owner's user_id); TenantFill takes the store's account when store_id is set, else the user's.
            if (! in_array($table, $own, true) || ! in_array('user_id', $cols[$table] ?? [], true) || ($tables[$table]['store_id'] ?? false) === true) {
                throw new \RuntimeException("{$source}: {$table} is not a store-less (or optional-store) user table of its own");
            }
        }
    }

    /** @param array<string, array{store_id?: bool, account_id?: bool}> $tables */
    private static function tableLines(array $tables, string $indent): string
    {
        $lines = [];
        foreach ($tables as $table => $c) {
            $lines[] = sprintf("%s'%s' => ['store' => %s, 'account' => %s, 'required' => %s, 'account_required' => %s],\n",
                $indent, $table, self::bool(isset($c['store_id'])), self::bool(isset($c['account_id'])),
                self::bool($c['store_id'] ?? false), self::bool($c['account_id'] ?? false));
        }

        return implode('', $lines);
    }

    /** @param array<string, list<array{0: string, 1: string}>> $parents */
    private static function parentLines(array $parents, string $indent): string
    {
        ksort($parents);
        $lines = [];
        foreach ($parents as $table => $candidates) {
            $lines[] = sprintf("%s'%s' => [%s],\n", $indent, $table,
                implode(', ', array_map(fn (array $c) => sprintf("['%s', '%s']", $c[0], $c[1]), $candidates)));
        }

        return implode('', $lines);
    }

    /** @param list<string> $userOwned */
    private static function userOwnedLines(array $userOwned, string $indent): string
    {
        sort($userOwned);

        return implode('', array_map(fn (string $t) => "{$indent}'{$t}',\n", $userOwned));
    }

    private static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param  array<string, array{store_id?: bool, account_id?: bool}>  $tables
     * @param  array<string, list<array{0: string, 1: string}>>  $parents
     * @param  list<string>  $userOwned
     */
    private static function renderCommunity(array $tables, array $parents, array $userOwned): string
    {
        $template = <<<'PHP'
<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Juwri\Core\Editions\EditionPackages;

/**
 * P1-04 slice 5 — `TABLES` is GENERATED from the Community migrations by `php artisan tenancy:tables`
 * (TenantTablesGenerator); do not edit it by hand. `PARENTS` and `USER_OWNED` are hand-maintained decisions the
 * generator keeps and checks against the schema. Every tenant-owned table: `store` = has store_id, `account` = has
 * account_id (J29 tenant key), `required` = store_id NOT NULL, `account_required` = account_id NOT NULL. Used by the
 * account fill (BelongsToAccount), the ownership re-key, the tenant-scope architecture test and the PostgreSQL RLS
 * policies. An installed edition package lists its own tables in its `tenant_tables` file (same generator); read the
 * merged lists through {@see tables()}, {@see parents()}, {@see userOwned()} and {@see get()}.
 * TenantTablesGeneratorTest fails when a committed list differs from the generator, TenantModelsTest when the
 * list and the live schema disagree.
 */
final class TenantTables
{
    /** @var array<string, array{store: bool, account: bool, required: bool, account_required: bool}> */
    public const TABLES = [
%TABLES%    ];

    /**
     * Child tables whose store_id is copied from a parent row on create: [fk column, parent table], first match wins.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const PARENTS = [
%PARENTS%    ];

    /**
     * Store-less tables whose user_id is the owning account's user (account_id = that user's account).
     *
     * @var list<string>
     */
    public const USER_OWNED = [
%USER_OWNED%    ];

    /** @var array{tables: array<string, array{store: bool, account: bool, required: bool, account_required: bool}>, parents: array<string, list<array{0: string, 1: string}>>, user_owned: list<string>}|null */
    private static ?array $merged = null;

    /**
     * Every tenant table: Community + installed edition packages, sorted by name.
     *
     * @return array<string, array{store: bool, account: bool, required: bool, account_required: bool}>
     */
    public static function tables(): array
    {
        return self::merged()['tables'];
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    public static function parents(): array
    {
        return self::merged()['parents'];
    }

    /** @return list<string> */
    public static function userOwned(): array
    {
        return self::merged()['user_owned'];
    }

    /** @return array{store: bool, account: bool, required: bool, account_required: bool}|null */
    public static function get(string $table): ?array
    {
        return self::merged()['tables'][$table] ?? null;
    }

    /**
     * Tables keyed by store_id that also carry account_id (re-keyed when a store changes account).
     *
     * @return list<string>
     */
    public static function storeAccountTables(): array
    {
        return array_keys(array_filter(self::tables(), fn ($t) => $t['store'] && $t['account']));
    }

    /** Test seam: forget the merged lists (EditionPackages::fake). */
    public static function flush(): void
    {
        self::$merged = null;
    }

    /** @return array{tables: array<string, array{store: bool, account: bool, required: bool, account_required: bool}>, parents: array<string, list<array{0: string, 1: string}>>, user_owned: list<string>} */
    private static function merged(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }
        $tables = self::TABLES;
        $parents = self::PARENTS;
        $userOwned = self::USER_OWNED;
        foreach (EditionPackages::tenantTables() as $package) {
            $tables += $package['tables'];
            $parents += $package['parents'];
            $userOwned = [...$userOwned, ...$package['user_owned']];
        }
        ksort($tables);
        ksort($parents);
        sort($userOwned);

        return self::$merged = ['tables' => $tables, 'parents' => $parents, 'user_owned' => array_values(array_unique($userOwned))];
    }
}

PHP;

        return strtr($template, [
            '%TABLES%' => self::tableLines($tables, '        '),
            '%PARENTS%' => self::parentLines($parents, '        '),
            '%USER_OWNED%' => self::userOwnedLines($userOwned, '        '),
        ]);
    }

    /**
     * @param  array<string, array{store_id?: bool, account_id?: bool}>  $tables
     * @param  array<string, list<array{0: string, 1: string}>>  $parents
     * @param  list<string>  $userOwned
     */
    private static function renderPackage(string $name, array $tables, array $parents, array $userOwned): string
    {
        $template = <<<'PHP'
<?php
// SPDX-License-Identifier: LicenseRef-juwri-Enterprise-1.0
// Copyright (c) JUWRI LTD

/*
 * Tenant tables of the edition package %NAME%.
 *
 * Merged into Juwri\Core\Services\Tenancy\TenantTables at run time (manifest key `tenant_tables`). `tables` is
 * GENERATED from this package's migrations by `php artisan tenancy:tables` (do not edit it by hand); `parents` and
 * `user_owned` are hand-maintained decisions the generator keeps and checks against the schema (same meaning as
 * TenantTables::PARENTS / USER_OWNED).
 */

return [
    'tables' => [
%TABLES%    ],
    'parents' => [
%PARENTS%    ],
    'user_owned' => [
%USER_OWNED%    ],
];

PHP;

        return strtr($template, [
            '%NAME%' => $name,
            '%TABLES%' => self::tableLines($tables, '        '),
            '%PARENTS%' => self::parentLines($parents, '        '),
            '%USER_OWNED%' => self::userOwnedLines($userOwned, '        '),
        ]);
    }
}
