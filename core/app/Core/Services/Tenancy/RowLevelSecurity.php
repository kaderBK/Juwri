<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Juwri\Core\Models\Scopes\AccountScope;

/**
 * P1-04 slice 6 (J29-2): the PostgreSQL row-level-security layer — the database-side safety net under the app
 * scoping ({@see AccountScope}). Policies are GENERATED from {@see TenantTables} (the
 * migration metadata), so a new tenant table gets its policy from `sync()` (the migration, or
 * `php artisan tenancy:rls sync` after TenantTables was regenerated).
 *
 * Every tenant table gets ENABLE ROW LEVEL SECURITY (not FORCE: the table owner — the migration role — is not
 * subject to it) and one policy `juwri_tenant` FOR ALL (USING + WITH CHECK):
 *
 *   juwri_rls_bypass() OR account_id = juwri_rls_account() [OR account_id IS NULL on nullable-account tables]
 *
 * The two functions read the session settings `app.tenant_bypass` / `app.account_id`, which
 * {@see TenantDatabaseSession} keeps equal to the {@see TenantContext} before every query. A connection that
 * never set them (psql, a reporting tool, a leaked pooled session) sees NO tenant rows (fail-closed).
 * The platform/public/console paths are not tenant contexts and set the bypass flag explicitly — the same
 * semantics as the app scope. Superusers and the table owner bypass RLS by PostgreSQL rules: production connects
 * the app with a separate non-owner role (docs/dev/tenancy-isolation.md).
 *
 * On MySQL / SQLite everything here is a no-op (app scoping is the only layer there, J29-2).
 */
final class RowLevelSecurity
{
    public const POLICY = 'juwri_tenant';

    public const GUC_ACCOUNT = 'app.account_id';

    public const GUC_BYPASS = 'app.tenant_bypass';

    public static function supported(ConnectionInterface $db): bool
    {
        return $db instanceof Connection && $db->getDriverName() === 'pgsql';
    }

    public static function enabled(): bool
    {
        return (bool) config('tenancy.rls.enabled', true);
    }

    /**
     * Tenant tables that get a policy: every TenantTables row with an account_id column.
     *
     * @return array<string, bool> table => account_id nullable
     */
    public static function tables(): array
    {
        // Every TenantTables row carries account_id (the generator guarantees it; TenantModelsTest checks the schema).
        return array_map(fn (array $meta): bool => ! $meta['account_required'], TenantTables::tables());
    }

    /** @return list<string> the helper functions (idempotent) */
    public static function functionStatements(): array
    {
        return [
            "CREATE OR REPLACE FUNCTION juwri_rls_bypass() RETURNS boolean LANGUAGE sql STABLE AS \$\$ SELECT coalesce(current_setting('".self::GUC_BYPASS."', true), '') = 'on' \$\$",
            "CREATE OR REPLACE FUNCTION juwri_rls_account() RETURNS bigint LANGUAGE sql STABLE AS \$\$ SELECT nullif(current_setting('".self::GUC_ACCOUNT."', true), '')::bigint \$\$",
        ];
    }

    public static function predicate(bool $nullable): string
    {
        return 'juwri_rls_bypass() OR account_id = juwri_rls_account()'.($nullable ? ' OR account_id IS NULL' : '');
    }

    /**
     * The statements for one table (idempotent: the policy is dropped and re-created).
     *
     * @return list<string>
     */
    public static function tableStatements(string $table, bool $nullable): array
    {
        $t = '"'.str_replace('"', '""', $table).'"';
        $p = self::predicate($nullable);

        return [
            "ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE {$t} NO FORCE ROW LEVEL SECURITY",
            'DROP POLICY IF EXISTS '.self::POLICY." ON {$t}",
            'CREATE POLICY '.self::POLICY." ON {$t} AS PERMISSIVE FOR ALL USING ({$p}) WITH CHECK ({$p})",
        ];
    }

    /**
     * Create / refresh every policy. Returns the number of tables covered (0 when not PostgreSQL or disabled).
     */
    public static function sync(ConnectionInterface $db): int
    {
        if (! self::supported($db) || ! self::enabled()) {
            return 0;
        }
        foreach (self::functionStatements() as $sql) {
            $db->statement($sql);
        }
        $existing = self::existingTables($db);
        $n = 0;
        foreach (self::tables() as $table => $nullable) {
            if (! isset($existing[$table])) {
                // a module's table that is not installed (e.g. an ee module) — nothing to protect; or a table whose
                // account_id a later migration adds (D-P1-21-15) — that migration syncs again
                continue;
            }
            foreach (self::tableStatements($table, $nullable) as $sql) {
                $db->statement($sql);
            }
            $n++;
        }
        self::grantAppRole($db);

        return $n;
    }

    /** Remove every juwri policy and disable RLS on the tenant tables (migration down / emergency switch-off). */
    public static function drop(ConnectionInterface $db): int
    {
        if (! self::supported($db)) {
            return 0;
        }
        $existing = self::existingTables($db);
        $n = 0;
        foreach (array_keys(self::tables()) as $table) {
            if (! isset($existing[$table])) {
                continue;
            }
            $t = '"'.str_replace('"', '""', $table).'"';
            $db->statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$t}");
            $db->statement("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
            $n++;
        }
        $db->statement('DROP FUNCTION IF EXISTS juwri_rls_bypass()');
        $db->statement('DROP FUNCTION IF EXISTS juwri_rls_account()');

        return $n;
    }

    /**
     * Per-table state from the catalog: rls on, forced, has the juwri policy.
     *
     * @return array<string, array{rls: bool, force: bool, policy: bool}>
     */
    public static function status(ConnectionInterface $db): array
    {
        if (! self::supported($db)) {
            return [];
        }
        $rows = $db->select(
            "SELECT c.relname AS name, c.relrowsecurity AS rls, c.relforcerowsecurity AS force,
                    EXISTS (SELECT 1 FROM pg_policies p WHERE p.schemaname = n.nspname AND p.tablename = c.relname AND p.policyname = ?) AS policy
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')",
            [self::POLICY]
        );
        $out = [];
        foreach ($rows as $r) {
            if (array_key_exists($r->name, self::tables())) {
                $out[$r->name] = ['rls' => (bool) $r->rls, 'force' => (bool) $r->force, 'policy' => (bool) $r->policy];
            }
        }

        return $out;
    }

    /**
     * Optional (TENANCY_RLS_APP_ROLE): give the application role DML rights on the schema so a deployment can
     * connect with it instead of the owner. The role itself is created by ops (docs/dev/tenancy-isolation.md);
     * nothing happens when it is unset or does not exist.
     */
    private static function grantAppRole(ConnectionInterface $db): void
    {
        $role = (string) config('tenancy.rls.app_role', '');
        if ($role === '' || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            return;
        }
        if (! $db->selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', [$role])) {
            return;
        }
        $db->statement("GRANT USAGE ON SCHEMA public TO {$role}");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        $db->statement("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO {$role}");
        $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO {$role}");
    }

    /**
     * Remove the policy of ONE table (a migration's down() that drops the table's account_id: PostgreSQL refuses to
     * drop a column a policy uses).
     */
    public static function dropTable(ConnectionInterface $db, string $table): void
    {
        if (! self::supported($db)) {
            return;
        }
        $t = '"'.str_replace('"', '""', $table).'"';
        $db->statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$t}");
        $db->statement("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
    }

    /** @return array<string, true> the tables of the current schema that have an account_id column */
    private static function existingTables(ConnectionInterface $db): array
    {
        $out = [];
        $rows = $db->select(
            "SELECT table_name AS name FROM information_schema.columns
              WHERE table_schema = current_schema() AND column_name = 'account_id'"
        );
        foreach ($rows as $r) {
            $out[$r->name] = true;
        }

        return $out;
    }
}
