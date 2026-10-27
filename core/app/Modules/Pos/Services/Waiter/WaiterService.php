<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Waiter;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\KdsService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Services\TableMergeService;

/**
 * SYSTEMS-RUN W-P1/P2: tableside operations over the EXISTING order model —
 * held items, course firing, table-state derivation, by-seat split, table
 * transfer/merge. Pricing is never forked (Order::total derives from items).
 */
class WaiterService
{
    /** Add items to an order. Held items stay out of the KDS queue until fired. */
    public function addItems(Order $order, array $items, bool $hold = false): array
    {
        $created = [];
        foreach ($items as $line) {
            $menu = Menu::where('store_id', $order->store_id)->find($line['item_id'] ?? 0);
            if (! $menu || (int) $menu->is_sold_out === 1) {
                continue; // 86'd or foreign item — skip
            }
            $created[] = OrderItem::create([
                'order_id' => $order->id,
                'item_id' => $menu->id,
                // Route through the canonical resolver so a per-branch station override
                // (branch_menu_station) wins over the menu default — matching every
                // other item-creation path (StoreController, OrderApiService, ComboService,
                // EscPosRenderer). The bare $menu->station_id sent tableside additions to
                // the wrong kitchen at a branch that remapped the item.
                'station_id' => KdsService::resolveStationForOrderItem($order->branch_id ?: null, $menu),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'variation' => 0,
                'course' => (int) ($line['course'] ?? 1),
                'seat' => isset($line['seat']) ? (int) $line['seat'] : null,
                'order_note' => $line['note'] ?? null,
                'allergy_flag' => (bool) ($line['allergy_flag'] ?? false),
                'status' => $hold ? 'held' : 'queued',
                'fired_at' => $hold ? null : now(),
            ]);
        }

        return $created;
    }

    /** Fire a course: held items of that course enter the KDS queue. Returns count. */
    public function fireCourse(Order $order, int $course): int
    {
        return OrderItem::where('order_id', $order->id)
            ->where('course', $course)
            ->where('status', 'held')
            ->update(['status' => 'queued', 'fired_at' => now()]);
    }

    /** Table states derived from open orders (+ requested checks). */
    public function tableStates(int $storeId, ?int $branchId = null): array
    {
        $orders = Order::where('store_id', $storeId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('table_number')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with('items')
            ->get();

        $states = [];
        foreach ($orders as $order) {
            $items = $order->items;
            $state = 'seated';
            if ($items->count() > 0) {
                $state = 'ordered';
                if ($items->every(fn ($i) => in_array($i->status, ['ready', 'served'], true))) {
                    $state = 'food_ready';
                }
            }
            if ($order->status === 'bill_requested' || $order->held_at) {
                $state = 'check_requested';
            }
            $states[$order->table_number] = ['table' => $order->table_number, 'order_id' => $order->id, 'state' => $state];
        }

        return array_values($states);
    }

    /** Split an order's items by seat → per-seat subtotal (menu-priced). */
    public function splitBySeat(Order $order): array
    {
        $groups = [];
        foreach ($order->items()->with('menu')->get() as $item) {
            $seat = $item->seat ?? 0;
            $price = (float) ($item->menu->price ?? 0) * (int) $item->quantity;
            $groups[$seat] = ($groups[$seat] ?? 0) + $price;
        }
        $out = [];
        foreach ($groups as $seat => $subtotal) {
            $out[] = ['seat' => $seat, 'subtotal' => round($subtotal, 2)];
        }

        return $out;
    }

    /**
     * Merge one open order into another (same restaurant). The source is CANCELLED and
     * its items re-parented onto the target, so any money that lives on the source
     * (wallet/gift-card tenders, a coupon, a manual discount, a tip) would be stranded on
     * the cancelled order and lost from the combined bill. Refuse those cases — and a
     * settled or mid-split order on either side — rather than silently losing value,
     * mirroring TableMergeService::transfer's guards.
     *
     * @throws \RuntimeException with a translated, user-facing message
     */
    public function merge(Order $from, Order $to): void
    {
        if ($from->store_id !== $to->store_id) {
            return;
        }
        if ((bool) $from->is_paid || (bool) $to->is_paid) {
            throw new \RuntimeException(___('A settled order cannot be merged.'));
        }
        if (TableMergeService::hasSplitInProgress($from) || TableMergeService::hasSplitInProgress($to)) {
            throw new \RuntimeException(___('Finish or cancel the split bill before merging these orders.'));
        }
        if ((float) ($from->wallet_applied ?? 0) > 0
            || (float) ($from->gift_card_applied ?? 0) > 0
            || $from->coupon_id !== null
            || (float) ($from->discount_amount ?? 0) > 0
            || (float) ($from->tip_amount ?? 0) > 0) {
            throw new \RuntimeException(___('Clear the payment, coupon or discount on this order before merging it.'));
        }

        OrderItem::where('order_id', $from->id)->update(['order_id' => $to->id]);
        $from->update(['status' => 'cancelled', 'message' => trim(($from->message ?? '').' [merged into #'.$to->id.']')]);
    }
}
