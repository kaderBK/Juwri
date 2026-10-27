<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use PDO;

/**
 * P1-04 slice 6: keeps the PostgreSQL session settings read by the RLS policies ({@see RowLevelSecurity}) equal to
 * the {@see TenantContext} — `app.account_id` = the subject's account inside a tenant context, `app.tenant_bypass`
 * = 'on' everywhere else (platform, public, console, jobs without a tenant, TenantContext::bypass()).
 *
 * Pool-safety (why session-level `set_config(…, false)` and not `SET LOCAL`): `SET LOCAL` only lives inside a
 * transaction, and most of the app's queries run outside one — wrapping every request/job in a transaction
 * would change locking and DB::transaction() semantics everywhere. Instead the state is reconciled BEFORE EVERY
 * QUERY (a `beforeExecuting` hook on each pgsql connection, one extra round trip only when the wanted state
 * differs from what this PDO last got), so:
 *  - a persistent / Octane-reused connection never carries the previous request's tenant into the next query;
 *  - a ROLLBACK (which reverts set_config done inside the transaction) invalidates the cache, so the next query
 *    sets it again instead of trusting a reverted value;
 *  - a reconnect gets a new PDO (the cache is keyed by PDO), so the settings are re-applied;
 *  - the end of a tenant context resets the session to the fail-closed state ({@see self::reset()}).
 * External poolers must run in SESSION mode (PgBouncer `pool_mode = session`, `server_reset_query = DISCARD ALL`):
 * in transaction mode another client could get the server session between our set_config and the query.
 *
 * The settings are applied through the raw PDO: no query events, no query log, no recursion.
 */
final class TenantDatabaseSession
{
    /** @var \WeakMap<PDO, string>|null PDO => applied state (weak: a closed PDO drops its entry) */
    private static ?\WeakMap $applied = null;

    /** @var \WeakMap<Connection, true>|null connections with the hook attached */
    private static ?\WeakMap $attached = null;

    /**
     * Both maps are weak and keyed by the connection / PDO object, so their lifetime IS the connection's: a persistent
     * connection of a long-lived Octane worker keeps its hook and its last applied state across requests (a new stamp per
     * request would attach the hook again to the same connection every time). The state itself is reconciled before every
     * query ({@see self::sync()}), so nothing here can carry a tenant over.
     */
    private static function scopeMemo(): void
    {
        self::$applied ??= new \WeakMap;
        self::$attached ??= new \WeakMap;
    }

    /** Called once per application boot (CoreServiceProvider). */
    public static function register(DatabaseManager $db, Dispatcher $events): void
    {
        if (! RowLevelSecurity::enabled()) {
            return;
        }
        foreach ($db->getConnections() as $connection) {
            self::attach($connection);
        }
        $events->listen(ConnectionEstablished::class, fn (ConnectionEstablished $e) => self::attach($e->connection));
        $events->listen(TransactionRolledBack::class, fn (TransactionRolledBack $e) => self::forget($e->connection));
    }

    public static function attach(Connection $connection): void
    {
        if (! RowLevelSecurity::supported($connection)) {
            return;
        }
        self::scopeMemo();
        if (isset(self::$attached[$connection])) {
            return;
        }
        self::$attached[$connection] = true;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $conn): void {
            self::sync($conn);
        });
    }

    /** The state the current tenant context wants: 'bypass' or 'account:<id>' ('account:' = owner without account). */
    public static function wanted(): string
    {
        $ctx = TenantContext::current();
        if (! $ctx->activeWhenSettled()) {
            return 'bypass';
        }

        return 'account:'.($ctx->accountId() ?? '');
    }

    /** Bring the connection's session settings in line with the tenant context (before a query). */
    public static function sync(Connection $connection): void
    {
        $state = self::wanted();
        try {
            $connection->reconnectIfMissingConnection();
            $write = $connection->getPdo();
        } catch (\PDOException) {
            // Cannot connect (e.g. the database does not exist yet): let the query itself connect and fail with
            // its normal QueryException — callers such as Laravel's parallel-testing database setup rely on it.
            return;
        }
        self::apply($write, $state);
        $read = $connection->getReadPdo();
        if ($read !== $write) {
            self::apply($read, $state);
        }
    }

    /**
     * Fail-closed state on every connection this process touched (end of a tenant request / job). The next query
     * reconciles again, so platform work after the reset still gets its explicit bypass.
     */
    public static function reset(): void
    {
        self::scopeMemo();
        foreach (app('db')->getConnections() as $connection) {
            if (! RowLevelSecurity::supported($connection) || ! isset(self::$attached[$connection])) {
                continue;
            }
            foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
                if ($pdo instanceof PDO && (self::$applied[$pdo] ?? 'closed') !== 'closed') {
                    try {
                        self::apply($pdo, 'closed');
                    } catch (\Throwable) {
                        unset(self::$applied[$pdo]); // aborted transaction: the next query re-applies
                    }
                }
            }
        }
    }

    /** A rollback may have reverted settings made inside the transaction: re-apply on the next query. */
    public static function forget(Connection $connection): void
    {
        self::scopeMemo();
        foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
            if ($pdo instanceof PDO && self::$applied !== null) {
                unset(self::$applied[$pdo]);
            }
        }
    }

    private static function apply(PDO $pdo, string $state): void
    {
        self::scopeMemo();
        if ((self::$applied[$pdo] ?? null) === $state) {
            return;
        }
        [$bypass, $account] = match (true) {
            $state === 'bypass' => ['on', ''],
            $state === 'closed' => ['', ''],
            default => ['', substr($state, strlen('account:'))],
        };
        $stmt = $pdo->prepare('SELECT set_config(?, ?, false), set_config(?, ?, false)');
        $stmt->execute([RowLevelSecurity::GUC_BYPASS, $bypass, RowLevelSecurity::GUC_ACCOUNT, $account]);
        $stmt->closeCursor();
        self::$applied[$pdo] = $state;
    }
}
