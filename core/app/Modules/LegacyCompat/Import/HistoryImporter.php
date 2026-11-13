<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * Money and history of the import (plan §4, §6.3, R2, Q3). Imported orders, lines, payments, billing and ledgers are
 * HISTORY: inserted once, never re-priced, re-totalled, re-taxed or run through a status machine afterwards (the
 * read-only side is {@see \Juwri\Modules\LegacyCompat\Support\LegacyHistoryGuard}).
 *
 * Prices (Q3 open, made explicit): the base source stores no price on order lines. A line keeps the price the source
 * RECORDED when the source has one (later source versions: `order_items.unit_price`, `order_item_extras.price`);
 * otherwise it takes the price of its item / variant / extra in the source menu AT MIGRATION TIME and is marked
 * `price_snapshot` — an approximation of what the customer paid, reported per row and in the counters. A line whose
 * item or extra no longer exists keeps no price (`price_unknown`), never a guessed one.
 *
 * Totals: source-recorded order totals win. Otherwise the subtotal is the sum of the lines (extras count per unit of
 * their line, as the source charged them), the tax is 0 because the source never stored it (`tax_not_recorded`), the
 * grand total is subtotal + tax + delivery + tip + donation + service charge − discount (never below 0) (`total_derived`;
 * as the source computed it: a sales-channel commission is the store's cost, never the customer's) — the delivery fee of a
 * delivery order is the store's delivery charge at migration time, stored on the order (`delivery_snapshot`); an order with an unpriced line is
 * `total_incomplete`. A recorded subtotal that differs from its recomputed lines by more than 1 minor unit is listed
 * for owner review (`subtotal_diff`), never "fixed"; a 1-minor-unit rounding difference is only counted.
 *
 * Fiscal: no fiscal document is created for an imported order (no event fires), so fiscal history starts at cut-over.
 */
final class HistoryImporter
{
    public const MARKS_TABLE = 'legacy_import_marks';

    /** Line price taken from the source menu at migration time (an approximation, Q3). */
    public const PRICE_SNAPSHOT = 'price_snapshot';

    /** Line with no price anywhere in the source (deleted item / extra): left NULL. */
    public const PRICE_UNKNOWN = 'price_unknown';

    /** Order subtotal / grand total computed at import from its lines (the source stored none). */
    public const TOTAL_DERIVED = 'total_derived';

    /** Order whose derived total misses at least one unpriced line. */
    public const TOTAL_INCOMPLETE = 'total_incomplete';

    /** The source stored no tax for the order: 0 imported. */
    public const TAX_NOT_RECORDED = 'tax_not_recorded';

    /** Delivery fee of a delivery order taken from the store's delivery charge at migration time (Q3). */
    public const DELIVERY_SNAPSHOT = 'delivery_snapshot';

    /** The store's delivery charge could not be read: the derived total carries no delivery fee. */
    public const DELIVERY_NOT_RECORDED = 'delivery_not_recorded';

    /** Store option holding the delivery charge (a bare amount in the store currency). */
    public const DELIVERY_CHARGE_OPTION = 'restaurant_delivery_charge';

    /** A source-recorded subtotal that differs from its recomputed lines. */
    public const SUBTOTAL_DIFF = 'subtotal_diff';

    /** An order (and its lines) imported in its own currency, not the store's (J58a). */
    public const OTHER_CURRENCY = 'other_currency';

    /** A weighed line priced from the per-kg menu price times the recorded weight (snapshot, Q3). */
    public const BASIS_PER_KG = 'item_per_kg';

    public const OTHER_CURRENCY_KIND = 'orders: imported in their own currency, not the store currency (J58a; excluded from the store money sums)';

    /** Target tables holding imported history: read-only after the import. */
    public const HISTORY_TABLES = [
        'orders', 'order_items', 'order_item_extras', 'loyalty_ledger_entries', 'wallet_transactions', 'transaction',
        'billing_receipts', 'shopper_wallet_ledger_entries',
    ];

    public const REVIEW_KIND = 'orders: source subtotal differs from its recomputed lines by more than 1 minor unit (owner review, not changed)';

    /** Opening balances posted for the store's gift cards (minor units), for the store totals. */
    private int $giftCardOpenings = 0;

    public function __construct(private readonly ImportContext $ctx) {}

    /**
     * TableMap transform of an order line: the recorded price, else the menu snapshot (marked), else none (marked).
     *
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function orderLine(array $out, array $row, ImportContext $ctx): array
    {
        $id = (int) $out['id'];
        [$currency, $exponent] = $ctx->orderCurrency((int) ($out['order_id'] ?? 0));
        $out['currency_code'] = $currency;
        $recorded = $row['unit_price'] ?? null;
        if ($recorded !== null && $recorded !== '') {
            $minor = $ctx->minor($recorded, $exponent);
            if ($minor !== null) {
                $out['unit_price_minor'] = $minor;

                return $out;
            }
            $ctx->report->conflict('order_items.unit_price: recorded price not numeric, menu snapshot used', 'order_items#'.$id);
        }
        $itemId = isset($row['item_id']) && is_numeric($row['item_id']) ? (int) $row['item_id'] : null;
        if ($currency !== $ctx->currency) {
            // The menu is priced in the store currency: no snapshot can stand for an order paid in another one.
            $out['unit_price_minor'] = null;
            $ctx->mark('order_items', $id, self::PRICE_UNKNOWN, ['item_id' => $itemId, 'currency' => $currency]);

            return $out;
        }
        $variation = isset($row['variation']) && is_numeric($row['variation']) ? (int) $row['variation'] : null;
        $line = $ctx->prices()->line($itemId, $variation);
        $minor = $line === null ? null : $ctx->minor($line[0]);
        if ($line === null || $minor === null) {
            $out['unit_price_minor'] = null;
            $ctx->mark('order_items', $id, self::PRICE_UNKNOWN, ['item_id' => $itemId]);

            return $out;
        }
        $basis = $line[1];
        $weight = $row['weight_kg'] ?? null;
        if ($weight !== null && $weight !== '' && is_numeric($weight) && (float) $weight > 0) {
            // A weighed line (quantity pinned to 1): the source charged the per-kg price times the weight.
            $minor = self::perKg($line[0], (string) $weight, $ctx);
            $basis = self::BASIS_PER_KG;
            if ($minor === null) {
                $out['unit_price_minor'] = null;
                $ctx->mark('order_items', $id, self::PRICE_UNKNOWN, ['item_id' => $itemId]);

                return $out;
            }
        }
        $out['unit_price_minor'] = $minor;
        $ctx->mark('order_items', $id, self::PRICE_SNAPSHOT, ['basis' => $basis, 'item_id' => $itemId] + ($line[1] === 'variant' ? ['variant_id' => $variation] : []));

        return $out;
    }

    /**
     * Per-kg price × weight in minor units of the store currency, rounded half up once (exact decimal arithmetic on
     * the cleaned source amount, plan §4), or null when either is not a number.
     */
    private static function perKg(mixed $price, string $weight, ImportContext $ctx): ?int
    {
        $perKgMinor = $ctx->minor($price);
        if ($perKgMinor === null || preg_match('/^\d+(\.\d+)?$/D', trim($weight)) !== 1) {
            return null;
        }
        // weight has at most 3 decimals in the source (decimal(8,3)): integer grams × minor per kg / 1000.
        $grams = (int) round((float) trim($weight) * 1000);
        $product = $perKgMinor * $grams;

        return intdiv($product + ($product >= 0 ? 500 : -500), 1000);
    }

    /**
     * TableMap transform of a line extra: the recorded price, else the source extra's price now (marked), else none.
     *
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function lineExtra(array $out, array $row, ImportContext $ctx): array
    {
        $id = (int) $out['id'];
        $line = $ctx->target->table('order_items')->where('id', (int) ($out['order_item_id'] ?? 0))->first(['order_id', 'item_id']);
        [$currency, $exponent] = $ctx->orderCurrency((int) ($line->order_id ?? 0));
        $out['currency_code'] = $currency;
        $recorded = $row['price'] ?? null;
        if ($recorded !== null && $recorded !== '') {
            $minor = $ctx->minor($recorded, $exponent);
            if ($minor !== null) {
                $out['price_minor'] = $minor;

                return $out;
            }
            $ctx->report->conflict('order_item_extras.price: recorded price not numeric, menu snapshot used', 'order_item_extras#'.$id);
        }
        $extraId = isset($row['extra_id']) && is_numeric($row['extra_id']) ? (int) $row['extra_id'] : null;
        // The line's item decides a linked library add-on's per-item price override (the source charged that).
        $itemId = isset($line->item_id) ? (int) $line->item_id : null;
        $price = $currency === $ctx->currency ? $ctx->prices()->extra($extraId, $itemId) : null;
        $minor = $price === null || $price[0] === null || $price[0] === '' ? null : $ctx->minor($price[0]);
        $out['price_minor'] = $minor;
        $ctx->mark('order_item_extras', $id, $minor === null ? self::PRICE_UNKNOWN : self::PRICE_SNAPSHOT, ['basis' => $price[1] ?? 'extra', 'extra_id' => $extraId]);

        return $out;
    }

    /**
     * TableMap transform of an order. An order the source recorded in ANOTHER currency than its store's is imported in
     * that currency (J58a): its amounts are converted with that currency's exponent, it is marked and reported, and it
     * stays out of the store's money sums. Never converted between currencies. A currency this install does not know is
     * refused (its amounts cannot be scaled).
     *
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|string
     */
    public static function order(array $out, array $row, ImportContext $ctx): array|string
    {
        $coupon = $out['coupon_id'] ?? null;
        if ($coupon !== null && ! LegacyContentMaps::couponOfStore((int) $coupon, $ctx)) {
            // Another owner's (or a reseller's) coupon: never linked across tenants (J29).
            $out['coupon_id'] = null;
            $ctx->report->conflict('orders.coupon_id: coupon of another owner or reseller, set NULL', 'orders#'.(int) $out['id']);
        }
        $currency = strtoupper(trim((string) ($row['currency_code'] ?? '')));
        if ($currency === '' || $currency === $ctx->currency) {
            return $out;
        }
        $exponent = $ctx->exponentOf($currency);
        if ($exponent === null) {
            return 'order currency unknown to this install (not converted)';
        }
        $id = (int) $out['id'];
        foreach (LegacyTableMaps::ORDER_MONEY as $target => $source) {
            unset($out[$target]);
            $raw = $row[$source] ?? null;
            if ($raw === null || $raw === '' || ! array_key_exists($source, $row)) {
                continue;
            }
            $minor = $ctx->minor($raw, $exponent);
            if ($minor === null) {
                $ctx->report->conflict('orders.'.$target.': amount not numeric or out of range, not imported', 'orders#'.$id);

                continue;
            }
            $out[$target] = $minor;
        }
        $out['currency_code'] = $currency;
        $ctx->orderCurrencies[$id] = [$currency, $exponent];
        $ctx->report->conflict(self::OTHER_CURRENCY_KIND, 'order#'.$id.' '.$currency);
        $ctx->mark('orders', $id, self::OTHER_CURRENCY, ['currency' => $currency, 'store_currency' => (string) $ctx->currency]);

        return $out;
    }

    /**
     * After the store's rows are in (same transaction): order totals, the ledger re-check, then the store's totals
     * for the cut-over checksum step.
     *
     * @return array<string, int|string> the store's totals
     *
     * @throws StoreBlocked when an imported loyalty account's ledger does not sum to its balance, or a gift card's
     *                      redemptions exceed its value
     */
    public function finishStore(int $storeId): array
    {
        $this->totals($storeId);
        $this->ctx->flushMarks();
        (new LedgerChecks($this->ctx))->assertImportedLoyalty($storeId);
        $this->giftCardOpenings = (new GiftCardBalances($this->ctx))->open($storeId);

        return $this->storeTotals($storeId);
    }

    private function totals(int $storeId): void
    {
        $orderIds = $this->ctx->storeIdsOf('orders');
        if ($orderIds === []) {
            return;
        }
        $target = $this->ctx->target;
        /** @var array<int, array{sum: int, unpriced: int}> $lines */
        $lines = [];
        $quantities = [];
        foreach ($target->table('order_items')->where('store_id', $storeId)->get(['id', 'order_id', 'quantity', 'unit_price_minor']) as $line) {
            $order = (int) $line->order_id;
            $quantity = (int) $line->quantity;
            $quantities[(int) $line->id] = [$order, $quantity];
            $lines[$order] ??= ['sum' => 0, 'unpriced' => 0];
            if ($line->unit_price_minor === null) {
                $lines[$order]['unpriced']++;
            } else {
                $lines[$order]['sum'] += (int) $line->unit_price_minor * $quantity;
            }
        }
        if ($target->getSchemaBuilder()->hasTable('order_item_extras')) {
            foreach ($target->table('order_item_extras')->where('store_id', $storeId)->get(['order_item_id', 'price_minor']) as $extra) {
                [$order, $quantity] = $quantities[(int) $extra->order_item_id] ?? [null, 0];
                if ($order === null) {
                    continue;
                }
                if ($extra->price_minor === null) {
                    $lines[$order]['unpriced']++;
                } else {
                    // The source charged an extra once per unit of its line.
                    $lines[$order]['sum'] += (int) $extra->price_minor * $quantity;
                }
            }
        }

        foreach (array_chunk($orderIds, 500) as $chunk) {
            $orders = $target->table('orders')->where('store_id', $storeId)->whereIn('id', $chunk)
                ->get(['id', 'type', 'currency_code', 'subtotal_minor', 'tax_total_minor', 'grand_total_minor', 'tip_amount_minor', 'discount_amount_minor', 'delivery_fee_minor', 'donation_amount_minor', 'service_charge_amount_minor']);
            foreach ($orders as $order) {
                $this->orderTotals($order, $lines[(int) $order->id] ?? ['sum' => 0, 'unpriced' => 0]);
            }
        }
    }

    /** @param array{sum: int, unpriced: int} $lines */
    private function orderTotals(\stdClass $order, array $lines): void
    {
        $id = (int) $order->id;
        $update = [];
        $derived = [];
        $subtotal = $order->subtotal_minor;
        if ($subtotal === null) {
            $subtotal = $lines['sum'];
            $update['subtotal_minor'] = $subtotal;
            $derived[] = 'subtotal';
        } elseif ($lines['unpriced'] === 0) {
            $diff = abs((int) $subtotal - $lines['sum']);
            if ($diff > 1) {
                $this->ctx->report->conflict(self::REVIEW_KIND, 'order#'.$id);
                $this->ctx->mark('orders', $id, self::SUBTOTAL_DIFF, ['source_minor' => (int) $subtotal, 'lines_minor' => $lines['sum']]);
            } elseif ($diff === 1) {
                $this->ctx->report->count('orders_subtotal_rounding_1_minor');
                $this->ctx->mark('orders', $id, self::SUBTOTAL_DIFF, ['source_minor' => (int) $subtotal, 'lines_minor' => $lines['sum']]);
            }
        }
        $tax = $order->tax_total_minor;
        if ($tax === null) {
            $tax = 0;
            $update['tax_total_minor'] = 0;
            $this->ctx->mark('orders', $id, self::TAX_NOT_RECORDED);
        }
        if ($order->grand_total_minor === null) {
            // The store's delivery charge is in the store currency: never added to an order in another currency.
            $sameCurrency = ! isset($this->ctx->orderCurrencies[$id]);
            $delivery = $order->type === 'delivery' && ($sameCurrency || $order->delivery_fee_minor !== null) ? $this->deliveryFee($id, $order->delivery_fee_minor) : 0;
            if ($order->type === 'delivery' && ! $sameCurrency && $order->delivery_fee_minor === null) {
                $this->ctx->mark('orders', $id, self::DELIVERY_NOT_RECORDED);
            }
            if ($delivery !== 0 && $order->delivery_fee_minor === null) {
                $update['delivery_fee_minor'] = $delivery;
            }
            $update['grand_total_minor'] = max(0, (int) $subtotal + (int) $tax + $delivery + (int) ($order->tip_amount_minor ?? 0)
                + (int) ($order->donation_amount_minor ?? 0) + (int) ($order->service_charge_amount_minor ?? 0)
                - (int) ($order->discount_amount_minor ?? 0));
            $derived[] = 'grand_total';
        }
        if ($derived !== []) {
            $this->ctx->mark('orders', $id, self::TOTAL_DERIVED, ['columns' => $derived]);
        }
        if ($lines['unpriced'] > 0 && in_array('subtotal', $derived, true)) {
            $this->ctx->mark('orders', $id, self::TOTAL_INCOMPLETE, ['unpriced_lines' => $lines['unpriced']]);
        }
        if ($update !== []) {
            $this->ctx->target->table('orders')->where('id', $id)->update($update);
        }
    }

    /**
     * The delivery fee a derived total adds (the source added the store's delivery charge to every delivery order):
     * the order's own fee when it has one, else the store's charge now (marked), else 0 (marked when unreadable).
     */
    private function deliveryFee(int $id, mixed $recorded): int
    {
        if ($recorded !== null) {
            return (int) $recorded;
        }
        $raw = $this->ctx->storeOptions[self::DELIVERY_CHARGE_OPTION] ?? null;
        if ($raw === null || trim((string) $raw) === '') {
            return 0;
        }
        $minor = $this->ctx->minor(trim((string) $raw));
        if ($minor === null || $minor < 0) {
            $this->ctx->mark('orders', $id, self::DELIVERY_NOT_RECORDED);

            return 0;
        }
        if ($minor > 0) {
            $this->ctx->mark('orders', $id, self::DELIVERY_SNAPSHOT, ['delivery_fee_minor' => $minor]);
        }

        return $minor;
    }

    /**
     * Per-store totals of what was written, for the cut-over checksum step (plan §6.3): order and line counts, the sum
     * of quantities, the sums of the order totals in minor units of the store currency, and the loyalty balance sum.
     *
     * @return array<string, int|string>
     */
    private function storeTotals(int $storeId): array
    {
        $target = $this->ctx->target;
        $all = $target->table('orders')->where('store_id', $storeId);
        // Money sums in the store currency only: an order kept in its own currency (J58a) is counted apart.
        $orders = (clone $all)->where('currency_code', (string) $this->ctx->currency);
        $source = $this->ctx->source;
        $sourceOrders = 0;
        $column = $source->pick('orders', LegacyTableMaps::STORE_KEYS);
        if ($column !== null) {
            $sourceOrders = $source->db->table('orders')->where($column, $storeId)->count();
        }
        $marked = fn (string $table, string $mark): int => $this->ctx->storeMarks[$table.':'.$mark] ?? 0;

        return [
            'currency_code' => (string) $this->ctx->currency,
            'items' => $target->table('items')->where('store_id', $storeId)->count(),
            'orders_source' => $sourceOrders,
            'orders' => (clone $all)->count(),
            'orders_other_currency' => (clone $all)->where('currency_code', '!=', (string) $this->ctx->currency)->count(),
            'order_items' => $target->table('order_items')->where('store_id', $storeId)->count(),
            'quantity_sum' => (int) $target->table('order_items')->where('store_id', $storeId)->sum('quantity'),
            'subtotal_minor_sum' => (int) (clone $orders)->sum('subtotal_minor'),
            'grand_total_minor_sum' => (int) (clone $orders)->sum('grand_total_minor'),
            'lines_price_snapshot' => $marked('order_items', self::PRICE_SNAPSHOT),
            'lines_price_unknown' => $marked('order_items', self::PRICE_UNKNOWN),
            'orders_total_derived' => $marked('orders', self::TOTAL_DERIVED),
            'loyalty_balance_sum' => (int) $target->table('loyalty_accounts')->where('store_id', $storeId)->sum('balance'),
            'gift_card_opening_minor_sum' => $this->giftCardOpenings,
        ];
    }
}
