<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\BranchMenuStation;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Broadcasting\LiveUpdates;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Kitchen Display System logic: station routing, item-level bumping, and keeping the
 * order-level status in sync with its items (an order is "ready" only when all items are).
 */
class KdsService
{
    /**
     * Resolve which station an order item should route to.
     * Priority: per-branch override (branch_menu_station) → the menu's default station → null.
     *
     * D4 — spillover: a ticket must never land on a DISABLED station (it would sit
     * unseen on a screen nobody watches). If the resolved station is inactive, spill
     * to the first active station of the store (by position). No active station at
     * all → null (the single-queue kitchen), never a 500.
     */
    public static function resolveStationForOrderItem(?int $branchId, Menu $menu): ?int
    {
        $candidate = null;

        if ($branchId) {
            $pivot = BranchMenuStation::where('branch_id', $branchId)
                ->where('menu_id', $menu->id)->first();
            if ($pivot) {
                $candidate = (int) $pivot->station_id;
            }
        }

        if ($candidate === null && $menu->station_id) {
            $candidate = (int) $menu->station_id;
        }

        if ($candidate === null) {
            return null; // no routing configured — single queue
        }

        // Route to the resolved station only while it is active.
        $station = KitchenStation::find($candidate);
        if ($station && $station->active) {
            return $candidate;
        }

        // Dead/disabled station → spill to the first active one (never a dead screen).
        $fallback = KitchenStation::where('store_id', $menu->store_id)
            ->where('active', true)
            ->orderBy('position')->orderBy('id')
            ->value('id');

        return $fallback ? (int) $fallback : null;
    }

    /** Fire a held course for an order — stamp fired_at so its items go live at their stations. */
    public static function fireCourse(int $orderId, int $course): int
    {
        return OrderItem::where('order_id', $orderId)
            ->where('course', $course)
            ->whereNull('fired_at')
            ->update(['fired_at' => now()]);
    }

    /** Bump one item forward (queued→preparing→ready→served) and re-sync the order. */
    public static function bumpItem(OrderItem $item): void
    {
        DB::transaction(function () use ($item) {
            $item->bumpForward();
            self::syncOrderStatus($item->order_id);
        });
        self::announceTicket($item->order_id);
    }

    /**
     * Recall (un-bump) one item back a step and re-sync the order.
     *
     * P6-293: refuse the recall when the parent order is `completed` (the bill
     * is closed, payment is settled — pulling a line item back would rewrite
     * the order to `ready` / `preparing` via the sync below and undo the close).
     * `bumpItem` keeps working (the live kitchen flow is still authoritative
     * for `new`/`preparing`/`ready`/`delivered`), and `call_bill` orders are
     * kept untouched by the same guard inside syncOrderStatus() above.
     */
    public static function recallItem(OrderItem $item): void
    {
        $order = Order::find($item->order_id);
        if ($order && $order->status === 'completed') {
            return;
        }

        DB::transaction(function () use ($item) {
            $item->recallBack();
            self::syncOrderStatus($item->order_id);
        });
        self::announceTicket($item->order_id);
    }

    /** Live push (Reverb; no-op while off): this ticket changed, so open KDS screens refetch. Ids + status only. */
    private static function announceTicket(int $orderId): void
    {
        if (! LiveUpdates::enabled()) {
            return;
        }
        $order = Order::find($orderId, ['id', 'store_id', 'branch_id', 'status']);
        if ($order) {
            LiveUpdates::kdsChanged((int) $order->id, (int) $order->store_id, (string) $order->status, $order->branch_id ? (int) $order->branch_id : null);
        }
    }

    /**
     * Derive the order's status from its items' statuses:
     *  - every item served    → delivered
     *  - every item ready/served (all ready) → ready  (expo can assemble)
     *  - any item preparing/ready → preparing
     *  - otherwise            → new
     * Only advances orders still in the live kitchen flow; never touches paid/cancelled flags.
     *
     * P6-293: the kitchen sync used to skip only `cancelled` / `unpaid`, so a
     * recalled item on a `completed` order pulled it back to `ready`/`preparing`
     * and a `call_bill` table reverted to `new` (busy). Sync ONLY from orders
     * still in the live kitchen flow: new/preparing/ready/delivered. Anything
     * else (completed, call_bill, cancelled, unpaid, pending_approval) is left
     * alone — those states belong to the front-of-house / POS, and the KDS has
     * no business rewriting them.
     */
    public static function syncOrderStatus(int $orderId): void
    {
        $order = Order::with('items')->find($orderId);
        if (! $order) {
            return;
        }
        /* PARITY-RUN T3: an order held for staff approval has not entered the KOT
           flow, so its items are all still 'queued' — deriving from them would
           rewrite the status to 'new' and quietly send an unapproved order to the
           kitchen. Approval is the only thing that may move it out of here. */
        if ($order->status === Order::STATUS_PENDING_APPROVAL) {
            return;
        }
        /* P6-293: keep the KDS's derivation authoritative only for the live kitchen
           statuses. `completed` is closed (paid/done); `call_bill` is a
           front-of-house signal that the table asked for the check; `cancelled`
           and `unpaid` were already terminal under the previous guard. None of
           them are owned by the kitchen, so a recall / sync here MUST NOT move
           the order off them. */
        // p5r int: `pending` (a just-paid order, e.g. a phone pay-link order once paid) is the start of the live flow.
        if (! in_array($order->status, ['pending', 'new', 'preparing', 'ready', 'delivered'], true)) {
            return;
        }

        $items = $order->items;
        if ($items->isEmpty()) {
            return;
        }

        $statuses = $items->pluck('status')->map(fn ($s) => $s ?: 'queued');

        if ($statuses->every(fn ($s) => $s === 'served')) {
            $new = 'delivered';
        } elseif ($statuses->every(fn ($s) => in_array($s, ['ready', 'served'], true))) {
            $new = 'ready';
        } elseif ($statuses->contains(fn ($s) => in_array($s, ['preparing', 'ready', 'served'], true))) {
            $new = 'preparing';
        } else {
            $new = 'new';
        }

        if ($order->status === $new) {
            return;
        }

        $order->status = $new;
        if ($new === 'preparing' && ! $order->preparing_at) {
            $order->preparing_at = now();
        } elseif ($new === 'ready' && ! $order->ready_at) {
            $order->ready_at = now();
        } elseif ($new === 'delivered' && ! $order->completed_at) {
            $order->completed_at = now();
        }
        $order->save();

        // P6-292: route the customer-facing status notification through the
        // shared service so the advanced-mode item-driven sync tells the diner
        // the order is ready. The item-driven sync only notifies on the states
        // the diner cares about (`ready` / `delivered`): the derived
        // `preparing` fires as soon as the FIRST item starts, while other items
        // are still queued, so it is deliberately silent here (the basic-mode
        // whole-order bump notifies `preparing` itself). The notifier dedupes
        // by (order, status), so bumping the last item to ready cannot fire
        // alongside the same-ready fired by another caller on the same order.
        if (in_array($new, ['ready', 'delivered'], true)) {
            OrderStatusNotifier::notify($order, $new);
        }
    }
}
