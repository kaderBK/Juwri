<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;

/**
 * rev/multi-vertical B6 — storefront cart for goods/service verticals.
 *
 * A qty stepper + variant select on the product page posts here; the cart
 * page shows the resolved lines (variant + line total). This is the same
 * cart GoodsOrderService (B2/B3) reads at checkout, so what the customer saw
 * in the cart is exactly what gets ordered and totalled.
 */
class GoodsCartController extends Controller
{
    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    /** GET {slug}/goods/{menu} — a minimal qty/variant "add to cart" form. */
    public function showProduct(string $slug, Menu $menu, GoodsCartService $cart, Request $request): View
    {
        $store = $this->store($slug);
        abort_unless((int) $menu->store_id === (int) $store->id, 404);

        return view('shop.goods.product', [
            'store' => $store,
            'menu' => $menu,
            'variants' => MenuVariant::where('menu_id', $menu->id)->where('active', 1)->get(),
            'lines' => $cart->lines($store, $cart->token($request)),
        ]);
    }

    /**
     * POST {slug}/goods/{menu}/cart — add/increment a line.
     *
     * rev/multi-vertical Lane V (integration fix 1) — the `shelf` theme's
     * item sheet calls this via fetch with `Accept: application/json` so a
     * real add-to-cart click lands on this native endpoint instead of the
     * legacy `restaurant.submitOrder`. The original redirect-back behaviour
     * (used by the bare-HTML `goods.product` page) is untouched for a normal
     * form submission.
     */
    public function addToCart(string $slug, Menu $menu, Request $request, GoodsCartService $cart): RedirectResponse|JsonResponse
    {
        $store = $this->store($slug);
        abort_unless((int) $menu->store_id === (int) $store->id, 404);

        // ValidationException (from validate() below, or thrown inside
        // GoodsCartService::addLine() for a wrong-store variant) is left to
        // propagate: Laravel's own exception handler already renders it as a
        // 422 JSON body for a `wantsJson()` request (shelf's fetch calls) and
        // as a redirect-back-with-errors for a normal form post (the
        // bare-HTML goods.product page) — no bespoke branching needed here.
        $data = $request->validate([
            'variation' => 'nullable|integer',
            'quantity' => 'nullable|integer|min:1|max:99',
            'extras' => 'nullable|array',
            'extras.*' => 'integer',
        ]);

        $line = $cart->addLine(
            $store,
            $cart->token($request),
            (int) $menu->id,
            $data['variation'] ?? null,
            (int) ($data['quantity'] ?? 1),
            array_map('intval', (array) ($data['extras'] ?? []))
        );

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'line_id' => $line->id]);
        }

        return back()->with('success', ___('Added to cart.'));
    }

    /** GET {slug}/cart — the cart summary (lines + total). */
    public function show(string $slug, GoodsCartService $cart, Request $request): View
    {
        $store = $this->store($slug);
        $token = $cart->token($request);

        return view('shop.goods.cart', [
            'store' => $store,
            'lines' => $cart->lines($store, $token),
            'total' => $cart->total($store, $token),
        ]);
    }

    /** POST {slug}/cart/{line}/quantity — update or remove one line. */
    public function updateLine(string $slug, int $line, Request $request, GoodsCartService $cart): RedirectResponse|JsonResponse
    {
        $store = $this->store($slug);
        $quantity = (int) $request->input('quantity', 1);

        $cart->updateLineQuantity($store, $cart->token($request), $line, $quantity);

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', ___('Cart updated.'));
    }
}
