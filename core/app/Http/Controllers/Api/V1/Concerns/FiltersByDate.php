<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * P6-339: validated `?from` / `?to` / `?since` date filters for v1 list endpoints.
 *
 * Why this trait: the orders() and reservations() lists previously piped the raw
 * query string straight into `where('created_at', ...)` — a `?from[]=1` therefore
 * surfaced as a 500, and a date-only `?to=2026-10-05` was treated as midnight,
 * silently dropping everything created later that day.
 *
 * Contract:
 *  - each of `from`, `to`, `since` is `nullable|string|date` — anything else (an
 *    array, garbage) is rejected by `$request->validate(...)` and surfaces as the
 *    standard 422 API envelope (Laravel renders ValidationException for
 *    JSON/api/* requests on its own — we never hand-build an error body);
 *  - date-only values (no `:` and no `T`) are interpreted in the STORE's local
 *    calendar via {@see StoreClock::rangeWindow()}, so `?to=2026-10-05` covers
 *    the whole store-local day and uses `<` against the next day's start — a
 *    DST-aware half-open interval instead of `Y-m-d 00:00:00`;
 *  - values that include a time (`2026-10-05 15:00:00`, `2026-10-05T15:00`, …)
 *    are parsed with Carbon in `StoreClock::tz($storeId)` and converted back to
 *    the app timezone, then compared with the original `>=` / `<=`;
 *  - `from` and `since` are inclusive lower bounds; `to` is exclusive for
 *    date-only inputs, inclusive (`<=`) when a time was supplied.
 *
 * @see StoreController::orders()
 * @see StoreController::reservations()
 */
trait FiltersByDate
{
    /**
     * Validate `?from`/`?to`/`?since` and apply them to $column.
     *
     * @template TQuery of EloquentBuilder<*>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function applyDateFilters(
        EloquentBuilder|QueryBuilder $query,
        string $column,
        Request $request,
        int $storeId,
    ): EloquentBuilder|QueryBuilder {
        // Step 1: validate. A ValidationException bubbles up and Laravel renders
        // it as the 422 API envelope on the api/* route group — that is exactly
        // the "do not hand-build errors" promise the task contract asks for.
        $request->validate([
            'from' => 'nullable|string|date',
            'to' => 'nullable|string|date',
            'since' => 'nullable|string|date',
        ]);

        // Step 2: from + since are inclusive lower bounds. Either can appear;
        // apply both as separate `>=` constraints so a caller may send `from`
        // and `since` together without trampling one with the other.
        $from = $request->query('from');
        if ($from !== null && $from !== '') {
            $query->where($column, '>=', $this->lowerBound($from, $storeId));
        }

        $since = $request->query('since');
        if ($since !== null && $since !== '') {
            $query->where($column, '>=', $this->lowerBound($since, $storeId));
        }

        // Step 3: `to` may be either exclusive (date-only — store-local day end)
        // or inclusive (a wall-clock instant with a time). The operator flips on
        // the same date-only test as the parse.
        $to = $request->query('to');
        if ($to !== null && $to !== '') {
            if ($this->isDateOnly($to)) {
                $query->where($column, '<', $this->exclusiveUpperBound($to, $storeId));
            } else {
                $query->where($column, '<=', $this->inclusiveUpperBound($to, $storeId));
            }
        }

        return $query;
    }

    /** A `2026-10-05` style value has neither `:` (HH:MM) nor `T` (ISO 8601 separator). */
    private function isDateOnly(string $value): bool
    {
        return ! str_contains($value, ':') && ! str_contains($value, 'T');
    }

    /**
     * Parse a lower-bound value into an app-timezone Carbon. `null` timestamps
     * slip through validation for `nullable`, so callers must pass a non-empty
     * value here.
     */
    private function lowerBound(string $value, int $storeId): Carbon
    {
        if ($this->isDateOnly($value)) {
            // rangeWindow($storeId, $from, $to) returns the start-of-day on the
            // left; passing the same value to both arms is the canonical "single
            // store-local day" interval.
            return StoreClock::rangeWindow($storeId, $value, $value)[0];
        }

        return Carbon::parse($value, StoreClock::tz($storeId))
            ->setTimezone($this->appTz());
    }

    /** Exclusive upper for date-only: start-of-NEXT-day in the store's calendar. */
    private function exclusiveUpperBound(string $value, int $storeId): Carbon
    {
        return StoreClock::rangeWindow($storeId, $value, $value)[1];
    }

    /** Inclusive upper for has-time values: the parsed instant, converted to the app tz. */
    private function inclusiveUpperBound(string $value, int $storeId): Carbon
    {
        return Carbon::parse($value, StoreClock::tz($storeId))
            ->setTimezone($this->appTz());
    }

    private function appTz(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }
}
