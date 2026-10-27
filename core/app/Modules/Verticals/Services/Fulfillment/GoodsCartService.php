<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fulfillment;

use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Ordering\Models\CartLine;
use Juwri\Modules\Menu\Contracts\ItemPricing;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Core\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;

/**
 * rev/multi-vertical B6 — cart qty + line semantics for goods.
 *
 * Reuses the EXISTING `carts`/`cart_lines` tables (BRAINSTORM §5: "carts" is a
 * listed reusable asset) rather than forking a parallel cart concept. This is
 * a plain guest-token cart scoped to one store — deliberately NOT the
 * marketplace cross-store bag (App\Services\MultiVendor\CrossCartService),
 * which is a different, heavier multi-vendor checkout model this lane does
 * not need. A goods/service line here becomes an OrderItem 1:1 at checkout
 * (see GoodsOrderService), so "correct variant + line total" is the SAME
 * price derivation Order::computeMoney() uses (menu/variant price × qty).
 */
class GoodsCartService
{
    private const TOKEN_COOKIE = 'mv_goods_cart_token';

    private const TOKEN_SESSION = 'mv_goods_cart_token';

    private const TTL_DAYS = 14;

    /** The guest cart-owner token, minted once and remembered cookie+session. */
    public function token(Request $request): string
    {
        $token = (string) ($request->cookie(self::TOKEN_COOKIE) ?: session(self::TOKEN_SESSION, ''));
        if ($token === '') {
            $token = Str::random(40);
        }
        session([self::TOKEN_SESSION => $token]);
        Cookie::queue(Cookie::make(self::TOKEN_COOKIE, $token, self::TTL_DAYS * 24 * 60));

        return $token;
    }

    /** Drop this browser's guest cart-owner token, so the next visitor starts an empty cart (the kiosk's idle reset). */
    public function forgetToken(): void
    {
        session()->forget(self::TOKEN_SESSION);
        Cookie::queue(Cookie::forget(self::TOKEN_COOKIE));
    }

    /** The active cart for this store+token, creating one on first use. */
    public function currentCart(Store $store, string $token): Cart
    {
        $cart = Cart::live()
            ->where('store_id', $store->id)
            ->where('guest_token', $token)
            ->first();

        return $cart ?: Cart::create([
            'store_id' => $store->id,
            'guest_token' => $token,
            'status' => 'active',
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);
    }

    /**
     * Add (or bump the quantity of) a line. Tenancy-fenced: the item and its
     * variant (if any) must belong to THIS store, never trusted from the
     * request alone (G2).
     */
    /**
     * @param  list<int>  $extraIds  Selected add-on (MenuExtra) ids — resolved
     *                               against the item's OWN offered set and snapshotted
     *                               with an effective (price_override-applied) price,
     *                               exactly like OrderApiService does for the restaurant
     *                               pipeline. Never trusts a client-sent price.
     */
    public function addLine(Store $store, string $token, int $menuId, ?int $variationId, int $quantity, array $extraIds = []): CartLine
    {
        $menu = Menu::where('store_id', $store->id)->find($menuId);
        if (! $menu) {
            throw ValidationException::withMessages(['menu_id' => ___('Product not found for this store.')]);
        }

        $variant = null;
        if ($variationId) {
            $variant = MenuVariant::where('menu_id', $menu->id)->find($variationId);
            if (! $variant) {
                throw ValidationException::withMessages(['variation' => ___('Invalid variant for this product.')]);
            }
        }

        $extras = $this->resolveExtras($menu, $extraIds);

        $quantity = max(1, $quantity);
        $cart = $this->currentCart($store, $token);
        $this->assertSalesAllowed($store, $cart, [$menu->id => $quantity]); // P1-23: AUP 3.1 at add-to-cart
        $this->assertAvailable($store, $cart, [$menu->id.':'.($variant ? $variant->id : 0) => $quantity]);

        // Two lines dedup only when the SAME item, variant AND add-on set match;
        // a different add-on selection is its own line.
        $sig = $this->extrasSignature($extras);
        $line = $cart->lines()
            ->where('menu_id', $menu->id)
            ->where('variation', $variant ? (string) $variant->id : null)
            ->get()
            ->first(fn (CartLine $l) => $this->extrasSignature((array) ($l->extras ?? [])) === $sig);

        if ($line) {
            $line->quantity = (int) $line->quantity + $quantity;
            $line->save();

            return $line;
        }

        return $cart->lines()->create([
            'menu_id' => $menu->id,
            'variation' => $variant ? (string) $variant->id : null,
            'quantity' => $quantity,
            'extras' => $extras ?: null,
        ]);
    }

    /**
     * Resolve requested add-on ids against the item's own offered set, returning
     * a snapshot list of {id, title, price} (effective, price_override-applied).
     *
     * @param  list<int>  $extraIds
     * @return list<array{id:int,title:string,price:float}>
     */
    private function resolveExtras(Menu $menu, array $extraIds): array
    {
        $extraIds = array_values(array_unique(array_map('intval', $extraIds)));
        if ($extraIds === []) {
            return [];
        }
        $offered = $menu->resolved_extras->keyBy('id');
        $out = [];
        foreach ($extraIds as $id) {
            $extra = $offered->get($id);
            if (! $extra || ! $extra->active) {
                throw ValidationException::withMessages(['extras' => ___('Invalid add-on for this item.')]);
            }
            $out[] = ['id' => (int) $extra->id, 'title' => (string) $extra->title, 'price' => (float) $extra->price];
        }

        return $out;
    }

    /**
     * Order-independent signature of a line's extras, for dedup.
     *
     * @param  array<int, array{id?:int|string}>  $extras
     */
    private function extrasSignature(array $extras): string
    {
        $ids = array_map(fn ($e) => (int) ($e['id'] ?? 0), $extras);
        sort($ids);

        return implode(',', $ids);
    }

    public function updateLineQuantity(Store $store, string $token, int $lineId, int $quantity): void
    {
        $cart = $this->currentCart($store, $token);
        $line = $cart->lines()->find($lineId);
        if (! $line) {
            return;
        }
        if ($quantity <= 0) {
            $line->delete();

            return;
        }
        $this->assertSalesAllowed($store, $cart, [(int) $line->menu_id => $quantity - (int) $line->quantity]); // P1-23
        $this->assertAvailable($store, $cart, [(int) $line->menu_id.':'.((int) $line->variation ?: 0) => $quantity - (int) $line->quantity]);
        $line->quantity = $quantity;
        $line->save();
    }

    /**
     * P1-23 (AUP 3.1): the store's sales rules on the cart as it would be after this change ($delta = menu id =>
     * units added or removed) — a pharmacy that is not published, or a medicine above the per-order cap, is refused
     * here at add-to-cart and again at checkout ({@see GoodsOrderService}).
     *
     * @param  array<int, int>  $delta
     */
    public function assertSalesAllowed(Store $store, Cart $cart, array $delta = []): void
    {
        $qty = [];
        foreach ($cart->lines()->get() as $l) {
            $qty[(int) $l->menu_id] = ($qty[(int) $l->menu_id] ?? 0) + (int) $l->quantity;
        }
        foreach ($delta as $id => $n) {
            $qty[(int) $id] = ($qty[(int) $id] ?? 0) + (int) $n;
        }
        $menus = Menu::where('store_id', $store->id)->whereIn('id', array_keys($qty))->get()->keyBy('id');
        $lines = [];
        foreach ($qty as $id => $n) {
            if ($n > 0 && ($menu = $menus->get($id)) !== null) {
                $lines[] = ['menu' => $menu, 'quantity' => $n];
            }
        }
        $refusal = app(SalesRestrictions::class)->orderRefusal($store, $lines);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['quantity' => $refusal]);
        }
    }

    /**
     * Found by the e2eA retail journey: nothing server-side stopped a sold-out product, a switched-off product or a
     * variant with no stock from entering a cart or an order (only the theme hid the buy button), so a stale page or a
     * hand-made request oversold it. The cart as it would be after this change ($delta = "menuId:variantId" => units
     * added or removed) must be orderable: product on and not sold out; a chosen variant on, with its own stock
     * covering the units (a product's own stock when it tracks inventory and has no variant).
     *
     * @param  array<string, int>  $delta
     *
     * @throws ValidationException
     */
    public function assertAvailable(Store $store, Cart $cart, array $delta = []): void
    {
        $qty = [];
        foreach ($cart->lines()->get() as $l) {
            $key = (int) $l->menu_id.':'.((int) $l->variation ?: 0);
            $qty[$key] = ($qty[$key] ?? 0) + (int) $l->quantity;
        }
        foreach ($delta as $key => $n) {
            $qty[$key] = ($qty[$key] ?? 0) + (int) $n;
        }

        foreach ($qty as $key => $units) {
            if ($units <= 0) {
                continue;
            }
            [$menuId, $variantId] = array_map('intval', explode(':', (string) $key));
            $menu = Menu::where('store_id', $store->id)->find($menuId);
            if ($menu === null) {
                continue; // an unknown product is refused where the line is created
            }
            $name = (string) $menu->getRawOriginal('name');
            if ((string) $menu->getRawOriginal('active') === '0') {
                throw ValidationException::withMessages(['quantity' => ___('":item" is no longer available.', ['item' => $name])]);
            }
            if ($menu->is_sold_out) {
                throw ValidationException::withMessages(['quantity' => ___(':item is sold out.', ['item' => $name])]);
            }
            $variant = $variantId > 0 ? MenuVariant::where('menu_id', $menu->id)->find($variantId) : null;
            if ($variantId > 0 && ($variant === null || ! $variant->active)) {
                throw ValidationException::withMessages(['quantity' => ___('":item" is no longer available.', ['item' => $name])]);
            }

            $stock = $variant !== null
                ? $variant->stock_qty
                : ($menu->track_inventory ? $menu->stock_qty : null);
            if ($stock !== null && $units > (int) $stock) {
                throw ValidationException::withMessages(['quantity' => (int) $stock <= 0
                    ? ___(':item is sold out.', ['item' => $name])
                    : ___('Only :count of ":item" left.', ['count' => (int) $stock, 'item' => $name])]);
            }
        }
    }

    public function removeLine(Store $store, string $token, int $lineId): void
    {
        $this->currentCart($store, $token)->lines()->where('id', $lineId)->delete();
    }

    /**
     * The cart lines with their resolved variant + money, in the exact shape
     * a storefront cart view (and the checkout summary) needs: line_id,
     * menu_id, name, variant_id, variant_title, quantity, unit_price,
     * line_total.
     *
     * @return Collection<int, mixed>
     */
    public function lines(Store $store, string $token): Collection
    {
        $cart = $this->currentCart($store, $token);
        // Bug 6 (2026-10-01): cart money rounds like the order it becomes — the store currency's decimals (MC-10/22,
        // slice 10a order-money rule) through Money::round; was round(…, 2), so a KWD 1.125 price showed 1.13.
        $dec = money_decimals(store_currency((int) $store->id));

        $pricing = app(ItemPricing::class);

        // P4-16b: the lines' items, variants and variant option groups in one query each (was three per line).
        $cartLines = $cart->lines()->orderBy('id')->get();
        $menus = Menu::query()->whereIn('id', $cartLines->pluck('menu_id')->filter()->unique()->values()->all())->get()->keyBy('id');
        $variantIds = $cartLines->filter(fn (CartLine $l) => (bool) $l->variation)->map(fn (CartLine $l) => (int) $l->variation)->unique()->values()->all();
        $variants = $variantIds === [] ? collect() : MenuVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');
        $groupIds = $variants->flatMap(fn (MenuVariant $v) => array_keys((array) $v->options))->filter(fn ($k) => is_numeric($k))->map(fn ($k) => (int) $k)->unique()->values()->all();
        $groups = $groupIds === [] ? collect() : MenuVariantOption::query()->whereIn('id', $groupIds)->get()->keyBy('id');

        return $cartLines->map(function (CartLine $line) use ($dec, $pricing, $menus, $variants, $groups) {
            $menu = $menus->get($line->menu_id);
            $variant = $line->variation ? $variants->get((int) $line->variation) : null;
            $base = $variant ? (float) $variant->price : (float) ($menu->price ?? 0);
            /* P1-12 (bug): the storefront shows the scheduled (sale / happy-hour) price, so the cart and the order
               charge it too — the scheduled price replaces the item/variant price, add-ons stay additive, exactly as
               Order::computeMoney() re-totals the snapshotted line. Was the list price (customer charged more). */
            if ($menu !== null && $base > 0) {
                $base = $pricing->unitPrice($menu, $base);
            }
            $extras = array_values((array) ($line->extras ?? []));
            $extrasTotal = array_sum(array_map(fn ($e) => (float) ($e['price'] ?? 0), $extras));
            $unitPrice = $base + $extrasTotal;
            $quantity = (int) $line->quantity;

            return [
                'line_id' => $line->id,
                'menu_id' => $line->menu_id,
                'name' => $menu ? (string) $menu->getRawOriginal('name') : null,
                'variant_id' => $variant?->id,
                'variant_title' => $variant ? $this->variantLabel($variant, $groups) : null,
                // Snapshotted add-ons ({id,title,price}) — surfaced for the cart
                // display and turned into OrderItemExtra rows at checkout.
                'extras' => $extras,
                'extras_title' => $extras ? implode(', ', array_map(fn ($e) => (string) ($e['title'] ?? ''), $extras)) : null,
                'quantity' => $quantity,
                'unit_price' => Money::round($unitPrice, $dec),
                'line_total' => Money::round((string) Money::decimal($unitPrice)->multipliedBy($quantity), $dec),
            ];
        })->values();
    }

    public function total(Store $store, string $token): float
    {
        return Money::round((float) $this->lines($store, $token)->sum('line_total'), money_decimals(store_currency((int) $store->id)));
    }

    /** Mark the cart converted after a successful checkout (no longer "live"). */
    public function convert(Store $store, string $token): void
    {
        $this->currentCart($store, $token)->update(['status' => 'converted']);
    }

    /** Mirrors OrderItem::getVariationTitleAttribute() for a storefront-friendly label. */
    /** @param  Collection<int, MenuVariantOption>|null  $groups  preloaded option groups by id */
    private function variantLabel(MenuVariant $variant, ?Collection $groups = null): string
    {
        $parts = [];
        foreach ((array) $variant->options as $optionId => $optionKey) {
            $group = is_numeric($optionId) ? ($groups !== null ? $groups->get((int) $optionId) : MenuVariantOption::find((int) $optionId)) : null; // legacy rows keyed by option name
            if (! $group) {
                continue;
            }
            $options = $group->options;
            if (is_string($options)) {
                $options = json_decode($options, true);
            } elseif (is_object($options)) {
                $options = (array) $options;
            }
            if (is_array($options) && isset($options[$optionKey])) {
                $parts[] = $options[$optionKey];
            }
        }

        return $parts !== [] ? implode(', ', $parts) : (string) ($variant->name ?: ('#'.$variant->id));
    }
}
