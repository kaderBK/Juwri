<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Http\Request;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Contracts\ItemPricing;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\StorefrontQuote;
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Verticals\Contracts\GoodsCartQuote;

/**
 * P3-13 (THEME-CONTRACT §5.2): the server quote of a store's goods cart — what the goods checkout
 * (GoodsOrderService::checkoutOrReplay) or the laundry booking (ServiceBookingService::book) would charge for it now,
 * as an unsaved order priced by Order::computeMoney. The lines are written exactly as those checkouts write them:
 * the scheduled-price snapshot (ItemPricing::snapshot), add-ons as OrderItemExtra rows at their snapshotted price,
 * and — for a booking — a weighed line at price per kg × kg. The coupon is checked read-only on the base each
 * checkout gives it (the goods checkout: the cart total; the booking: the weight-corrected order subtotal).
 * Nothing is written. Used by the premium goods themes (carely, aurelia, freshly) to show the total the order gets.
 */
final class GoodsQuoteService implements GoodsCartQuote
{
    public function __construct(
        private readonly GoodsCartService $cart,
        private readonly FulfillmentRegistry $fulfillment,
        private readonly StorefrontOrdering $ordering,
    ) {}

    /** Null when the cart is empty or a line no longer exists. `$booking`: price like the laundry booking. */
    public function quote(Store $store, Request $request, ?string $mode, ?string $couponCode, bool $booking = false): ?StorefrontQuote
    {
        $cartToken = $this->cart->token($request);
        // Read only: an empty browser gets no cart row from a quote.
        $cart = Cart::live()->where('store_id', $store->id)->where('guest_token', $cartToken)->first();
        $lines = $cart?->lines()->orderBy('id')->get();
        if ($lines === null || $lines->isEmpty()) {
            return null;
        }
        $mode = $mode !== null && $this->fulfillment->isValid($mode) ? $mode : null;

        $currency = strtoupper(store_currency((int) $store->id));
        $order = (new Order)->forceFill(['store_id' => $store->id, 'currency_code' => $currency, 'type' => $mode, 'branch_id' => null]);
        $pricing = app(ItemPricing::class);
        $items = [];
        // P4-16b: the cart's items and variants in one query each (was two queries per line).
        $menus = Menu::query()->where('store_id', $store->id)->whereIn('id', $lines->map(fn ($l) => (int) $l->menu_id)->unique()->values()->all())->get()->keyBy('id');
        $variantIds = $lines->filter(fn ($l) => (bool) $l->variation)->map(fn ($l) => (int) $l->variation)->unique()->values()->all();
        $variants = $variantIds === [] ? collect() : MenuVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');
        foreach ($lines as $line) {
            $menu = $menus->get((int) $line->menu_id);
            if ($menu === null) {
                return null;
            }
            $found = $line->variation ? $variants->get((int) $line->variation) : null;
            $variant = $found !== null && (int) $found->menu_id === (int) $menu->id ? $found : null;
            $item = (new OrderItem)->forceFill(['currency_code' => $currency]);
            $item->forceFill([
                'item_id' => $menu->id,
                'variation' => $line->variation,
                'quantity' => (int) $line->quantity,
            ] + $pricing->snapshot($menu, $variant));
            if ($booking && $line->weight_kg !== null) {
                // ServiceBookingService::book: a weighed line is one batch at price per kg × kg (3 decimals, HALF_UP).
                $perKg = $variant !== null ? (float) $variant->price : (float) $menu->price;
                $item->unit_price = Money::round((string) Money::decimal($perKg)->multipliedBy(Money::decimal((float) $line->weight_kg)), 3);
                $item->weight_kg = round((float) $line->weight_kg, 3);
            }
            $item->setRelation('menu', $menu);
            $item->setRelation('variant', $variant);
            $item->setRelation('itemExtras', collect(array_values(array_filter(array_map(
                fn ($e) => is_array($e) && ! empty($e['id']) ? (new OrderItemExtra)->forceFill(['extra_id' => (int) $e['id'], 'price' => (float) ($e['price'] ?? 0)]) : null,
                (array) ($line->extras ?? [])
            )))));
            $item->setRelation('order', $order);
            $items[] = $item;
        }
        $order->setRelation('items', collect($items));

        // The base each checkout hands its coupon: the goods checkout its cart total (GoodsCartService::lines,
        // add-ons included); the booking the weight-corrected order's own subtotal (ServiceBookingService::book).
        $couponBase = $booking ? null : (float) $this->cart->lines($store, $cartToken)->sum('line_total');

        return $this->ordering->quotePreview($store, $order, $couponCode, $couponBase);
    }
}
