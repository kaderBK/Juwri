<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use Juwri\Modules\Ordering\Data\StorefrontQuote;

/**
 * P3-06b: a headless cart the server priced ({@see StorefrontCart::price()}): the core order type, the orderable
 * lines (public ids + the client's line ids), the refused line ids with their warning code, the priced money, the
 * presented View Model Quote and its `quote_hash`.
 */
final class PricedCart
{
    /**
     * @param  list<array{line_id: string, item: string, qty: int, variant: string|null, options: list<string>, note: string|null, substitution: string|null}>  $lines
     * @param  array<string, string>  $refused  line id => `out_of_stock` | `item_unavailable`
     * @param  array<string, mixed>  $quote  View Model Quote
     * @param  string|null  $shippingProblem  P3-08: `out_of_zone` (the address is in no shipping zone) or
     *                                         `shipping_method` (no method chosen, or not offered there)
     * @param  array<string, mixed>|null  $slot  P3-09: the delivery / collection slot the cart names (DeliverySlotBook)
     * @param  string|null  $holdId  P3-09: the slot hold's public id
     * @param  string|null  $substitutionPreference  P3-09 (API-22): the order-level out-of-stock option (public id)
     * @param  array{estimate: int, cap: int}|null  $weighed  P3-09: the weighed lines' estimate and cap, minor units
     * @param  bool  $slotFull  P3-09: the slot has no room and no live hold of this cart holds it
     */
    public function __construct(
        public readonly StorefrontApiContext $ctx,
        public readonly string $orderType,
        public readonly array $lines,
        public readonly array $refused,
        public readonly ?string $coupon,
        public readonly int $tipMinor,
        public readonly StorefrontQuote $priced,
        public readonly array $quote,
        public readonly string $hash,
        public readonly ?string $shippingMethodId = null,
        public readonly ?string $shippingProblem = null,
        public readonly ?array $slot = null,
        public readonly ?string $holdId = null,
        public readonly bool $express = false,
        public readonly ?string $substitutionPreference = null,
        public readonly ?array $weighed = null,
        public readonly bool $slotFull = false,
    ) {}

    /** P3-09: does the cart hold weighed lines (an estimate up to a cap)? */
    public function isWeighed(): bool
    {
        return $this->weighed !== null;
    }

    /**
     * P3-09 (basket §9): what a card hold authorises for this cart — the quote total + what the weighed lines may add
     * (cap − estimate). Equal to the total when nothing is weighed.
     */
    public function holdAmountMinor(): int
    {
        return $this->priced->total + ($this->weighed !== null ? max(0, $this->weighed['cap'] - $this->weighed['estimate']) : 0);
    }

    /** P3-08: the chosen shipping option, as priced (null when none was chosen or it is not offered). */
    public function shipping(): ?\Juwri\Modules\Ordering\Data\ShippingOption
    {
        return $this->priced->shipping;
    }

    /** P3-08: a ship order whose chosen method is a pickup in store (no address needed, no delivery). */
    public function isPickup(): bool
    {
        return $this->priced->shipping?->method->kind === 'pickup_in_store';
    }
}
