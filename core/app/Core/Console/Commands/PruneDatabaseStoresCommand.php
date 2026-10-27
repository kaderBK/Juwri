<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P6-156: delete expired rows from the database session and cache stores (P6-145 tables).
 *
 * Sessions: Laravel prunes by the session lottery (config session.lottery), which only runs on web requests that
 * start a session and never when the lottery is [0, n]. Cache: the database store deletes an expired row only when
 * that key is read again, so keys never read again stay forever; expired cache_locks rows are pruned by a lottery on
 * lock acquire only. This hourly command is the guaranteed floor on every hosting profile.
 */
class PruneDatabaseStoresCommand extends Command
{
    protected $signature = 'juwri:prune-db-stores';

    protected $description = 'Delete expired rows from the database session, cache and cache_locks tables';

    public function handle(): int
    {
        $now = now()->getTimestamp();

        $sessionTable = (string) config('session.table', 'sessions');
        $sessionConn = config('session.connection');
        $sessions = 0;
        if (Schema::connection($sessionConn)->hasTable($sessionTable)) {
            $cutoff = $now - ((int) config('session.lifetime', 120) * 60);
            $sessions = DB::connection($sessionConn)->table($sessionTable)->where('last_activity', '<=', $cutoff)->delete();
        }

        $cache = 0;
        $locks = 0;
        $seen = [];
        foreach ((array) config('cache.stores', []) as $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
                continue;
            }
            $conn = $store['connection'] ?? null;
            $lockConn = $store['lock_connection'] ?? $conn;
            foreach ([[$conn, $store['table'] ?? 'cache', 'cache'], [$lockConn, $store['lock_table'] ?? 'cache_locks', 'locks']] as [$c, $table, $kind]) {
                $key = ($c ?? '').'|'.$table;
                if (isset($seen[$key]) || ! Schema::connection($c)->hasTable($table)) {
                    continue;
                }
                $seen[$key] = true;
                $n = DB::connection($c)->table($table)->where('expiration', '<=', $now)->delete();
                $kind === 'cache' ? $cache += $n : $locks += $n;
            }
        }

        $this->info("Pruned {$sessions} expired sessions, {$cache} expired cache rows, {$locks} expired cache locks.");

        return self::SUCCESS;
    }
}
