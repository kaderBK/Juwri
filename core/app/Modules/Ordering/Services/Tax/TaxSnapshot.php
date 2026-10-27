<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Tax;

use App\Models\Order;

/**
 * D7 — freeze an order's applied taxes at create time so no later rate change can
 * rewrite the tax printed on a settled order.
 *
 * The stored shape is a JSON array of `[{label, rate, amount}]` on
 * `orders.tax_breakdown`. `orders` carries no cast for that column (the Order
 * model belongs to another lane), so this service owns the JSON encode/decode —
 * every writer goes through {@see stamp()} and every reader through {@see lines()}.
 *
 * Contract (SPEC §5): `stamp(Order): void` is idempotent and null-safe. Calling it
 * twice never re-stamps (the first snapshot wins); a legacy order (null column)
 * keeps recomputing from live rates via {@see lines()}, so pre-migration behaviour
 * is unchanged. REOPEN is explicitly out of scope (SPEC §3.5).
 */
class TaxSnapshot
{
    /**
     * Freeze the order's tax breakdown, once. No-op if the order is not persisted
     * or already stamped.
     */
    public static function stamp(Order $order): void
    {
        if (! $order->exists || ! $order->getKey()) {
            return; // null-safe: nothing to stamp
        }
        if ($order->getAttribute('tax_breakdown') !== null) {
            return; // idempotent: the first snapshot wins
        }

        $order->setAttribute('tax_breakdown', json_encode(self::compute($order), JSON_UNESCAPED_UNICODE));
        $order->save();
    }

    /**
     * The tax lines to render for an order: the frozen snapshot when present, else a
     * live recompute (the legacy path for pre-migration orders). Always the same
     * `[{label, rate, amount}]` shape.
     *
     * @return array<int, array{label: string, rate: float, amount: float}>
     */
    public static function lines(Order $order): array
    {
        $raw = $order->getAttribute('tax_breakdown');
        if ($raw !== null) {
            $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return self::compute($order); // legacy: recompute from live rates
    }

    /** Total tax across the snapshot lines — a convenience for render surfaces. */
    public static function total(Order $order): float
    {
        return array_sum(array_map(static fn ($l) => (float) $l['amount'], self::lines($order)));
    }

    /**
     * Compute the applied tax lines from the store's tax config. The platform uses
     * a single tax (percentage-of-subtotal or a fixed amount), so this yields at
     * most one line; the array shape leaves room for multi-tax stores later.
     *
     * @return array<int, array{label: string, rate: float, amount: float}>
     */
    private static function compute(Order $order): array
    {
        $postOptions = store_options((int) $order->store_id);
        $charge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
        if ($charge <= 0) {
            return [];
        }

        $money = $order->computeMoney();
        // P6-99b: on the VAT engine the order's own per-line VAT, not the legacy formula
        $amount = TaxEngine::applies($order, $postOptions)
            ? (float) $money['tax']
            : Order::computeTax($order->taxBase((float) $money['subtotal']), $postOptions, $order->currencyCode());
        if ($amount <= 0) {
            return [];
        }

        // A fixed tax has no meaningful percentage rate — expose 0 there so the
        // display shows just the label, not a misleading "(15%)".
        $type = (string) (@$postOptions->restaurant_tax_type ?: 'percentage');

        return [[
            'label' => ___('Tax'),
            'rate' => $type === 'fixed' ? 0.0 : $charge,
            'amount' => (float) $amount,
        ]];
    }
}
