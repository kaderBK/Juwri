<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Services\OrderBoard;

use App\Models\Order;
use App\Services\FeatureAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Ordering\Models\DailyToken;

/**
 * PARITY-RUN T2-1: daily customer token numbers ("now serving 42").
 *
 * One counter per store + branch + STORE-LOCAL day. The number is what the
 * public board shows, so it is also the only thing about an order that ever
 * leaves the building — no name, no phone, no order id.
 *
 * Design notes worth keeping:
 *  - The day rolls over at the branch's (else the store's) local midnight, not
 *    the server's. A Tripoli restaurant's counter must reset when ITS day does.
 *  - Numbering is a locked read-modify-write, never `UPDATE … SET seq = seq + 1`
 *    with hand-written SQL: prefixed installs (qrtest_) and the sqlite test DB
 *    make raw table names a production-only failure.
 *  - A store that has the board off gets NO tokens at all. Turning the board on
 *    starts numbering from the next order — nothing is backfilled.
 */
class DailyTokenService
{
    /** Order types that can be opted into numbering (one post-option per type). */
    public const TYPES = ['takeaway', 'on-table', 'delivery', 'room_service'];

    /** Counter-service defaults: pickup-style types on, delivery-style off. */
    private const TYPE_DEFAULTS = [
        'takeaway' => '1',
        'on-table' => '1',
        'delivery' => '0',
        'room_service' => '0',
    ];

    /** Per-request memo so a board render does not re-resolve the same store. */
    private static array $tzMemo = [];

    /* Cross-test leak guard: this memo is keyed by restaurant/branch id and
       holds DB-derived data. RefreshDatabase rolls rows back but hands the next
       test the same low ids, so a stale entry answers for an unrelated store.
       One app instance per request AND per test => rebind on a new app.
       Pinned by tests/Modules/Platform/StaticMemoAppScopeTest.php. */
    private static ?int $memoApp = null;

    /** Branch timezone wins, else the store option, else the app default. */
    public static function timezone(int $storeId, ?int $branchId = null): string
    {
        self::rebindMemoOnNewApp();

        $memoKey = $storeId.':'.(int) $branchId;
        if (isset(self::$tzMemo[$memoKey])) {
            return self::$tzMemo[$memoKey];
        }

        $tz = null;
        if ($branchId) {
            $tz = Branch::where('id', $branchId)->value('timezone');
        }
        if (! $tz) {
            $tz = store_options($storeId, 'timezone') ?: null;
        }
        $tz = (string) ($tz ?: config('app.timezone', 'UTC'));

        // A store with a typo'd timezone must not take the board down.
        try {
            Carbon::now($tz);
        } catch (\Throwable $e) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return self::$tzMemo[$memoKey] = $tz;
    }

    /** Today's calendar date ("Y-m-d") in the store/branch timezone. */
    public static function today(int $storeId, ?int $branchId = null): string
    {
        return Carbon::now(self::timezone($storeId, $branchId))->format('Y-m-d');
    }

    /** Is the board (and therefore numbering) live for this store? */
    public static function enabled(int $storeId): bool
    {
        if (! $storeId) {
            return false;
        }
        if (! FeatureAccess::storeAllows($storeId, 'order_board')) {
            return false;
        }

        return (string) store_options($storeId, 'order_board_enabled') === '1';
    }

    /** Does this order type get a number? (per-type owner opt-in) */
    public static function typeEnabled(int $storeId, ?string $type): bool
    {
        $type = (string) $type;
        if (! in_array($type, self::TYPES, true)) {
            return false;
        }

        $key = 'order_board_type_'.str_replace('-', '_', $type);
        $value = store_options($storeId, $key);

        return $value === false || $value === null || $value === ''
            ? self::TYPE_DEFAULTS[$type] === '1'
            : (string) $value === '1';
    }

    /**
     * Hand out the next number for a store/branch/day.
     *
     * Locked read-modify-write inside a transaction. The only race left is two
     * *first* orders of the day inserting the counter row at once — the unique
     * index rejects the loser, which then retries onto the UPDATE path.
     */
    public static function next(int $storeId, ?int $branchId = null, ?string $date = null): int
    {
        $branch = (int) ($branchId ?: 0);
        $day = $date ?: self::today($storeId, $branchId);

        $lastError = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($storeId, $branch, $day) {
                    $row = DailyToken::query()
                        ->where('store_id', $storeId)
                        ->where('branch_id', $branch)
                        ->where('token_date', $day)
                        ->lockForUpdate()
                        ->first();

                    if ($row) {
                        $row->last_seq = (int) $row->last_seq + 1;
                        $row->save();

                        return (int) $row->last_seq;
                    }

                    DailyToken::create([
                        'store_id' => $storeId,
                        'branch_id' => $branch,
                        'token_date' => $day,
                        'last_seq' => 1,
                    ]);

                    return 1;
                });
            } catch (QueryException $e) {
                // Lost the insert race (or a lock timeout) — try again; the next
                // pass finds the row and takes the increment path.
                $lastError = $e;
                usleep(20000 * ($attempt + 1));
            }
        }

        throw $lastError;
    }

    /**
     * Give an order its number, if the store wants one for that order type.
     * Idempotent, and quiet: `saveQuietly` keeps this out of the model's own
     * updated() hook so numbering can never re-enter the status listeners.
     *
     * @return int|null the assigned (or already-present) token, null when the
     *                  store/type is not opted in
     */
    public static function assign(Order $order): ?int
    {
        if (! empty($order->token_number)) {
            return (int) $order->token_number;
        }

        $storeId = (int) $order->store_id;
        if (! self::enabled($storeId) || ! self::typeEnabled($storeId, $order->type)) {
            return null;
        }

        $branchId = (int) ($order->branch_id ?: 0);
        $day = self::today($storeId, $branchId ?: null);
        $seq = self::next($storeId, $branchId, $day);

        $order->token_number = $seq;
        $order->token_date = $day;
        $order->saveQuietly();

        return $seq;
    }

    /**
     * T1 (printing) SEAM — the token line for a printed receipt/kitchen ticket.
     *
     * Returns null when the order has no number, so the caller can splice it in
     * with a plain null check and nothing changes for stores without a board:
     *
     *     if ($line = DailyTokenService::receiptLine($order)) { … }
     *
     * Deliberately plain text (no ESC/POS control bytes): the renderer decides
     * width, centering and double-height. Nothing under Services/Printing is
     * touched by this track.
     */
    public static function receiptLine(Order $order): ?string
    {
        if (empty($order->token_number)) {
            return null;
        }

        return ___('Number').' '.(int) $order->token_number;
    }

    /** Test seam: forget memoized timezones. */
    public static function clearMemo(): void
    {
        self::$tzMemo = [];
    }

    /** Clears ONLY this class's memo — never another class's. */
    private static function rebindMemoOnNewApp(): void
    {
        $appId = RequestEpoch::id();
        if (self::$memoApp !== $appId) {
            self::$memoApp = $appId;
            self::$tzMemo = [];
        }
    }
}
