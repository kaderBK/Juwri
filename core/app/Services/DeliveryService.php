<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Order;
use App\Services\Marketplace\DispatchOrchestrator;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Delivery\Services\Delivery\PodService;

/**
 * Delivery lifecycle transitions, shared by the owner API (assign/track) and the
 * driver app (self-service updates). Keeps timestamp-stamping and webhook firing
 * in one place so both entry points behave identically.
 */
class DeliveryService
{
    public const STATUSES = ['pending', 'assigned', 'picked_up', 'out_for_delivery', 'delivered', 'failed'];

    /** delivery_status → the webhook event fired on entering it (if any). */
    private const EVENTS = [
        'assigned' => 'order.driver_assigned',
        'out_for_delivery' => 'order.out_for_delivery',
        'delivered' => 'order.delivered',
        'failed' => 'order.delivery_failed',
    ];

    /** delivery_status → the order column stamped with now(). */
    private const STAMPS = [
        'assigned' => 'assigned_at',
        'picked_up' => 'picked_up_at',
        'out_for_delivery' => 'out_for_delivery_at',
        'delivered' => 'delivered_at',
    ];

    /**
     * P6-354: lifecycle order. A status may only move to an equal or higher rank;
     * delivered and failed share the terminal rank (and are final, see setStatus).
     */
    public const RANK = ['pending' => 0, 'assigned' => 1, 'picked_up' => 2, 'out_for_delivery' => 3, 'delivered' => 4, 'failed' => 4];

    /** P6-354: proof-of-delivery mode of a store (Delivery module), for callers that may not import Delivery. */
    public static function podMode(int $storeId): string
    {
        return PodService::mode($storeId);
    }

    /** P6-354: whether the order already carries the proof of delivery its store requires. */
    public static function podSatisfied(Order $order): bool
    {
        return PodService::satisfied($order);
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array((string) $status, ['delivered', 'failed'], true);
    }

    /** True when $to is a backward move from $from (e.g. out_for_delivery → picked_up). */
    public static function isBackward(?string $from, string $to): bool
    {
        return (self::RANK[$to] ?? 0) < (self::RANK[(string) $from] ?? 0);
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /**
     * Apply a delivery-status transition and fire the matching webhook.
     * Returns the refreshed order.
     */
    public static function setStatus(Order $order, string $status, ?int $driverId = null): Order
    {
        $terminal = ['delivered', 'failed'];
        $previous = (string) $order->delivery_status;

        // Terminal is final: delivered/failed are the end of the line. A stale or
        // replayed courier/driver update that tries to move an order out of a terminal
        // status — to a non-terminal status OR to the OTHER terminal — must be ignored.
        // delivered→failed would fire order.delivery_failed on an order that was really
        // delivered and paid (running refund/notify handlers, stripping the driver's
        // earnings), and failed→delivered would resurrect a dead order. reassign() and
        // unassign() both refuse terminal orders, so no legitimate caller moves out of
        // one; the only paths here are stale replays.
        //
        // P6-354: the check reads the row under a lock, not the caller's in-memory copy,
        // so a driver's "delivered" and an owner's "failed" racing each other cannot
        // both pass: the second one sees the first one's terminal status and is ignored.
        $applied = DB::transaction(function () use ($order, $status, $driverId, $terminal, &$previous) {
            if ($order->exists) {
                $current = Order::whereKey($order->getKey())->lockForUpdate()->value('delivery_status');
                $previous = (string) $current;
            }
            if (in_array($previous, $terminal, true)) {
                return false;
            }

            $order->delivery_status = $status;

            if ($driverId !== null) {
                $order->driver_id = $driverId;
            }
            if (isset(self::STAMPS[$status])) {
                $order->{self::STAMPS[$status]} = now();
            }
            $order->save();

            return true;
        });

        if (! $applied) {
            return $order->fresh() ?? $order;
        }

        // T4-2 seam: first entry into a terminal status releases marketplace
        // fleet capacity (active_load). No-op for store drivers.
        if (in_array($status, $terminal, true) && ! in_array($previous, $terminal, true)) {
            try {
                new DispatchOrchestrator()->complete($order);
            } catch (\Throwable $e) {
            }
        }

        if (isset(self::EVENTS[$status]) && $previous !== $status) {
            try {
                $ownerId = (int) Store::where('id', $order->store_id)->value('user_id');
                if ($ownerId) {
                    WebhookService::dispatch($ownerId, self::EVENTS[$status], [
                        'order_id' => $order->id,
                        'delivery_status' => $status,
                        'driver_id' => $order->driver_id,
                    ]);
                }
            } catch (\Throwable $e) {
            }
        }

        return $order->fresh();
    }
}
