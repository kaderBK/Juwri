<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Data;

use App\Models\Order;

/**
 * P3-02: a priced cart or a placed order, in minor units of `$currency`. Every charged amount is listed; `total` is
 * the order's own grand total (Order::computeMoney), and subtotal + delivery + fee + service + tax + tip + donation
 * - discount equals it exactly (the discount is capped so the floor at zero never breaks the sum).
 *
 * `$order` carries the lines (items with menu, variant, add-ons and the choice snapshot) — unsaved for a cart quote.
 *
 * P3-08: a `ship` cart priced with a shipping method carries it in `$shipping` (its fee is `$delivery`); a method the
 * store does not offer for the address sets `$shippingRefused`. `$shippingLabel` names the method on a placed order.
 */
final class StorefrontQuote
{
    public function __construct(
        public readonly Order $order,
        public readonly string $currency,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $delivery,
        public readonly int $fee,
        public readonly int $service,
        public readonly int $tax,
        public readonly int $tip,
        public readonly int $donation,
        public readonly int $total,
        public readonly ?string $couponCode = null,
        public readonly bool $couponApplied = false,
        public readonly ?string $couponMessage = null,
        public readonly ?ShippingOption $shipping = null,
        public readonly bool $shippingRefused = false,
        public readonly ?string $shippingLabel = null,
        /** minor units still needed for the store's "free delivery over X"; null = not applicable, 0 = reached */
        public readonly ?int $freeDeliveryRemaining = null,
        /** @var list<array{id: string, name: string, amount: int}> J32 pre-VAT levies, minor units, each its own line */
        public readonly array $levies = [],
    ) {}

    /** Sum of the charged rows — equals $total. */
    public function sumOfRows(): int
    {
        return $this->subtotal + $this->delivery + $this->fee + $this->service + $this->tax + $this->tip + $this->donation - $this->discount;
    }
}
