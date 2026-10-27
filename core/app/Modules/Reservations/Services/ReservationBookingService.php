<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services;

use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Floor FP5 — assign a reservation to a real table with a server-side double-book
 * guard: no two active reservations on the same table within a +/- window.
 *
 * HPMS E1 — the same guard is now wired into the reservation CREATE paths
 * (dashboard store + public book). A booking that names a table is refused when
 * another active reservation already holds that table within a buffer window;
 * tableless bookings skip the table clash. The window is per-store, read from the
 * `reservation_buffer_minutes` option (default WINDOW_MIN). Blackout dates/ranges
 * are enforced too (`reservation_blackouts` option).
 */
class ReservationBookingService
{
    public const WINDOW_MIN = 90;

    /** The clash buffer for a store, in minutes (option override, default WINDOW_MIN). */
    public static function bufferMinutes(int $storeId): int
    {
        $raw = store_options($storeId, 'reservation_buffer_minutes', self::WINDOW_MIN);
        $min = (int) $raw;

        return $min > 0 ? $min : self::WINDOW_MIN;
    }

    /** @throws \RuntimeException on a clash */
    public static function assignTable(Reservation $res, int $tableId): Reservation
    {
        // P6-239: one lock per store+table; the clash is re-checked while it is held.
        $lock = Cache::lock('res-table-id:'.$res->store_id.':'.$tableId, 15);
        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            throw new \RuntimeException('That table is busy. Please try again.');
        }
        try {
            return self::assignTableLocked($res, $tableId);
        } finally {
            $lock->release();
        }
    }

    private static function assignTableLocked(Reservation $res, int $tableId): Reservation
    {
        $at = Carbon::parse($res->reserved_at);
        $window = self::bufferMinutes((int) $res->store_id);
        $clash = Reservation::where('id', '!=', $res->id)
            ->where('table_id', $tableId)
            ->whereIn('status', ['pending', 'confirmed', 'seated'])
            ->whereBetween('reserved_at', [$at->copy()->subMinutes($window), $at->copy()->addMinutes($window)])
            ->exists();
        if ($clash) {
            throw new \RuntimeException('That table is already booked around this time.');
        }
        $res->table_id = $tableId;
        $res->save();

        return $res;
    }

    /**
     * P6-239: run $create while holding ONE lock per store+table, after re-checking the
     * clash inside it, so two concurrent bookings of the same table/time cannot both pass.
     * Tableless bookings skip the lock (nothing to clash on). Returns $create()'s result.
     *
     * @template T
     *
     * @param  callable():T  $create
     * @return T
     *
     * @throws \RuntimeException when the table clashes or the lock cannot be taken
     */
    public static function withTableLock(int $storeId, ?string $tableNumber, string $reservedAt, callable $create, ?int $excludeId = null)
    {
        $tableNumber = $tableNumber !== null ? trim($tableNumber) : '';
        if ($tableNumber === '') {
            return $create();
        }
        $lock = Cache::lock('res-table:'.$storeId.':'.mb_strtolower($tableNumber), 15);
        try {
            $lock->block(5);
        } catch (LockTimeoutException $e) {
            throw new \RuntimeException('That table is busy. Please try again.');
        }
        try {
            if (self::clashesForCreate($storeId, $tableNumber, $reservedAt, null, $excludeId)) {
                throw new \RuntimeException('That table is already booked around this time.');
            }

            return $create();
        } finally {
            $lock->release();
        }
    }

    /**
     * E1 create-path table clash: is another active reservation already holding
     * this table (by its label / table_number) within the buffer window? A
     * tableless booking (null/blank table) skips this check and returns false.
     */
    public static function clashesForCreate(int $storeId, ?string $tableNumber, string $reservedAt, ?int $bufferMin = null, ?int $excludeId = null): bool
    {
        $tableNumber = $tableNumber !== null ? trim($tableNumber) : '';
        if ($tableNumber === '') {
            return false; // tableless — nothing to clash on
        }
        try {
            $at = Carbon::parse($reservedAt);
        } catch (\Throwable $e) {
            return false; // an unparseable value is rejected by the date rule already
        }
        $window = $bufferMin !== null && $bufferMin > 0 ? $bufferMin : self::bufferMinutes($storeId);

        return Reservation::where('store_id', $storeId)
            ->where('table_number', $tableNumber)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->whereIn('status', ['pending', 'confirmed', 'seated'])
            ->whereBetween('reserved_at', [$at->copy()->subMinutes($window), $at->copy()->addMinutes($window)])
            ->exists();
    }

    /**
     * E1 blackout: does this requested time fall inside a store blackout? The
     * `reservation_blackouts` option holds a JSON array of either
     * `{"date":"Y-m-d"}` (whole-day block) or `{"from":"Y-m-d H:i","to":"Y-m-d H:i"}`
     * (window block). Absent / empty / malformed → never blocks (back-compat).
     */
    public static function blackoutHit(int $storeId, string $reservedAt): bool
    {
        $entries = self::blackouts($storeId);
        if ($entries === []) {
            return false;
        }
        try {
            $at = Carbon::parse($reservedAt);
        } catch (\Throwable $e) {
            return false;
        }
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (! empty($entry['date'])) {
                try {
                    if ($at->format('Y-m-d') === Carbon::parse((string) $entry['date'])->format('Y-m-d')) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            } elseif (! empty($entry['from']) && ! empty($entry['to'])) {
                try {
                    $from = Carbon::parse((string) $entry['from']);
                    $to = Carbon::parse((string) $entry['to']);
                    if ($at->betweenIncluded($from, $to)) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        return false;
    }

    /**
     * Decode the `reservation_blackouts` option into a normalized array of plain
     * arrays. store_options() hands back a raw string OR (for a JSON value) an
     * already-decoded stdClass/array — normalize both to nested arrays.
     *
     * @return array<int,mixed>
     */
    public static function blackouts(int $storeId): array
    {
        $raw = store_options($storeId, 'reservation_blackouts', null);
        if (empty($raw)) {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : json_decode(json_encode($raw), true);

        return is_array($decoded) ? $decoded : [];
    }
}
