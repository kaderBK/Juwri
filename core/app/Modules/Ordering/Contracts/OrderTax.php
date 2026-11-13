<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use App\Models\Order;

/**
 * P6-126: what other modules (Accounting's invoice export) may ask of the order's VAT engine result, without reaching
 * into Ordering's tax internals.
 */
interface OrderTax
{
    /** True when the VAT engine prices this order and its item prices include the VAT. */
    public function pricesIncludeTax(Order $order): bool;

    /**
     * J32: the order's levy lines (minor units of the order currency), for invoices and the journal.
     *
     * @return list<array{id: string, name: string, amount: int, tax: int, decimals: int}>
     */
    public function levyLines(Order $order): array;

    /** J32: Σ levies (without their VAT) in major units of the order currency. */
    public function levyTotal(Order $order): float;

    /** P6-259: the store's own percentage VAT rate; null for a fixed-amount tax (no rate). */
    public function storeRate(int $storeId): ?float;

    /** P6-259: true when the store prices orders with the per-line VAT engine. */
    public function perLineEnabled(int $storeId): bool;

    /** P6-259: true when the store's menu prices include VAT (per-line engine). */
    public function pricesIncludeVat(int $storeId): bool;

    /**
     * P6-259: the orders' net sales and VAT grouped by rate, in minor units of $currency.
     *
     * @param  iterable<Order>  $orders
     * @return list<array{rate: float|null, category: string, net: int, vat: int}> highest rate first
     */
    public function byRateMinor(iterable $orders, int $storeId, string $currency): array;
}
