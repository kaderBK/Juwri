<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers;

use App\Models\Order;
use App\Services\MultiVendor\CrossCartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * GAPS-RUN-2 B12-P2 → T3-5 (D6): the hub's BROWSE UI is retired — every GET
 * page 301s into the Fusion marketplace (/fusion). The 301s are unconditional
 * (no multivendor gate: a redirect exposes no data). The POST endpoints stay
 * fully functional and gated, because the Fusion discovery/bag JS reuses
 * /order-hub/add|update|remove as its cart mutation API, and POST /checkout
 * keeps working for backwards compat (old clients mid-flow).
 *
 * 301 mapping (documented decision):
 *  - GET /order-hub              → /fusion
 *  - GET /order-hub/store/{slug} → /fusion/store/{slug} when the store is
 *    mkApproved (the Fusion store page — served by L2 T2-3), else /fusion.
 *    Literal path, not route(): the store-page route lives on the L2 lane.
 *  - GET /order-hub/checkout     → /fusion/bag (the hub checkout is replaced
 *    by the Fusion bag → checkout flow).
 *
 * P1-13 follow-up: /fusion is the Marketplace module's (ee). Without it (Community: no route mk.home) these 301s
 * would land on a 404, so the GET pages go to working pages instead: /checkout shows the hub's own cart page when
 * the cross-store cart is on, /store/{slug} the store's own storefront, anything else the home page. Temporary (302) redirects,
 * so browsers do not cache them across an edition change.
 */
class OrderHubController extends Controller
{
    public function __construct(private CrossCartService $cart) {}

    private function gate(): void
    {
        abort_unless(feature_enabled('multivendor_cart'), 404);
    }

    /** GET /order-hub — browse UI retired (T3-5/D6). */
    public function index()
    {
        if (! $this->marketplaceInstalled()) {
            return $this->communityLanding();
        }

        return redirect('/fusion', 301);
    }

    /** GET /order-hub/store/{slug} — mkApproved stores land on their Fusion page. */
    public function store(string $slug)
    {
        $store = Store::idOrSlug($slug)->first();

        if (! $this->marketplaceInstalled()) {
            return $store ? redirect()->route('publicView', $store->slug) : $this->communityLanding();
        }

        if ($store && Store::mkApproved()->whereKey($store->id)->exists()) {
            return redirect('/fusion/store/'.$store->slug, 301);
        }

        return redirect('/fusion', 301);
    }

    public function add(Request $request)
    {
        $this->gate();
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'note' => ['nullable', 'string', 'max:255'],
            // P3.8 selections seam. Shapes match OrderApiService::resolveLines,
            // so the cart stores them verbatim and checkout forwards them.
            // Every id/label is re-validated against the item in CrossCartService.
            'variation' => ['nullable', 'integer'],
            'options' => ['nullable', 'array'],
            'options.*.group_id' => ['required_with:options', 'integer'],
            'options.*.selected' => ['required_with:options', 'array'],
            'options.*.selected.*' => ['string', 'max:190'],
            'extras' => ['nullable', 'array'],
            'extras.*' => ['integer'],
        ]);
        try {
            $this->cart->addItem(
                (int) $data['store_id'],
                (int) $data['item_id'],
                (int) ($data['quantity'] ?? 1),
                $data['note'] ?? null,
                isset($data['variation']) ? ((int) $data['variation'] ?: null) : null,
                (array) ($data['options'] ?? []),
                (array) ($data['extras'] ?? []),
            );
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
            }

            return back()->withErrors($e->errors());
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'cart' => $this->cartView()]);
        }

        return back()->with('success', ___('Added to cart.'));
    }

    /** Stepper endpoint: set a line's absolute quantity (0 removes). JSON-first. */
    public function update(Request $request)
    {
        $this->gate();
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
            'note' => ['nullable', 'string', 'max:255'],
            // Optional: target ONE configuration of an item (P3.8). Omitted →
            // first line matching item_id, the pre-P3.8 stepper behaviour.
            'line_id' => ['nullable', 'integer'],
        ]);
        try {
            $this->cart->setItem(
                (int) $data['store_id'],
                (int) $data['item_id'],
                (int) $data['quantity'],
                array_key_exists('note', $data) ? (string) $data['note'] : null,
                isset($data['line_id']) ? ((int) $data['line_id'] ?: null) : null,
            );
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'error' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json(['ok' => true, 'cart' => $this->cartView()]);
    }

    public function remove(Request $request)
    {
        $this->gate();
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer'],
            'line_id' => ['nullable', 'integer'],
        ]);
        $this->cart->removeItem(
            (int) $data['store_id'],
            (int) $data['item_id'],
            isset($data['line_id']) ? ((int) $data['line_id'] ?: null) : null,
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'cart' => $this->cartView()]);
        }

        return back()->with('success', ___('Removed.'));
    }

    /** GET /order-hub/checkout — replaced by the Fusion bag → checkout flow. */
    public function checkout()
    {
        if (! $this->marketplaceInstalled()) {
            if (! feature_enabled('multivendor_cart')) {
                return redirect(url('/'));
            }

            return view('order-hub.checkout', ['cart' => $this->cartView()]);
        }

        return redirect('/fusion/bag', 301);
    }

    /** The Fusion marketplace (ee Marketplace module) serves /fusion. */
    private function marketplaceInstalled(): bool
    {
        return Route::has('mk.home');
    }

    /** Community: there is no store browser (it was retired into /fusion) — the home page. */
    private function communityLanding(): RedirectResponse
    {
        return redirect(url('/'));
    }

    public function placeOrder(Request $request)
    {
        $this->gate();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
        ]);

        $results = $this->cart->checkout(['name' => $data['name'], 'phone' => $data['phone'], 'type' => 'takeaway']);

        // Batch title + order lookups instead of one query per result row.
        $titles = Store::whereIn('id', array_column($results, 'store_id'))->pluck('title', 'id');
        $orders = Order::whereIn('id', array_filter(array_column($results, 'order_id')))->get()->keyBy('id');

        $rows = collect($results)->map(function ($r) use ($titles, $orders) {
            $order = $r['ok'] && $r['order_id'] ? $orders->get($r['order_id']) : null;

            return [
                'store' => $titles[$r['store_id']] ?? null,
                'ok' => $r['ok'],
                'order_id' => $r['order_id'],
                'error' => $r['error'],
                'track' => $order && $order->track_token ? route('store.orderTrack', $order->track_token) : null,
            ];
        })->all();

        return view('order-hub.confirmation', ['rows' => $rows, 'cart' => $this->cartView()]);
    }

    /** Session cart hydrated with names/prices for display. */
    private function cartView(): array
    {
        $cart = $this->cart->cart();
        if (empty($cart)) {
            return [];
        }

        // Batch the store titles + menu rows up front (one query each) instead of
        // per-store lookups inside the loop.
        $rids = array_map('intval', array_keys($cart));
        $titles = Store::whereIn('id', $rids)->pluck('title', 'id');
        $allItemIds = array_merge(...array_map(fn ($lines) => array_column($lines, 'item_id'), $cart));
        $menus = Menu::whereIn('id', $allItemIds)->get()->keyBy('id');

        $out = [];
        foreach ($cart as $rid => $lines) {
            $rid = (int) $rid;
            $storeItems = [];
            $total = 0.0;
            // P6-208: KWD/BHD/JOD keep 3 decimals.
            $dec = money_decimals(store_currency($rid));
            foreach ($lines as $line) {
                $menu = $menus->get($line['item_id']);
                if (! $menu || (int) $menu->store_id !== $rid) {
                    continue;
                }
                // P3.8: the unit price is the CONFIGURED one (variant base +
                // option/extra add-ons), resolved server-side from the ids on the
                // line — same math as BagQuote, so a bag with selections no
                // longer under-reports its total.
                $sel = $this->cart->describeLine($menu, $line);
                $lineTotal = $sel['unit_price'] * (int) $line['quantity'];
                $total += $lineTotal;
                $storeItems[] = [
                    'line_id' => $line['line_id'] ?? null,
                    'item_id' => $menu->id,
                    'name' => $menu->name,
                    'quantity' => $line['quantity'],
                    'note' => isset($line['note']) && $line['note'] !== '' ? (string) $line['note'] : null,
                    'unit_price' => $sel['unit_price'],
                    'image' => $menu->image ? media_url('storage/menu/', $menu->image, $rid) : null,
                    'line_total' => money_round($lineTotal, $dec),
                    // Resolved labels + prices for the bag's selection summary.
                    'variant' => $sel['variant'],
                    'options' => $sel['options'],
                    'extras' => $sel['extras'],
                    'selection' => $sel['summary'],
                ];
            }
            $out[] = [
                'store_id' => $rid,
                'title' => $titles[$rid] ?? null,
                'items' => $storeItems,
                'total' => money_round($total, $dec),
            ];
        }

        return $out;
    }
}
