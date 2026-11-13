<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Services\OrderBoard;

use App\Models\Order;
use App\Services\FeatureAccess;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;

/**
 * PARITY-RUN T2: the public order-status board.
 *
 * Two responsibilities:
 *  1. read the store's board configuration (one place, so the page, the JSON
 *     poll and the settings tab can never disagree);
 *  2. build the board payload — which is nothing but token numbers. No name, no
 *     phone, no order id, no total. A screen hanging in a public dining room is
 *     the least trustworthy surface we own, so it is given the least data.
 *
 * The status feed is NOT a new event bus: `Order::booted()`'s updated() hook is
 * the single choke point every status-write path already goes through, and
 * {@see self::statusChanged()} is called from there inside its own try/catch.
 */
class OrderBoardService
{
    /** Kitchen states that belong in the "Preparing" column. */
    public const PREPARING_STATUSES = ['new', 'preparing'];

    /** Kitchen states that belong in the "Ready" column. */
    public const READY_STATUSES = ['ready'];

    /** Ready entries fall off the board after this many minutes unless configured. */
    public const DEFAULT_READY_MINUTES = 5;

    /** How often the board re-polls, in seconds. */
    public const POLL_SECONDS = 5;

    /** Never paint more than this per column — a TV cannot show more anyway. */
    private const MAX_PER_COLUMN = 40;

    /** Effective board configuration for a store. */
    public static function settings(int $storeId): array
    {
        $readyMinutes = (int) store_options($storeId, 'order_board_ready_minutes', self::DEFAULT_READY_MINUTES);
        if ($readyMinutes < 0 || $readyMinutes > 240) {
            $readyMinutes = self::DEFAULT_READY_MINUTES;
        }

        $chime = store_options($storeId, 'order_board_chime');

        return [
            'enabled' => DailyTokenService::enabled($storeId),
            'ready_minutes' => $readyMinutes,
            'chime' => $chime === false || $chime === null || $chime === '' ? true : (string) $chime === '1',
            'poll_seconds' => self::POLL_SECONDS,
            'types' => array_values(array_filter(
                DailyTokenService::TYPES,
                fn ($type) => DailyTokenService::typeEnabled($storeId, $type)
            )),
        ];
    }

    /** Is the board servable for this store? (kill-switch + plan + owner toggle) */
    public static function visible(int $storeId): bool
    {
        return FeatureAccess::storeAllowsCached($storeId, 'order_board')
            && (string) store_options($storeId, 'order_board_enabled') === '1';
    }

    /** Public board URL for a store (optionally pinned to one branch). */
    public static function url(Store $store, ?int $branchId = null): string
    {
        $url = route('board.show', $store->slug);

        return $branchId ? $url.'?branch='.$branchId : $url;
    }

    /**
     * The whole public payload. TOKENS ONLY — see the class docblock.
     *
     * @return array{preparing:int[], ready:int[], date:string, config:array}
     */
    public static function payload(Store $store, ?int $branchId = null): array
    {
        $storeId = (int) $store->id;
        $settings = self::settings($storeId);
        $today = DailyTokenService::today($storeId, $branchId);

        $orders = Order::query()
            ->where('store_id', $storeId)
            ->whereIn('status', array_merge(self::PREPARING_STATUSES, self::READY_STATUSES))
            ->whereNotNull('token_number')
            ->where('token_date', $today) // yesterday's #7 must never sit next to today's
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->live() // scheduled-but-unreleased orders stay out of live queues
            ->orderBy('token_number')
            ->limit(self::MAX_PER_COLUMN * 2)
            ->get(['status', 'token_number', 'ready_at']);

        $preparing = $orders
            ->filter(fn ($o) => in_array($o->status, self::PREPARING_STATUSES, true))
            ->map(fn ($o) => (int) $o->token_number);

        $ready = $orders->filter(fn ($o) => in_array($o->status, self::READY_STATUSES, true));
        if ($settings['ready_minutes'] > 0) {
            $cutoff = Carbon::now()->subMinutes($settings['ready_minutes']);
            $ready = $ready->filter(fn ($o) => ! $o->ready_at || $o->ready_at->gt($cutoff));
        }

        return [
            'preparing' => $preparing->take(self::MAX_PER_COLUMN)->values()->all(),
            'ready' => $ready->map(fn ($o) => (int) $o->token_number)
                ->take(self::MAX_PER_COLUMN)->values()->all(),
            'date' => $today,
            'config' => [
                'ready_minutes' => $settings['ready_minutes'],
                'chime' => $settings['chime'],
                'poll_seconds' => $settings['poll_seconds'],
            ],
        ];
    }

    /**
     * Called from Order::booted()'s updated() choke point (guarded there, like
     * the two shopper-push/WhatsApp listeners next to it).
     *
     * Two jobs, both additive — this must never change an order's flow:
     *  - LATE NUMBERING: an order placed before the store switched the board on
     *    (or before its type was opted in) still gets a number the moment the
     *    kitchen starts it, so it is not an invisible ghost on the board.
     *  - TIMESTAMPS: stamp preparing_at/ready_at when a status-write path left
     *    them empty, because the Ready column recycles off `ready_at`.
     */
    public static function statusChanged(Order $order): void
    {
        $storeId = (int) $order->store_id;
        if (! DailyTokenService::enabled($storeId)) {
            return;
        }

        $status = (string) $order->status;
        $onBoard = in_array($status, array_merge(self::PREPARING_STATUSES, self::READY_STATUSES), true);

        if ($onBoard) {
            DailyTokenService::assign($order);
        }

        $dirty = false;
        // Only the real 'preparing' transition stamps preparing_at — 'new' means
        // "queued", and stamping it would skew the prep-time statistics.
        if ($status === 'preparing' && ! $order->preparing_at) {
            $order->preparing_at = now();
            $dirty = true;
        }
        if (in_array($status, self::READY_STATUSES, true) && ! $order->ready_at) {
            $order->ready_at = now();
            $dirty = true;
        }
        if ($dirty) {
            $order->saveQuietly(); // quiet: never re-enter the status listeners
        }
    }
}
