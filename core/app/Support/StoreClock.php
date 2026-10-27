<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Support\RequestEpoch;

/**
 * Store-local calendar days for reports, exports and daily jobs (P6-102).
 *
 * Timestamps are persisted in the app timezone (UTC). A "day" in a store-facing
 * report is the store's own calendar day, which on a DST change is 23 or 25 hours
 * long, so windows are always built in the store timezone and converted to the
 * app timezone as a half-open [start, end) interval. Never use whereDate() on a
 * UTC column for these.
 */
final class StoreClock
{
    /** @var array<string, true> */
    private static array $warned = [];

    /** @var list<string>|null */
    private static ?array $zones = null;

    private static ?int $memoApp = null;

    /** Drop the memos when the app instance changes (tests rebuild it; ids and zones must not leak across). */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$warned = [];
            self::$zones = null;
        }
    }

    /** @return list<string> */
    private static function validZones(): array
    {
        self::scopeMemo();

        return self::$zones ??= array_merge(timezone_identifiers_list(), ['UTC']);
    }

    public static function tz(int|string|null $storeId): string
    {
        $appTz = (string) (config('app.timezone') ?: 'UTC');

        if (! $storeId) {
            return $appTz;
        }
        $tz = (string) (store_tz($storeId) ?: $appTz);
        if (! in_array($tz, self::validZones(), true)) {
            self::scopeMemo();
            if (! isset(self::$warned[$tz])) {
                self::$warned[$tz] = true;
                Log::warning('StoreClock: invalid store timezone, using app timezone', ['store_id' => $storeId, 'timezone' => $tz]);
            }

            return $appTz;
        }

        return $tz;
    }

    /** Store-local "now". */
    public static function now(int|string|null $storeId): Carbon
    {
        return Carbon::now(self::tz($storeId));
    }

    /** Store-local date string (Y-m-d) for today. */
    public static function today(int|string|null $storeId): string
    {
        return self::now($storeId)->toDateString();
    }

    /**
     * The store-local day containing $date (Y-m-d string, or a moment; null = today)
     * as [start, endExclusive] in the app timezone.
     *
     * @return array{Carbon, Carbon}
     */
    public static function dayWindow(int|string|null $storeId, CarbonInterface|string|null $date = null): array
    {
        $tz = self::tz($storeId);
        $day = match (true) {
            $date === null => Carbon::now($tz),
            $date instanceof CarbonInterface => Carbon::instance($date)->setTimezone($tz),
            default => Carbon::parse($date, $tz),
        };
        $appTz = (string) (config('app.timezone') ?: 'UTC');
        // startOfDay + addDay (not +24h) so DST days come out 23 h / 25 h.
        $start = $day->copy()->startOfDay();

        return [$start->copy()->setTimezone($appTz), $start->copy()->addDay()->startOfDay()->setTimezone($appTz)];
    }

    /**
     * Inclusive store-local date range [from..to] as [start, endExclusive] in the
     * app timezone. Either bound may be null (null = open on that side).
     *
     * @return array{?Carbon, ?Carbon}
     */
    public static function rangeWindow(int|string|null $storeId, ?string $from, ?string $to): array
    {
        return [
            filled($from) ? self::dayWindow($storeId, $from)[0] : null,
            filled($to) ? self::dayWindow($storeId, $to)[1] : null,
        ];
    }

    /**
     * Constrain $column to the store-local inclusive date range.
     *
     * @template TQuery of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereLocalDateBetween(EloquentBuilder|QueryBuilder $query, string $column, int|string|null $storeId, ?string $from, ?string $to): EloquentBuilder|QueryBuilder
    {
        [$start, $end] = self::rangeWindow($storeId, $from, $to);
        if ($start) {
            $query->where($column, '>=', $start);
        }
        if ($end) {
            $query->where($column, '<', $end);
        }

        return $query;
    }

    /** Store-local Y-m-d of a stored (app-timezone) moment. */
    public static function localDate(int|string|null $storeId, CarbonInterface|string|null $moment): ?string
    {
        if ($moment === null || $moment === '') {
            return null;
        }
        $appTz = (string) (config('app.timezone') ?: 'UTC');
        $c = $moment instanceof CarbonInterface ? Carbon::instance($moment) : Carbon::parse($moment, $appTz);

        return $c->setTimezone(self::tz($storeId))->toDateString();
    }
}
