<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * O12c: log MySQL queries slower than config('logging.slow_query_ms') as a
 * [slow-query] warning, so a bad plan is visible in the log before it hurts
 * prod. Registered as a DB::listen handler in AppServiceProvider — but only
 * when the default connection is mysql, so the sqlite test suite never pays for
 * it. `evaluate()` is a pure decision so every branch is unit-testable without
 * a real database.
 */
class SlowQueryLogger
{
    /** DB::listen handler: evaluate the query and warn when it is slow. */
    public static function handle(QueryExecuted $query): void
    {
        $context = self::evaluate($query->sql, (float) $query->time, $query->connection->getDriverName());

        if ($context !== null) {
            Log::warning('[slow-query]', $context);
        }
    }

    /**
     * Should this query be logged as slow? Returns the log context, or null when
     * disabled / non-mysql / under threshold. Pure — no DB, no side effects.
     *
     * @return array{ms: float, sql: string, route: string}|null
     */
    public static function evaluate(string $sql, float $timeMs, string $driver): ?array
    {
        $slowMs = (int) config('logging.slow_query_ms', 0);

        if ($slowMs <= 0 || $driver !== 'mysql' || $timeMs < $slowMs) {
            return null;
        }

        return [
            'ms'    => round($timeMs, 1),
            'sql'   => Str::limit($sql, 300),
            'route' => self::route(),
        ];
    }

    /** Best-effort route name (or path, or 'cli') for the offending query. */
    private static function route(): string
    {
        try {
            return optional(request()->route())->getName() ?: (request()->path() ?: 'cli');
        } catch (\Throwable) {
            return 'cli';
        }
    }
}
