<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use App\Models\Order;
use Carbon\Carbon;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Data\StorefrontQuote;

/**
 * P3-02: the ordering facts a storefront shows — which order types a store offers, the server quote of a cart,
 * the money of a placed order and the bookable slots — for the storefront view model (and, after it, the
 * headless storefront API). Implemented by the Ordering module (StorefrontOrderingService), bound in
 * OrderingServiceProvider. Every method is strictly scoped to `$store`; prices come from the SAME path an order is
 * priced with (OrderApiService line resolution + Order::computeMoney), so a quote equals the order total.
 */
interface StorefrontOrdering
{
    /**
     * Fulfilment mode keys (config/verticals.php `fulfillment`) the storefront offers now, in display order.
     * Food stores: the owner's dine-in / takeaway / delivery switches (and room service in a room context);
     * other stores: the enabled modes of their type. Empty when the plan does not allow ordering.
     *
     * @return list<string>
     */
    public function orderTypes(Store $store, bool $roomContext = false): array;

    /**
     * Price a cart. A line: `item` (item public id), `qty`, optional `variant` (variant public id, or its SKU as ItemDetail.variants shows it), `options`
     * (option / add-on public ids; a variant's own options choose the variant) and `note`. Null when a line is not
     * orderable (unknown, sold out, invalid choice, other store) — the caller shows the cart without a total.
     *
     * P3-08: `$shipping` = {method: shipping method public id, address: {country, region?, city?, postal_code?}} for a
     * `ship` order: the method as offered for that address and this items subtotal is priced into the quote (its fee
     * is the delivery row). A method the store does not offer there leaves the fee out and sets
     * StorefrontQuote::$shippingRefused.
     *
     * @param  list<array{item: string, qty?: int, variant?: string|null, options?: list<string>, note?: string|null}>  $lines
     * @param  array{method: string, address: array<string, mixed>}|null  $shipping
     * @param  int|null  $deliveryFeeMinor  P3-09: a delivery order's server-set fee (the store fee + the express fee), minor units
     */
    public function quote(Store $store, array $lines, ?string $orderType, ?Branch $branch = null, ?string $couponCode = null, int $tipMinor = 0, ?array $shipping = null, ?int $deliveryFeeMinor = null): ?StorefrontQuote;

    /**
     * P3-13: price the cart a legacy theme posts to the checkout (`send-order`): `items` decoded (objects with numeric
     * `id`, `quantity`, `variants`, `extras[].id`, `mods[{groupId, optIndex}]`), `combos` as posted, the core order
     * type, the coupon code and the tip in major units. Read the same way the checkout reads them (LegacyCartLines,
     * ComboService, tip clamp), so the quote is what that checkout charges. Null when nothing in it can be sold.
     *
     * @param  array<int, mixed>  $items
     * @param  array<int|string, mixed>  $combos
     */
    public function quoteLegacy(Store $store, array $items, array $combos, ?string $orderType, ?Branch $branch = null, ?string $couponCode = null, float $tip = 0.0): ?StorefrontQuote;

    /**
     * P3-13: price an unsaved order another module built the way its checkout writes it (the goods cart, a laundry
     * booking): coupon checked read-only against `$couponSubtotal` (the base that checkout gives its coupon; null =
     * the order's own subtotal), money from Order::computeMoney. Null for another store's or a saved order.
     */
    public function quotePreview(Store $store, Order $preview, ?string $couponCode = null, ?float $couponSubtotal = null): ?StorefrontQuote;

    /**
     * P3-03 (REVIEW, Opus: tenancy — every id resolved inside `$store`): the same cart lines in the shape the storefront checkout (`send-order`) has always taken — numeric item
     * ids, `variants`, `extras`, `mods` [{groupId, optIndex}], `order_note` — resolved strictly inside `$store`.
     * Null when a line is not this store's (never a partial list), so a cart priced by {@see quote()} is the cart
     * that is placed.
     *
     * @param  list<array{item: string, qty?: int, variant?: string|null, options?: list<string>, note?: string|null}>  $lines
     * @return list<array<string, mixed>>|null
     */
    public function legacyItems(Store $store, array $lines): ?array;

    /**
     * P3-06b (REVIEW, Opus: tenancy): the same cart lines in the OrderApiService line shape (internal ids: item_id,
     * quantity, variation, extras, options [{group_id, selected}], note) — what the headless API's `POST /orders`
     * places, so the order is exactly the cart {@see quote()} priced. Null when any line is not this store's.
     *
     * @param  list<array{item: string, qty?: int, variant?: string|null, options?: list<string>, note?: string|null}>  $lines
     * @return list<array<string, mixed>>|null
     */
    public function orderLines(Store $store, array $lines): ?array;

    /** The money of a placed order of this store (null for another store's order). */
    public function orderQuote(Store $store, Order $order): ?StorefrontQuote;

    /** Is scheduling (pre-order / booking slots) on for this store and order type? */
    public function schedulingEnabled(Store $store, ?string $orderType = null): bool;

    /**
     * Bookable slots of a day (Y-m-d, store time) for a slot order type, with their state.
     *
     * @return list<array{at: Carbon, full: bool}>
     */
    public function slots(Store $store, ?Branch $branch, string $orderType, string $date): array;

    /**
     * P3-07 (brew): the store's current prep time in minutes, from its own kitchen history (the KDS status stamps,
     * EtaEstimator) or the owner's static range; null when neither exists, so a theme hides the "ready in" chip.
     * P5-31: a branch of the store with its own prep time answers that instead.
     */
    public function prepTimeMinutes(Store $store, ?Branch $branch = null): ?int;

    /**
     * P3-07 (brew `reorder`): what a signed-in customer ordered here before, newest first, one row per distinct
     * line (item + choices), at most `$limit`. Server-filtered: only this store's lines, and only lines the storefront
     * would accept now (the same preview an order is priced with: unpublished, sold-out or scheduled-out items drop
     * out). `variant` / `options` are the last choices as public ids and `complete` says every one of them still
     * exists; when one is gone the row falls back to the bare item (`complete` false) and the theme opens the item sheet.
     *
     * @return list<array{item: string, label: string, choices_label: string, variant: string|null, options: list<string>, complete: bool}>
     */
    public function recentLines(Store $store, int $customerId, int $limit = 6): array;
}
