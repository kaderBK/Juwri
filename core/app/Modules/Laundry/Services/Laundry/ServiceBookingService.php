<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Services\Laundry;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Laundry\Exceptions\LaundryHoldException;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\CartLine;
use Juwri\Modules\Ordering\Contracts\ScheduledSlots;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsOrderService;

/**
 * rev/multi-vertical Lane D — D2: booking order type + pickup/delivery
 * (DECISIONS A9).
 *
 * Wraps {@see GoodsCartService} and {@see GoodsOrderService} (Lane B,
 * consumed — never forked) to add the ONE thing a service booking needs that
 * a goods cart cannot express: a fractional per-kg quantity. Both services'
 * public API and tables (`carts`/`cart_lines`/`orders`/`order_items`) are
 * reused completely unmodified.
 *
 * Why a wrapper instead of widening GoodsCartService/GoodsOrderService: those
 * classes were themselves deliberately written as their OWN small writer
 * rather than a fork of the food OrderApiService, specifically because a
 * genuinely different semantic (there, food-only KDS routing; here, a
 * fractional weight `cart_lines.quantity`/`order_items.quantity` cannot
 * express — both are integer columns) does not fit the shared shape — see
 * GoodsOrderService's own docblock. This class applies the identical
 * judgment one layer up: an "each" line (e.g. "Shirt — iron") is added via
 * GoodsCartService::addLine() completely as-is; a "kg" line (e.g.
 * "Wash & fold") is written directly onto the SAME `cart_lines` row via the
 * additive `weight_kg` column (quantity pinned to 1 — "one weighed batch"),
 * and at checkout — AFTER GoodsOrderService::checkout() has done everything
 * it always does (mode/slot/address validation, the Order + OrderItem rows,
 * cart conversion) — this class snapshots each weighted line's
 * `order_items.unit_price` to `price_per_kg × weight_kg`, the exact mechanism
 * PARITY-RUN T5-3 already gives every OrderItem for a schedule-resolved price,
 * then re-calls the SAME public `Order::snapshotTotals()`. Order::computeMoney()
 * itself is never touched.
 */
class ServiceBookingService
{
    public function __construct(
        private GoodsCartService $cart,
        private GoodsOrderService $orders,
        private LaundryHolds $holds,
        private ScheduledSlots $scheduling,
    ) {}

    /** Passthrough to the SAME guest cart-owner token GoodsCartService mints (B6). */
    public function cartToken(Request $request): string
    {
        return $this->cart->token($request);
    }

    /** 'each' | 'kg', defaulting to 'each' for a service item that never set it (D1). */
    public function unit(Menu $menu): string
    {
        return $menu->pricing_unit === 'kg' ? 'kg' : 'each';
    }

    /**
     * Add (or accumulate) a service line. $amount is a garment COUNT for an
     * 'each' item, or a WEIGHT IN KG for a 'kg' item.
     *
     * rev/multi-vertical Lane V (integration fix 1) — returns the CartLine
     * (mirrors GoodsCartService::addLine()'s own return) so the booking
     * endpoint can hand its id back to `bookla`'s JS, letting the drawer
     * remove exactly the line it added instead of guessing.
     */
    public function addLine(Store $store, string $token, int $menuId, ?int $variationId, float $amount): CartLine
    {
        $menu = Menu::where('store_id', $store->id)->find($menuId);
        if (! $menu) {
            throw ValidationException::withMessages(['menu_id' => ___('Service not found for this store.')]);
        }

        if ($this->unit($menu) === 'kg') {
            return $this->upsertWeightLine($store, $token, $menu, $variationId, max(0.01, $amount));
        }

        return $this->cart->addLine($store, $token, $menuId, $variationId, max(1, (int) round($amount)));
    }

    /**
     * The whole cart in one step (the theme's service list): every service of `$amounts` (menu public id => pieces or
     * kg) replaces what the cart held. An id of another store, an inactive service or an amount outside 0.01–999 is refused
     * and the cart stays as it was.
     *
     * @param  array<string, float|int|string>  $amounts
     *
     * @throws ValidationException
     */
    public function replaceLines(Store $store, string $token, array $amounts): void
    {
        $amounts = array_filter($amounts, fn ($v) => is_numeric($v) && (float) $v > 0);
        if ($amounts === []) {
            throw ValidationException::withMessages(['lines' => ___('Choose at least one service.')]);
        }
        $ids = array_values(array_filter(array_keys($amounts), fn ($id) => \Illuminate\Support\Str::isUuid((string) $id)));
        $menus = Menu::query()->where('store_id', $store->id)->where('active', '1')->whereIn('public_id', $ids)->get()->keyBy('public_id');
        if ($menus->count() !== count($amounts)) {
            throw ValidationException::withMessages(['lines' => ___('Service not found for this store.')]);
        }

        DB::transaction(function () use ($store, $token, $amounts, $menus): void {
            $this->cart->currentCart($store, $token)->lines()->delete();
            foreach ($amounts as $publicId => $amount) {
                $menu = $menus[(string) $publicId];
                $this->addLine($store, $token, (int) $menu->id, null, min(999.0, (float) $amount));
            }
        });
    }

    private function upsertWeightLine(Store $store, string $token, Menu $menu, ?int $variationId, float $weightKg): CartLine
    {
        $variant = $variationId ? MenuVariant::where('menu_id', $menu->id)->find($variationId) : null;
        if ($variationId && ! $variant) {
            throw ValidationException::withMessages(['variation' => ___('Invalid variant for this product.')]);
        }

        $cart = $this->cart->currentCart($store, $token);
        $line = $cart->lines()
            ->where('menu_id', $menu->id)
            ->where('variation', $variant ? (string) $variant->id : null)
            ->first();

        if ($line) {
            $line->weight_kg = round((float) $line->weight_kg + $weightKg, 3);
            $line->quantity = 1;
            $line->save();

            return $line;
        }

        return $cart->lines()->create([
            'menu_id' => $menu->id,
            'variation' => $variant ? (string) $variant->id : null,
            'quantity' => 1,
            'weight_kg' => round($weightKg, 3),
        ]);
    }

    /**
     * Weight-aware read model on top of GoodsCartService::lines() — same
     * shape, plus 'unit' and (for a kg line) a correct 'line_total'
     * (unit_price × weight, not × the pinned quantity=1).
     *
     * @return Collection<int, mixed>
     */
    public function lines(Store $store, string $token): Collection
    {
        $cart = $this->cart->currentCart($store, $token);
        $weights = $cart->lines()->pluck('weight_kg', 'id');

        // P1-05: line totals at the store currency's decimals (the rule order money uses), exact unit price × kg,
        // HALF_UP once — was a hard-coded 2.
        $dec = money_decimals(store_currency((int) $store->id));

        return $this->cart->lines($store, $token)->map(function (array $line) use ($weights, $dec) {
            $weight = $weights[$line['line_id']] ?? null;
            if ($weight !== null) {
                $line['unit'] = 'kg';
                $line['weight_kg'] = (float) $weight;
                $line['line_total'] = Money::round((string) Money::decimal((float) $line['unit_price'])->multipliedBy(Money::decimal((float) $weight)), $dec);
            } else {
                $line['unit'] = 'each';
                $line['weight_kg'] = null;
            }

            return $line;
        })->values();
    }

    public function total(Store $store, string $token): float
    {
        return Money::round((float) $this->lines($store, $token)->sum('line_total'), money_decimals(store_currency((int) $store->id)));
    }

    /**
     * Book the cart. With a slot hold (P3-11, API-23: `hold` + `hold_token` in `$data`) the held pickup slot becomes the
     * order's slot and the hold's delivery-back time stays with the order; the hold is used up in the same transaction
     * as the order, so a refused booking gives it back. Without a hold the slot is whatever `scheduled_at` says
     * (the bare booking page and the older themes).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LaundryHoldException
     */
    public function book(Store $store, string $token, string $mode, array $data): Order
    {
        $holdId = isset($data['hold']) && $data['hold'] !== '' ? (string) $data['hold'] : null;
        $holdToken = (string) ($data['hold_token'] ?? '');
        unset($data['hold'], $data['hold_token']);
        if ($holdId === null) {
            return $this->placeCart($store, $token, $mode, $data);
        }

        // a resend of a booking already placed with this client key is that booking: its hold was used the first time
        $key = isset($data['client_order_id']) && $data['client_order_id'] !== '' ? (string) $data['client_order_id'] : null;
        if ($key !== null && Order::query()->where('store_id', $store->id)->where('client_uuid', $key)->exists()) {
            return $this->placeCart($store, $token, $mode, $data);
        }

        return DB::transaction(function () use ($store, $token, $mode, $data, $holdId, $holdToken) {
            $hold = $this->holds->consume($store, $holdId, $holdToken, $mode);
            $data['scheduled_at'] = Carbon::instance($hold->pickup_at)->setTimezone($this->scheduling->timezone((int) $store->id))->format('Y-m-d H:i');
            $order = $this->placeCart($store, $token, $mode, $data);
            if ($order->scheduled_at === null) {
                // `delivery` (we collect from the customer's address and bring it back) takes no slot in the checkout
                // writer; its order still carries the held pickup time, as a drop-off order does
                $order->forceFill(['scheduled_at' => Carbon::instance($hold->pickup_at)->setTimezone($this->scheduling->timezone((int) $store->id))])->save();
            }
            $hold->forceFill(['order_id' => $order->id])->save();

            return $order;
        });
    }

    /**
     * checkout via GoodsOrderService unmodified, then correct every per-kg line's money snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    private function placeCart(Store $store, string $token, string $mode, array $data): Order
    {
        $cart = $this->cart->currentCart($store, $token);
        $weightMap = [];
        foreach ($cart->lines()->get() as $line) {
            if ($line->weight_kg !== null) {
                $weightMap[$line->menu_id.'|'.($line->variation ?? '')] = (float) $line->weight_kg;
            }
        }

        // Coupon / gift-card / loyalty are applied by THIS class AFTER the per-kg
        // price correction below — never inside checkout(), because a percentage
        // coupon would otherwise discount the cart's pre-weight subtotal (a weighted
        // line rides the cart as qty 1 × price_per_kg). Withhold them from the
        // checkout call so GoodsOrderService::applyDiscounts is a no-op there, then
        // apply against the corrected subtotal.
        $discountData = [
            'coupon_code' => $data['coupon_code'] ?? null,
            'gift_card_code' => $data['gift_card_code'] ?? null,
            'loyalty_redeem' => $data['loyalty_redeem'] ?? null,
        ];
        unset($data['coupon_code'], $data['gift_card_code'], $data['loyalty_redeem']);

        [$order, $replayed] = $this->orders->checkoutOrReplay($store, $token, $mode, $data);
        if ($replayed) {
            // J27-A2: a resent booking (same client_order_id) is the order already booked — its weights were
            // priced and its coupon / gift card / points settled the first time; doing it again would charge twice.
            return $order;
        }

        if ($weightMap !== []) {
            $dirty = false;
            foreach ($order->items as $item) {
                $key = $item->item_id.'|'.($item->variation ?? '');
                if (! array_key_exists($key, $weightMap)) {
                    continue;
                }
                $weight = $weightMap[$key];
                $pricePerKg = $item->variation && $item->variant
                    ? (float) $item->variant->price
                    : (float) ($item->menu->price ?? 0);

                $item->weight_kg = round($weight, 3);
                // P1-05: exact price × kg at 3 decimals as before (HALF_UP, PHP-version independent); the order line's
                // cast then stores it at the order currency's exponent.
                $item->unit_price = Money::round((string) Money::decimal($pricePerKg)->multipliedBy(Money::decimal($weight)), 3);
                $item->save();
                $dirty = true;
            }
            if ($dirty) {
                $order->unsetRelation('items');
            }
        }

        // Now that every line carries its final (weight-corrected) price, settle
        // the discounts against the true subtotal, then snapshot once.
        if ($discountData['coupon_code'] || $discountData['gift_card_code'] || $discountData['loyalty_redeem']) {
            // P3-13 (bug): the order's own subtotal (Order::computeMoney: a line without a price snapshot at its
            // item / variant price, add-ons included). Was the sum of unit_price × qty, which counted a per-item line
            // with no scheduled price (unit_price NULL) as 0 — a percentage coupon took too little off.
            $subtotal = (float) $order->computeMoney()['subtotal'];
            $this->orders->applyDiscounts($order, $store, $discountData, $subtotal);
        }

        $order->unsetRelation('items');
        $order->snapshotTotals();

        return $order->fresh('items');
    }
}
