<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Storefront;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CouponService;
use App\Services\Entitlements;
use App\Services\OrderApiService;
use App\Services\Storefront\LegacyCartLines;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Menu\Contracts\QuoteItems;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\ShippingOption;
use Juwri\Modules\Ordering\Data\StorefrontQuote;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Ordering\Services\Shipping\ShippingQuotes;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;

/**
 * P3-02: the Ordering module's answer to {@see StorefrontOrdering}. A cart quote is the order the storefront
 * would place, unsaved: lines resolved by OrderApiService::previewOrder (guest rules: sold-out, schedule,
 * unpublished items and invalid choices refused), the coupon checked read-only by CouponService::resolve (the
 * binding claim stays at placement), and the money from Order::computeMoney — the derivation every order total
 * reads. Public ids in, internal ids never out.
 */
final class StorefrontOrderingService implements StorefrontOrdering
{
    /** Food-store order types and the store switch that turns each on. */
    private const FOOD_SWITCHES = [
        'on-table' => 'restaurant_on_table_order',
        'takeaway' => 'restaurant_takeaway_order',
        'delivery' => 'restaurant_delivery_order',
    ];

    public function __construct(
        private readonly FulfillmentSettingsService $fulfillment,
        private readonly SchedulingService $scheduling,
        private readonly ShippingQuotes $shipping,
        private readonly RetailCatalog $retail,
    ) {}

    public function orderTypes(Store $store, bool $roomContext = false): array
    {
        if (! Entitlements::has($store->user, 'allow_ordering')) {
            return [];
        }

        if (! $store->isFoodVertical()) {
            return $this->fulfillment->offeredModes($store);
        }

        // Pricing follow-ups: delivery only when the plan includes it (a plan without the key keeps it).
        $delivery = PlanGate::storeAllows($store, PlanGate::DELIVERY);
        $types = [];
        foreach (self::FOOD_SWITCHES as $type => $setting) {
            if ($type === 'delivery' && ! $delivery) {
                continue;
            }
            if (StoreSettings::bool((int) $store->id, $setting)) {
                $types[] = $type;
            }
        }
        if ($roomContext && app(RoomCharge::class)->enabled((int) $store->id)) {
            $types[] = 'room_service';
        }

        return $types;
    }

    public function quote(Store $store, array $lines, ?string $orderType, ?Branch $branch = null, ?string $couponCode = null, int $tipMinor = 0, ?array $shipping = null, ?int $deliveryFeeMinor = null): ?StorefrontQuote
    {
        // P4-16b: the cart's items are loaded once for the whole quote (QuoteItems; nested scopes are one scope).
        return app(QuoteItems::class)->during(function () use ($store, $lines, $orderType, $branch, $couponCode, $tipMinor, $shipping, $deliveryFeeMinor) {
            app(QuoteItems::class)->prime((int) $store->id, array_column($lines, 'item'));

            return $this->quoteLines($store, $lines, $orderType, $branch, $couponCode, $tipMinor, $shipping, $deliveryFeeMinor);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{method: string, address: array<string, mixed>}|null  $shipping
     */
    private function quoteLines(Store $store, array $lines, ?string $orderType, ?Branch $branch, ?string $couponCode, int $tipMinor, ?array $shipping, ?int $deliveryFeeMinor): ?StorefrontQuote
    {
        $apiLines = [];
        foreach ($lines as $line) {
            $resolved = $this->line($store, $line);
            if ($resolved === null) {
                return null;
            }
            $apiLines[] = $resolved;
        }
        if ($apiLines === []) {
            return null;
        }

        $branchId = $branch !== null && (int) $branch->store_id === (int) $store->id ? (int) $branch->id : null;
        $order = OrderApiService::previewOrder((int) $store->id, $apiLines, $orderType, $branchId);
        if ($order === null) {
            return null;
        }

        if ($orderType === 'delivery' && $deliveryFeeMinor !== null) {
            // P3-09: the express fee rides on the order's delivery charge (Order::deliveryCharge), set by the server
            $order->delivery_fee = Money::majorFromMinor(max(0, $deliveryFeeMinor), $order->minorMoneyCurrency());
        }

        return $this->settle($store, $order, $orderType, $branchId, $couponCode, Money::majorFromMinor(max(0, $tipMinor), $order->minorMoneyCurrency()), null, $shipping);
    }

    public function quoteLegacy(Store $store, array $items, array $combos, ?string $orderType, ?Branch $branch = null, ?string $couponCode = null, float $tip = 0.0): ?StorefrontQuote
    {
        // P4-16b (load test: the legacy cart quote re-read each item three times per line): items loaded once.
        return app(QuoteItems::class)->during(function () use ($store, $items, $combos, $orderType, $branch, $couponCode, $tip) {
            app(QuoteItems::class)->primeIds((int) $store->id, array_values(array_map(fn ($i) => is_object($i) ? ($i->id ?? 0) : 0, $items)));

            return $this->quoteLegacyItems($store, $items, $combos, $orderType, $branch, $couponCode, $tip);
        });
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<int|string, mixed>  $combos
     */
    private function quoteLegacyItems(Store $store, array $items, array $combos, ?string $orderType, ?Branch $branch, ?string $couponCode, float $tip): ?StorefrontQuote
    {
        $branchId = $branch !== null && (int) $branch->store_id === (int) $store->id ? (int) $branch->id : null;
        $items = array_values(array_filter($items, 'is_object'));
        // The checkout refuses the whole cart for a line that is not this store's (or not sold at the branch) and for
        // a quantity outside 1..999; it skips a line it cannot sell now (sold out, out of its schedule) and places
        // the rest. The quote does the same.
        foreach ($items as $i) {
            $menu = app(QuoteItems::class)->byId((int) $store->id, (int) ($i->id ?? 0)); // fresh outside a quote
            $qty = $i->quantity ?? 1;
            if ($menu === null || ($branchId !== null && ! $menu->availableAtBranch($branchId))
                || ! is_numeric($qty) || (int) $qty != $qty || (int) $qty < 1 || (int) $qty > 999) {
                return null;
            }
        }
        $apiLines = LegacyCartLines::lines($items, $store, $orderType);
        if ($apiLines === [] && $combos === []) {
            return null;
        }

        $order = OrderApiService::previewOrder((int) $store->id, $apiLines, $orderType, $branchId, $combos);
        if ($order === null || $order->items->isEmpty()) {
            return null;
        }

        // The checkout clamps the posted tip the same way (StoreController@submitOrder, K4).
        $tip = max(0.0, min($tip, (float) config('settings.max_tip_amount', 100000)));

        return $this->settle($store, $order, $orderType, $branchId, $couponCode, $tip);
    }

    public function quotePreview(Store $store, Order $preview, ?string $couponCode = null, ?float $couponSubtotal = null): ?StorefrontQuote
    {
        if ((int) $preview->store_id !== (int) $store->id || $preview->exists) {
            return null;
        }

        return $this->settle($store, $preview, $preview->type, $preview->branch_id ? (int) $preview->branch_id : null, $couponCode, (float) ($preview->tip_amount ?? 0), $couponSubtotal);
    }

    /**
     * Coupon (read-only check: CouponService::resolve with the context the binding settlement uses — OrderPricing),
     * tip and the money of an unsaved priced order. `$couponSubtotal`: the base a checkout passes its coupon when it
     * is not the order's own subtotal (the goods checkout passes its cart total). `$shipping`: the chosen ship method
     * and address (P3-08, ship orders only).
     *
     * @param  array{method: string, address: array<string, mixed>}|null  $shipping
     */
    private function settle(Store $store, Order $order, ?string $orderType, ?int $branchId, ?string $couponCode, float $tip, ?float $couponSubtotal = null, ?array $shipping = null): StorefrontQuote
    {
        $order->forceFill(['user_id' => $store->user_id]);
        $currency = $order->minorMoneyCurrency();

        $code = trim((string) $couponCode);
        $applied = false;
        $message = null;
        $discount = 0.0;
        if ($code !== '') {
            $menus = $order->items->map(fn ($item) => $item->menu)->filter()->all();
            if (app(SalesRestrictions::class)->promotionsBlocked($store, $menus)) {
                $message = ___('Coupons cannot be used on medicines in this market.');
            } else {
                $result = (new CouponService)->resolve($code, [
                    'surface' => 'food_order',
                    'owner_id' => $store->user_id,
                    'subtotal' => $couponSubtotal ?? (float) $order->computeMoney()['subtotal'],
                    'customer_id' => auth('customer')->id(),
                    'branch_id' => $branchId,
                    'order' => $order,
                    'currency' => $currency,
                ]);
                $applied = ! empty($result['ok']);
                $message = (string) $result['message'];
                if ($applied) {
                    $discount = (float) $result['discount'];
                    if (! empty($result['free_delivery']) && $orderType === 'delivery') {
                        $order->delivery_fee = 0;
                    }
                }
            }
        }

        $order->discount_amount = $discount;
        $order->tip_amount = $tip;

        // P3-08: a ship order is priced with the shipping method the shopper chose, as offered for the address and
        // this items subtotal (before discounts) — the fee is the order's delivery charge (Order::deliveryCharge).
        $option = null;
        $refused = false;
        if ($orderType === 'ship' && $shipping !== null) {
            $subtotalMinor = Money::minorFromMajor((float) $order->computeMoney()['subtotal'], $currency);
            $option = $this->shipping->find($store, (string) $shipping['method'], (array) $shipping['address'], $subtotalMinor);
            if ($option === null || $option->currency !== $currency) {
                $option = null;
                $refused = true;
            } else {
                $order->delivery_fee = Money::majorFromMinor($option->feeMinor, $currency);
            }
        }

        return $this->priced($order, $currency, $code !== '' ? $code : null, $applied, $message, $option, $refused);
    }

    public function orderQuote(Store $store, Order $order): ?StorefrontQuote
    {
        if ((int) $order->store_id !== (int) $store->id) {
            return null;
        }
        $code = $order->coupon_id ? Coupon::query()->whereKey($order->coupon_id)->value('code') : null;
        $label = $order->shipping_method_id !== null
            ? ShippingMethod::query()->where('store_id', $store->id)->whereKey($order->shipping_method_id)->first()?->name
            : null;

        return $this->priced($order, $order->minorMoneyCurrency(), is_string($code) ? $code : null, $code !== null, null, null, false, is_string($label) ? $label : null);
    }

    public function schedulingEnabled(Store $store, ?string $orderType = null): bool
    {
        return $this->scheduling->enabled((int) $store->id, $orderType);
    }

    public function slots(Store $store, ?Branch $branch, string $orderType, string $date): array
    {
        $branch = $branch !== null && (int) $branch->store_id === (int) $store->id ? $branch : null;

        return array_map(
            fn (array $slot) => ['at' => $slot['at'], 'full' => (bool) $slot['full']],
            $this->scheduling->slotsFor((int) $store->id, $branch, $orderType, $date)
        );
    }

    public function prepTimeMinutes(Store $store, ?Branch $branch = null): ?int
    {
        $own = $branch !== null && (int) $branch->store_id === (int) $store->id ? $branch->prepMinutesOverride() : null;
        if ($own !== null) {
            return $own > 0 ? $own : null; // P5-31: the branch's own prep time (0 hides the chip)
        }
        $eta = EtaEstimator::estimate($store);

        return $eta !== null && $eta->p50 > 0 ? $eta->p50 : null;
    }

    public function recentLines(Store $store, int $customerId, int $limit = 6): array
    {
        if ($customerId <= 0 || $limit <= 0) {
            return [];
        }

        $orders = Order::query()
            ->where('store_id', $store->id)
            ->where('customer_id', $customerId)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra'])
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $seen = [];
        $out = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $menu = $item->menu;
                // a combo line has no menu row; a row of another store (a repaired import) is never offered
                if ($item->isCombo() || $menu === null || (int) $menu->store_id !== (int) $store->id || $menu->public_id === null) {
                    continue;
                }
                $candidate = $this->recentCandidate($store, $item);
                $key = $candidate['item'].'|'.($candidate['variant'] ?? '').'|'.implode(',', $candidate['options']).'|'.($candidate['complete'] ? '1' : '0');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $line = $this->recentOrderable($store, $candidate);
                if ($line === null) {
                    continue;
                }
                $out[] = $line;
                if (count($out) >= $limit) {
                    break 2;
                }
            }
        }

        return $out;
    }

    /**
     * One past order line as a storefront line (public ids). `complete` is false when a choice it carried no longer
     * exists (the variant, an option or an add-on was removed or deactivated).
     *
     * @return array{item: string, label: string, choices_label: string, variant: string|null, options: list<string>, complete: bool}
     */
    private function recentCandidate(Store $store, OrderItem $item): array
    {
        $menu = $item->menu;
        $complete = true;
        $labels = [];

        $variant = null;
        if (! empty($item->variation)) {
            $row = $item->variant;
            if ($row === null || ! (int) $row->active || $row->public_id === null || (int) $row->menu_id !== (int) $menu->id) {
                $complete = false;
            } else {
                $variant = (string) $row->public_id;
                $label = trim((string) $row->axes_label);
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        $options = [];
        $groups = $menu->resolved_variant_options->keyBy('id');
        foreach ((array) $item->options as $snapshot) {
            if (! empty($snapshot['combo'])) {
                continue;
            }
            $group = $groups->get((int) ($snapshot['group_id'] ?? 0));
            $groupLabels = $group !== null ? array_values((array) json_decode((string) json_encode($group->options), true)) : [];
            foreach ((array) ($snapshot['selected'] ?? []) as $selected) {
                $name = (string) ($selected['option'] ?? '');
                $index = $group !== null ? array_search($name, $groupLabels, true) : false;
                $modifier = $index !== false ? $group->modifiers->values()->get($index) : null;
                if ($modifier === null || $modifier->public_id === null) {
                    $complete = false;

                    continue;
                }
                $options[] = (string) $modifier->public_id;
                $labels[] = $name;
            }
        }

        $activeExtras = $menu->resolved_extras->keyBy('id');
        foreach ($item->itemExtras as $itemExtra) {
            $extra = $activeExtras->get((int) $itemExtra->extra_id);
            if ($extra === null || $extra->public_id === null) {
                $complete = false;

                continue;
            }
            $options[] = (string) $extra->public_id;
            $labels[] = (string) $extra->title;
        }

        return [
            'item' => (string) $menu->public_id,
            'label' => (string) $menu->name,
            'choices_label' => implode(' · ', array_filter($labels, fn (string $l) => $l !== '')),
            'variant' => $variant,
            'options' => $options,
            'complete' => $complete,
        ];
    }

    /**
     * The row if the storefront would accept it now: with its last choices when all still exist and the cart line prices (the
     * same preview an order is priced with), else — a choice is gone — as the bare item (choices dropped, `complete` false)
     * for the theme to open the item sheet on, as long as the item itself is on sale; null when it is not.
     *
     * @param  array{item: string, label: string, choices_label: string, variant: string|null, options: list<string>, complete: bool}  $candidate
     * @return array{item: string, label: string, choices_label: string, variant: string|null, options: list<string>, complete: bool}|null
     */
    private function recentOrderable(Store $store, array $candidate): ?array
    {
        if ($candidate['complete'] && $this->orderable($store, ['item' => $candidate['item'], 'qty' => 1, 'variant' => $candidate['variant'], 'options' => $candidate['options']])) {
            return $candidate;
        }

        $menu = Menu::query()->where('store_id', $store->id)->where('public_id', $candidate['item'])->first();
        if ($menu === null || (string) $menu->active !== '1' || (int) ($menu->is_sold_out ?? 0) === 1) {
            return null;
        }

        return ['variant' => null, 'options' => [], 'choices_label' => '', 'complete' => false] + $candidate;
    }

    /** @param  array<string, mixed>  $line */
    private function orderable(Store $store, array $line): bool
    {
        $resolved = $this->line($store, $line);

        return $resolved !== null && OrderApiService::previewOrder((int) $store->id, [$resolved]) !== null;
    }

    /**
     * One storefront cart line → the OrderApiService line shape (internal ids), or null when anything in it is
     * not this item's (another store's item, variant, option or add-on).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>|null
     */
    private function line(Store $store, array $line): ?array
    {
        $r = $this->resolve($store, $line);
        if ($r === null) {
            return null;
        }

        $out = ['item_id' => $r['item'], 'quantity' => $r['qty'], 'extras' => $r['extras'], 'options' => []];
        if ($r['note'] !== '') {
            $out['note'] = $r['note'];
        }
        if ($r['variation'] !== null) {
            $out['variation'] = $r['variation'];
        }
        foreach ($r['picks'] as $groupId => $picked) {
            $out['options'][] = ['group_id' => $groupId, 'selected' => array_column($picked, 'label')];
        }

        return $out;
    }

    public function orderLines(Store $store, array $lines): ?array
    {
        // P6-148: the cart's items are read once for all its lines (a fresh scope for this order, closed when done).
        return app(QuoteItems::class)->during(function () use ($store, $lines) {
            app(QuoteItems::class)->prime((int) $store->id, array_column($lines, 'item'));

            return $this->orderLinesNow($store, $lines);
        });
    }

    /**
     * @param  list<array{item: string, qty?: int, variant?: string|null, options?: list<string>, note?: string|null}>  $lines
     * @return list<array<string, mixed>>|null
     */
    private function orderLinesNow(Store $store, array $lines): ?array
    {
        $out = [];
        foreach ($lines as $line) {
            $resolved = $this->line($store, $line);
            if ($resolved === null) {
                return null;
            }
            $out[] = $resolved;
        }

        return $out === [] ? null : $out;
    }

    public function legacyItems(Store $store, array $lines): ?array
    {
        $items = [];
        foreach ($lines as $line) {
            $r = $this->resolve($store, $line);
            if ($r === null) {
                return null;
            }
            $mods = [];
            foreach ($r['picks'] as $groupId => $picked) {
                foreach ($picked as $pick) {
                    $mods[] = ['groupId' => $groupId, 'optIndex' => $pick['index']];
                }
            }
            $items[] = array_filter([
                'id' => $r['item'],
                'quantity' => $r['qty'],
                'variants' => $r['variation'],
                'extras' => $r['extras'] !== [] ? array_map(fn (int $id) => ['id' => $id], $r['extras']) : null, // the checkout reads `extras[].id`
                'mods' => $mods ?: null,
                'order_note' => $r['note'] !== '' ? $r['note'] : null,
            ], fn ($v) => $v !== null);
        }

        return $items === [] ? null : $items;
    }

    /**
     * The one place a storefront line (public ids) is resolved to this store's rows: item, variant, option picks
     * (group id => [{index, label}]) and add-ons. Null when anything in it belongs to another item or store.
     *
     * @param  array<string, mixed>  $line
     * @return array{item: int, qty: int, note: string, variation: int|null, extras: list<int>, picks: array<int, list<array{index: int, label: string}>>}|null
     */
    private function resolve(Store $store, array $line): ?array
    {
        $item = app(QuoteItems::class)->byPublicId((int) $store->id, (string) ($line['item'] ?? ''));
        if ($item === null) {
            return null;
        }

        $out = [
            'item' => (int) $item->id,
            'qty' => max(1, (int) ($line['qty'] ?? 1)),
            'note' => self::cleanNote($line['note'] ?? null),
            'variation' => null,
            'extras' => [],
            'picks' => [],
        ];
        if (! empty($line['variant'])) {
            // The view model names a variant by its public id; ItemDetail.variants carry the owner's SKU (the kit
            // schema's key), so a cart may send either — always within this item.
            $ref = (string) $line['variant'];
            $variant = (Str::isUuid($ref) ? MenuVariant::query()->where('menu_id', $item->id)->where('public_id', $ref)->first() : null)
                ?? MenuVariant::query()->where('menu_id', $item->id)->where('sku', $ref)->orderBy('id')->first();
            if ($variant === null) {
                return null;
            }
            $out['variation'] = (int) $variant->id;
        } else {
            $variant = null;
        }

        $groups = $item->resolved_variant_options;
        $extras = $item->resolved_extras;
        // P3-08: a matrix cell answers its own axes (Colour: Sand, Size: M) — the order line is the cell (its price,
        // its name), exactly as the storefront checkout records a priced combination, so the axis options are never
        // priced again on top. An axis option the client sends with the cell must be the cell's. A cart that picks
        // the axis options instead of naming the cell gets the cell of those picks (below).
        $cell = $variant?->cell();
        foreach ((array) ($line['options'] ?? []) as $publicId) {
            $publicId = (string) $publicId;
            $matched = false;
            foreach ($groups as $group) {
                $index = $group->modifiers->values()->search(fn ($m) => (string) $m->public_id === $publicId);
                if ($index !== false) {
                    if ($cell !== null && array_key_exists((int) $group->id, $cell)) {
                        if ((int) $cell[(int) $group->id] !== (int) $index) {
                            return null; // an option of the cell's axis that is not the cell's
                        }
                        $matched = true;
                        break; // the cell answers this axis
                    }
                    $labels = array_values((array) json_decode((string) json_encode($group->options), true));
                    $out['picks'][(int) $group->id][] = ['index' => (int) $index, 'label' => (string) ($labels[$index] ?? '')];
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $extra = $extras->first(fn ($e) => (string) $e->public_id === $publicId);
                if ($extra === null) {
                    return null;
                }
                $out['extras'][] = (int) $extra->id;
            }
        }

        if ($out['variation'] === null) {
            $axes = $this->retail->axes($item);
            if ($axes !== []) {
                $picked = [];
                foreach ($axes as $groupId) {
                    if (count($out['picks'][$groupId] ?? []) === 1) {
                        $picked[$groupId] = (int) $out['picks'][$groupId][0]['index'];
                    }
                }
                if (count($picked) === count($axes)) {
                    $found = $this->retail->variantForPicks($item, $picked);
                    if ($found === null) {
                        return null; // a combination the store does not sell
                    }
                    $out['variation'] = (int) $found->id;
                    foreach ($axes as $groupId) {
                        unset($out['picks'][$groupId]); // the cell answers its axes
                    }
                }
            }
        }

        return $out;
    }

    /**
     * A per-line note ("Name on the cup") is free text from a guest that is printed on the kitchen ticket and the receipt
     * (thermal printers read control bytes as commands) and shown on the KDS: control and invisible formatting characters
     * and line breaks become a space, and it is cut at 255 characters. Output stays escaped where it is rendered.
     */
    public static function cleanNote(mixed $note): string
    {
        if (! is_string($note) || $note === '') {
            return '';
        }
        $note = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $note);

        return trim(mb_substr(trim($note), 0, 255));
    }

    private function priced(Order $order, string $currency, ?string $code, bool $applied, ?string $message, ?ShippingOption $shipping = null, bool $shippingRefused = false, ?string $shippingLabel = null): StorefrontQuote
    {
        $money = $order->computeMoney();
        $minor = fn (float|int|string|null $major): int => Money::minorFromMajor((float) ($major ?? 0), $currency);

        $subtotal = $minor($money['subtotal']);
        $tax = $minor($money['tax']);
        $delivery = $minor($order->deliveryCharge());
        $fee = $minor(Order::trayCharge($order->type, (float) $money['subtotal'], $order->store_id, $currency));
        $service = $minor($order->service_charge_amount);
        $tip = $minor($order->tip_amount);
        $donation = $minor($order->donation_amount);
        // P6-99b: a tax-inclusive store's prices already hold the VAT (the quote still shows it as `tax`)
        $taxOnTop = (TaxEngine::compute($order)['inclusive'] ?? false) ? 0 : $tax;
        $before = $subtotal + $delivery + $fee + $service + $taxOnTop + $tip + $donation;
        // computeMoney floors the total at zero: a discount larger than the bill is shown at what it takes off.
        $discount = min($minor($order->discount_amount), $before);

        return new StorefrontQuote(
            order: $order,
            currency: $currency,
            subtotal: $subtotal,
            discount: $discount,
            delivery: $delivery,
            fee: $fee,
            service: $service,
            tax: $tax,
            tip: $tip,
            donation: $donation,
            total: $minor($money['grand']),
            couponCode: $code,
            couponApplied: $applied,
            couponMessage: $message,
            shipping: $shipping,
            shippingRefused: $shippingRefused,
            shippingLabel: $shippingLabel ?? ($shipping !== null ? (string) $shipping->method->name : null),
            freeDeliveryRemaining: ($left = $order->freeDeliveryRemaining()) !== null ? $minor($left) : null,
            levies: array_map(fn (array $l): array => ['id' => $l['id'], 'name' => $l['name'], 'amount' => $l['amount']], TaxEngine::levyLines($order)),
        );
    }
}
