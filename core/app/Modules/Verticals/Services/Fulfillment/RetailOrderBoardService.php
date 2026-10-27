<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fulfillment;

use App\Models\Order;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Validation\ValidationException;

/**
 * rev/multi-vertical B5 — retail/laundry order states + board.
 *
 * A separate, additive state machine for non-food orders: new -> packing ->
 * ready -> shipped|collected -> done. Deliberately NOT the KDS/OrderStatus
 * pipeline (Juwri\Modules\Ordering\Enums\OrderStatus, KdsController, OrderBoardService) — those
 * are the food board and stay untouched; a retail/laundry order never enters
 * them (its `type` is never a food fulfillment mode, so KDS queries that key
 * on food types never see it). `orders.status` is a free-text string already
 * (F0-3), so these are just new string values on the SAME column, timestamped
 * on the SAME pattern as `ready_at`/`completed_at` (reused here) plus the two
 * new columns this WI adds (`packing_at`, `shipped_at`/`collected_at`).
 */
class RetailOrderBoardService
{
    /** @var list<string> */
    public const STATES = ['new', 'packing', 'ready', 'shipped', 'collected', 'done'];

    /**
     * The terminal handoff state for this order's fulfillment mode.
     *
     * Reads via getRawOriginal(): `orders.type` is a free-text VARCHAR since
     * F0-3's enum->string migration (a raw ALTER MODIFY, invisible to static
     * analysis of the original Schema::create migration), so PHPStan still
     * narrows the attribute to the table's ORIGINAL enum literals
     * ('on-table'/'takeaway'/'delivery') and would flag a direct === 'ship'
     * compare as always-false — a stale analysis artifact, not a real
     * constraint (FulfillmentRegistryTest::test_order_persists_a_non_food_type
     * pins that 'ship' really does round-trip).
     */
    public function terminalHandoffState(Order $order): string
    {
        $type = $order->getRawOriginal('type');
        if ($type === 'ship') {
            return 'shipped';
        }

        // P3-11 (F3): a service store (laundry) that brings the finished order back sets out with it — its `delivery`
        // orders reach `shipped` ("Out for delivery"); a `pickup_slot` order the customer collects still ends `collected`.
        // `shipped` was unreachable for laundry before.
        if ($type === 'delivery' && $this->isServiceStore($order)) {
            return 'shipped';
        }

        return 'collected';
    }

    private function isServiceStore(Order $order): bool
    {
        $store = $order->store;

        return $store !== null && app(VerticalRegistry::class)->has($store->store_type ?? null, 'service');
    }

    /** The next state after the order's current one, or null if already final. */
    public function nextState(Order $order): ?string
    {
        return match ($order->status) {
            'new' => 'packing',
            'packing' => 'ready',
            'ready' => $this->terminalHandoffState($order),
            'shipped', 'collected' => 'done',
            default => null,
        };
    }

    /** Advance the order one step, stamping the matching timestamp. */
    public function advance(Order $order): Order
    {
        $next = $this->nextState($order);
        if ($next === null) {
            throw ValidationException::withMessages(['status' => ___('This order cannot be advanced any further.')]);
        }

        $order->status = $next;
        $column = $this->timestampColumnFor($next);
        if ($column !== null) {
            $order->{$column} = now();
        }
        $order->save();

        return $order;
    }

    private function timestampColumnFor(string $state): ?string
    {
        return match ($state) {
            'packing' => 'packing_at',
            'ready' => 'ready_at',
            'shipped' => 'shipped_at',
            'collected' => 'collected_at',
            'done' => 'completed_at',
            default => null,
        };
    }
}
