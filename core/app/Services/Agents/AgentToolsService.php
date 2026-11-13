<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Agents;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CouponService;
use App\Services\CustomerWalletService;
use App\Services\OrderApiService;
use App\Services\Verticals\FulfillmentRegistry;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Modules\Agents\Contracts\AgentPolicyStore;
use Juwri\Modules\Agents\Tools\AgentTool;
use Juwri\Modules\Agents\Tools\AgentToolRegistry;
use Juwri\Modules\Menu\Contracts\Recommendations;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;

/**
 * Agents P1: the ONE tool implementation shared by our chat widget (AiService
 * function-calling) and external MCP agents. Every method takes a RESOLVED
 * restaurant (never a client-supplied id) and returns JSON-safe arrays.
 *
 * Invariants (spec §5): server-side pricing only; the only mutating tool is
 * createOrder (unpaid, capped, takeaway/table only); no payment fields anywhere;
 * createOrder refuses when agent ordering is disabled for the restaurant's plan.
 */
class AgentToolsService
{
    /** Hard caps so an LLM loop can't spam the kitchen. */
    public const MAX_ITEMS_PER_ORDER = 40;

    public const MAX_QTY_PER_LINE = 30;

    /** AO5 #27: above these, the agent must read back + get explicit confirmation. */
    public const CONFIRM_QTY_TOTAL = 10;   // total units across the cart

    public const CONFIRM_SUBTOTAL = 200;  // money threshold

    /** searchMenu — name/description match + vegan/halal/price filters; sold-out flagged. */
    public static function searchMenu(Store $store, string $query, array $filters = []): array
    {
        $q = Menu::where('store_id', $store->id)->where('active', '1'); // enum('0','1'): string, not index

        $query = trim($query);
        if ($query !== '') {
            // Full-phrase first; customers phrase items loosely ("Margherita pizza"
            // for an item named "Margherita"), so fall back to any-token matching
            // when the phrase itself hits nothing.
            $phrase = clone $q;
            $phrase->where(function ($w) use ($query) {
                $w->whereLike('name', "%{$query}%")->orWhereLike('description', "%{$query}%");
            });
            $tokens = array_values(array_filter(
                preg_split('/[\s,\.]+/u', mb_strtolower($query)) ?: [],
                fn ($t) => mb_strlen($t) >= 3
            ));
            if ($phrase->exists() || $tokens === []) {
                $q = $phrase;
            } else {
                $q->where(function ($w) use ($tokens) {
                    foreach ($tokens as $t) {
                        $w->orWhereLike('name', "%{$t}%")->orWhereLike('description', "%{$t}%");
                    }
                });
            }
        }
        if (! empty($filters['vegan'])) {
            $q->where('is_vegan', 1);
        }
        if (! empty($filters['halal'])) {
            $q->where('is_halal', 1);
        }
        if (isset($filters['max_price'])) {
            // P1-05: price_minor in the store currency.
            $q->where('price_minor', '<=', Money::minorFromMajor((float) $filters['max_price'], store_currency($store->id)));
        }

        $found = $q->orderBy('position')->limit(50)->get();

        /* PARITY-RUN T5: quote the SCHEDULED price and hide anything outside its
           window. The agent is a GUEST channel — OrderApiService refuses an
           out-of-window line for source=agent — so an agent that offered one
           would talk a customer into an order the server then rejects, and an
           agent quoting the list price during a happy hour would disagree with
           the bill. */
        MenuScheduleService::decorate($found, (int) $store->id);
        $found = $found->filter(fn ($m) => $m->available_now !== false)->values();

        // rev/mv-mcp-parity: a non-food store's agent needs the goods attrs
        // (brand/stock), not is_vegan/calories. Eager-load brand once for the
        // whole result set so the map below isn't an N+1.
        $isFood = $store->isFoodVertical();
        if (! $isFood) {
            $found->loadMissing('brand');
        }

        return $found->map(function ($m) use ($isFood) {
            $row = [
                'id' => $m->id,
                'name' => $m->getRawOriginal('name'),
                'price' => (float) $m->price,
                'sold_out' => (bool) $m->is_sold_out,
            ];
            if ($isFood) {
                $row['is_vegan'] = (bool) $m->is_vegan;
                $row['is_halal'] = (bool) $m->is_halal;
                $row['calories'] = $m->calories;

                return $row;
            }
            if ($m->brand) {
                $row['brand'] = $m->brand->name;
            }
            if ($m->track_inventory && $m->stock_qty !== null) {
                $row['stock'] = (int) $m->stock_qty;
                $row['in_stock'] = (int) $m->stock_qty > 0;
            }

            return $row;
        })->values()->all();
    }

    /** getItem — full item (variants, extras, groups) for the restaurant. */
    public static function getItem(Store $store, int $menuId): ?array
    {
        $m = Menu::where('store_id', $store->id)->where('id', $menuId)->first();
        if (! $m) {
            return null;
        }

        // T5: same scheduled price (and variant prices) the order path will charge.
        $m->load('variants');
        MenuScheduleService::decorate([$m], (int) $store->id);

        $out = [
            'id' => $m->id,
            'name' => $m->getRawOriginal('name'),
            'description' => $m->description,
            'price' => (float) $m->price,
            'sold_out' => (bool) $m->is_sold_out,
            'variants' => $m->variants->where('active', true)->map(fn ($v) => ['id' => $v->id, 'price' => (float) $v->price, 'options' => $v->options])->values()->all(),
            'extras' => $m->resolved_extras->map(fn ($e) => ['id' => $e->id, 'title' => $e->getRawOriginal('title'), 'price' => (float) $e->price])->values()->all(),
        ];

        // rev/mv-mcp-parity: a goods/services agent needs the attrs the item
        // actually has — brand/stock/weight, plus capability-gated OTC (pharmacy)
        // and fitment (car-parts) — instead of a food-only shape.
        if (! $store->isFoodVertical()) {
            $cfg = $store->verticalConfig();
            if ($m->brand) {
                $out['brand'] = $m->brand->name;
            }
            if ($m->weight !== null) {
                $out['weight'] = (float) $m->weight;
            }
            if ($m->track_inventory && $m->stock_qty !== null) {
                $out['stock'] = (int) $m->stock_qty;
                $out['in_stock'] = (int) $m->stock_qty > 0;
            }
            if (! empty($cfg['age_gate'])) {
                $out['age_restricted'] = (bool) $m->age_restricted;
            }
            if (! empty($cfg['expiry']) && $m->expiry_date !== null) {
                $out['expiry_date'] = $m->expiry_date->toDateString();
            }
            if (! empty($cfg['fitment'])) {
                $out['fitments'] = $m->fitments->map(fn ($f) => [
                    'year' => $f->year, 'make' => $f->make, 'model' => $f->model, 'label' => $f->label,
                ])->values()->all();
            }
        }

        return $out;
    }

    /**
     * validateCart — SERVER-SIDE pricing. The agent sends ids + quantities only;
     * we recompute every figure from the DB. Returns line totals + problems[].
     *
     * Coupons & Wallets P7-2a: an optional owner `coupon_code` is resolved
     * server-side (food_order surface) for a PREVIEW discount — the same
     * CouponService the placed order uses, so the quoted discount matches what
     * create_order will apply. No store credit here (preview; credit is
     * account-bound and applied only at order time for an identified customer).
     */
    public static function validateCart(Store $store, array $items, ?string $couponCode = null): array
    {
        $lines = [];
        $problems = [];
        $subtotal = 0.0;
        // P1-05 10b: the quote reads back what the order will total — in the store currency's own decimals (the
        // order total is not cut to 2 decimals, so a KWD quote must keep its fils).
        $exp = Currencies::exponent(store_currency((int) $store->id));

        foreach ($items as $i => $line) {
            $menu = Menu::where('store_id', $store->id)->find($line['item_id'] ?? 0);
            if (! $menu) {
                $problems[] = 'Item #'.($line['item_id'] ?? '?').' not found.';

                continue;
            }
            if ($menu->is_sold_out) {
                $problems[] = "'{$menu->getRawOriginal('name')}' is sold out.";

                continue;
            }

            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $price = (float) $menu->price;
            if (! empty($line['variation'])) {
                $variant = $menu->variants->firstWhere('id', (int) $line['variation']);
                if (! $variant) {
                    $problems[] = "Invalid variation for '{$menu->getRawOriginal('name')}'.";

                    continue;
                }
                $price = (float) $variant->price;
            }
            /* PARITY-RUN T5: the quote must use the SAME scheduled price the
               order path will snapshot, or the agent reads a total back to the
               customer that the bill then contradicts. */
            $price = MenuScheduleService::quote($menu, $price)['price'];
            $lineTotal = $price * $qty;
            foreach ((array) ($line['extras'] ?? []) as $exId) {
                $extra = $menu->resolved_extras->firstWhere('id', (int) $exId);
                if ($extra) {
                    $lineTotal += (float) $extra->price * $qty;
                }
            }
            $subtotal += $lineTotal;
            $lines[] = ['item_id' => $menu->id, 'name' => $menu->getRawOriginal('name'), 'quantity' => $qty, 'line_total' => Money::round($lineTotal, $exp)];
        }

        $opts = store_options($store->id);

        // P7-2a: server-priced coupon preview (owner coupon, food_order surface).
        $discount = 0.0;
        $couponApplied = false;
        $couponMessage = null;
        $code = $couponCode !== null ? trim($couponCode) : '';
        if ($code !== '') {
            $res = (new CouponService)->resolve($code, [
                'surface' => 'food_order',
                'owner_id' => $store->user_id,
                'subtotal' => $subtotal,
                'branch_id' => $store->branch_id ?? null,
                'currency' => store_currency((int) $store->id), // bug batch 4 · 2: as the order rounds it
            ]);
            if (! empty($res['ok'])) {
                $discount = (float) $res['discount'];
                $couponApplied = true;
                $couponMessage = $res['message'] ?? null;
            } else {
                $couponMessage = $res['message'] ?? 'Coupon code is invalid.';
            }
        }

        // P6-271: tax on the subtotal after the coupon, as the order charges it
        $tax = Order::computeTax(max(0.0, $subtotal - $discount), $opts);
        $total = Money::round($subtotal + $tax - $discount, $exp);

        // AO5 #27/#28: flag large/odd carts so the agent reads back a summary and
        // gets an explicit yes before createOrder. The summary is server-built
        // from the re-priced lines (no model-invented figures).
        $qtyTotal = array_sum(array_column($lines, 'quantity'));
        $needsConfirmation = $qtyTotal >= self::CONFIRM_QTY_TOTAL || $subtotal >= self::CONFIRM_SUBTOTAL;
        $summary = implode('; ', array_map(
            fn ($l) => $l['quantity'].'× '.$l['name'].' ('.$l['line_total'].')',
            $lines
        ));

        return [
            'lines' => $lines,
            'subtotal' => Money::round($subtotal, $exp),
            'tax' => $tax,
            'discount' => Money::round($discount, $exp),
            'coupon_applied' => $couponApplied,
            'coupon_message' => $couponMessage,
            'total' => $total,
            'problems' => $problems,
            'needs_confirmation' => $needsConfirmation,
            'confirmation' => $summary === '' ? '' : ($summary.' — total '.$total),
        ];
    }

    /** Is agent ordering enabled for this restaurant's plan? */
    public static function orderingEnabled(Store $store): bool
    {
        $owner = User::find($store->user_id);

        return $owner && (plan_feature('ai_chatbot', $owner) || plan_feature('agent_api', $owner));
    }

    /**
     * createOrder — the ONLY mutating tool. UNPAID order via OrderApiService
     * (source='agent'), takeaway/on-table only (no delivery, §DECISIONS), capped.
     * Refuses when ordering is disabled. Returns order no + track_token + continue_url.
     */
    public static function createOrder(Store $store, array $items, array $meta = [], ?string $sessionPhone = null, string $source = 'agent', ?int $payLinkMinutes = null): array
    {
        // Source is caller-controlled (never from $meta, which is model input):
        // 'agent' (chat/MCP) or 'storefront_api' (P1-7 REST order-write).
        $source = in_array($source, ['agent', 'phone', 'storefront_api'], true) ? $source : 'agent'; // P6-09 V-15: 'phone' = AI phone agent
        // Gate is source-aware: the agent flow requires ai_chatbot/agent_api
        // (spec §0.3 invariant, unchanged); the storefront REST flow requires the
        // storefront_api plan feature. Never weaken the agent gate.
        $gateOk = $source === 'storefront_api'
            ? (bool) plan_feature('storefront_api', $store->user)
            : self::orderingEnabled($store);
        if (! $gateOk) {
            return ['ok' => false, 'error' => 'Online ordering is not available here right now.'];
        }
        if (count($items) === 0 || count($items) > self::MAX_ITEMS_PER_ORDER) {
            return ['ok' => false, 'error' => 'Order size is out of range.'];
        }
        foreach ($items as $line) {
            if ((int) ($line['quantity'] ?? 1) > self::MAX_QTY_PER_LINE) {
                return ['ok' => false, 'error' => 'Quantity too high for one item.'];
            }
        }

        // Validate (server-side pricing) before creating; refuse on any problem.
        $check = self::validateCart($store, $items);
        if (! empty($check['problems'])) {
            return ['ok' => false, 'error' => implode(' ', $check['problems'])];
        }

        // Food stores keep the agent channel's takeaway/on-table constraint
        // (§DECISIONS). A non-food store accepts its own fulfillment modes so a
        // ship/pickup_slot/appointment order is created with the right type
        // instead of being silently coerced to 'takeaway'.
        if ($store->isFoodVertical()) {
            $allowedTypes = ['takeaway', 'on-table'];
            $defaultType = 'takeaway';
        } else {
            $allowedTypes = app(FulfillmentRegistry::class)->modesFor($store->store_type);
            $defaultType = $allowedTypes[0] ?? 'takeaway';
        }
        $type = in_array($meta['type'] ?? null, $allowedTypes, true) ? $meta['type'] : $defaultType;

        // Coupons & Wallets P7-2b: an agent may pass a coupon_code — applied
        // server-side by OrderApiService via the shared concern.
        $couponCode = isset($meta['coupon_code']) ? trim((string) $meta['coupon_code']) : '';

        // Decision B (merchant-configurable): an agent spends a customer's store
        // credit ONLY when the merchant opts in (`agent_wallet_spend`) AND the
        // diner is identified by their TRUSTED sender phone ($sessionPhone from the
        // verified SMS/WhatsApp channel) — never a model-supplied phone. A null
        // identity (web / MCP, unverified) never spends credit, even when the
        // toggle is on. Off by default → the safe posture is unchanged.
        $useWallet = false;
        $creditCustomerId = null;
        if ($sessionPhone !== null && $sessionPhone !== '' && app(AgentPolicyStore::class)->walletSpendAllowed((int) $store->id)) {
            $cust = Customer::where('store_id', $store->id)
                ->where('phone', $sessionPhone)->first();
            if ($cust) {
                $useWallet = true;
                $creditCustomerId = $cust->id;
            }
        }

        try {
            $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
                'type' => $type,
                'table_number' => $type === 'on-table' ? ($meta['table_number'] ?? null) : null,
                // OrderApiService nulls this for modes that don't need an address.
                'address' => $meta['address'] ?? null,
                'customer_name' => $meta['customer_name'] ?? null,
                'phone' => $meta['phone'] ?? null,
                'customer_id' => $creditCustomerId,
                'client_uuid' => $meta['client_uuid'] ?? null,
                'source' => $source,
                'coupon_code' => $couponCode !== '' ? $couponCode : null,
                'use_wallet' => $useWallet,
                // T1: optional pre-order time (validated server-side in OrderApiService).
                'scheduled_at' => isset($meta['scheduled_at']) ? (string) $meta['scheduled_at'] : null,
                'items' => $items,
                // P6-09 V-15 (server-set only, never from tool args): a phone order waits `unpaid` — out of the
                // kitchen — until its hosted pay link is paid; cancelled when the window ends unpaid.
                'awaiting_payment' => $payLinkMinutes !== null,
                'payment_mode' => $payLinkMinutes !== null ? 'hosted' : null,
                'payment_window_minutes' => $payLinkMinutes,
            ]);
        } catch (\InvalidArgumentException $e) {
            // Scheduling rule violation (bad time/closed/full) → clean tool error.
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (ClientOrderConflict $e) {
            // J27-A2: this client_uuid already placed a different cart — no second order.
            return ['ok' => false, 'error' => $e->getMessage(), 'conflict' => true];
        }

        return [
            'ok' => true,
            'order_id' => $order->id,
            'total' => $order->total,
            // P1-05 10b: stored tenders read back in the order's own currency.
            'discount' => Money::round((float) ($order->discount_amount ?? 0), Currencies::exponent($order->minorMoneyCurrency())),
            'coupon_applied' => $order->coupon_id !== null,
            'wallet_applied' => Money::round((float) ($order->wallet_applied ?? 0), Currencies::exponent($order->minorMoneyCurrency())),
            'scheduled_at' => $order->scheduled_at?->format('Y-m-d H:i'),
            'track_token' => $order->track_token,
            'continue_url' => url('/api/v1/track/'.$order->track_token),
            // Human tracking page — what the agent should quote to diners.
            // continue_url stays the machine-readable API contract.
            'track_url' => url('/order/track/'.$order->track_token),
            // ZERO-TOUCH Track C seam (C→A): nullable prep-time estimate in
            // minutes ("20-30"). ADDITIVE — chat/MCP confirmation cards render
            // it when present; absent estimate changes nothing else.
            'eta_range' => EtaEstimator::estimate($store, count($items))?->toApiString(),
        ];
    }

    public static function orderStatus(Store $store, string $trackToken): ?array
    {
        $order = Order::where('store_id', $store->id)->where('track_token', $trackToken)->first();
        if (! $order) {
            return null;
        }

        return ['order_id' => $order->id, 'status' => $order->status, 'is_paid' => (bool) $order->is_paid, 'total' => $order->total];
    }

    public static function restaurantInfo(Store $store): array
    {
        // rev/mv-mcp-parity: advertise the store's OWN fulfillment modes, not a
        // hardcoded food pair — a shop offers ship/pickup_slot, a service offers
        // appointments. Falls back to the food pair for a legacy/unknown type.
        $modes = app(FulfillmentRegistry::class)->modesFor($store->store_type);
        if ($modes === []) {
            $modes = ['takeaway', 'on-table'];
        }

        return [
            'id' => $store->id,
            'name' => $store->title,
            'phone' => $store->phone,
            'address' => $store->address,
            'store_type' => $store->store_type ?: 'restaurant',
            'order_types' => $modes,
            'payment' => 'Orders are placed unpaid; the customer pays on arrival, at the counter, or on delivery.',
        ];
    }

    /**
     * AO4 — grounded dietary answer. Answers ONLY from the item's structured
     * dietary_tags; never guesses. Returns 'yes' | 'no' | 'unknown' so the agent can
     * say "I'm not sure — please check with staff" instead of hallucinating.
     */
    public static function dietaryAnswer(Store $store, int $itemId, string $tag): string
    {
        $menu = Menu::where('store_id', $store->id)->find($itemId);
        if (! $menu) {
            return 'unknown';
        }
        $tags = $menu->dietary_tags;
        if (! is_array($tags) || ! array_key_exists($tag, $tags)) {
            return 'unknown'; // no data → refuse to guess
        }

        return $tags[$tag] ? 'yes' : 'no';
    }

    /**
     * AO2 — suggest add-ons for the current cart (top-ROI feature). Rule-based: active,
     * in-stock items from THIS restaurant not already in the cart, cheapest-first, cap 3.
     * Restaurant-scoped; prices are server-sourced, never invented.
     */
    public static function suggestUpsell(Store $store, array $cartItemIds = []): array
    {
        // P1-17: the Menu recommendation engine (agent channel) picks the ids; this only shapes the tool output.
        $recs = app(Recommendations::class)
            ->recommend($store, Recommendations::CHANNEL_AGENT, $cartItemIds);
        if ($recs === []) {
            return [];
        }
        $byId = Menu::where('store_id', $store->id)
            ->whereIn('id', array_map(fn ($r) => $r->itemId, $recs))
            ->get(['id', 'name', 'price_minor', 'currency_code'])->keyBy('id');

        return collect($recs)->map(fn ($r) => $byId->get($r->itemId))->filter()->map(fn ($m) => [
            'item_id' => $m->id,
            'name' => $m->getRawOriginal('name'),
            'price' => (float) $m->price,
        ])->values()->all();
    }

    /**
     * Tool JSON-schemas — defined ONCE, exported to both the MCP tool list and the
     * AiService function-calling defs. Prices are never a parameter (server-computed).
     */
    /**
     * AO3 #16: a customer's recent orders at THIS restaurant, matched by phone.
     * Read-only + tenant-scoped; the phone is the customer key the agent collects.
     */
    public static function orderHistory(Store $store, string $phone, int $limit = 5): array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return [];
        }

        return Order::where('store_id', $store->id)
            ->where('phone_number', $phone)
            ->with('items.menu')
            ->orderByDesc('id')->limit(max(1, min(10, $limit)))->get()
            ->map(fn ($o) => [
                'order_no' => $o->id,
                'date' => optional($o->created_at)->toDateString(),
                'total' => $o->total,
                'items' => $o->items->map(fn ($it) => [
                    'name' => optional($it->menu)->getRawOriginal('name') ?? '—',
                    'qty' => (int) $it->quantity,
                ])->values()->all(),
            ])->values()->all();
    }

    /**
     * Coupons & Wallets P7-2c: a returning diner's store-credit balance at this
     * restaurant, matched by phone. Store credit is account-bound (D3), so it is
     * safe to read out to a diner who has identified themselves by phone — this is
     * a READ only. Agents never SPEND credit (decision B); a diner pays with
     * credit on the storefront / customer app. Returns found + balance + currency.
     */
    public static function storeCreditBalance(Store $store, string $phone): array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return ['found' => false, 'balance' => 0.0];
        }

        $customer = Customer::where('store_id', $store->id)
            ->where('phone', $phone)->first();
        if (! $customer) {
            return ['found' => false, 'balance' => 0.0];
        }

        return [
            'found' => true,
            'balance' => CustomerWalletService::balanceFor($customer), // P1-05 10b: already rounded in the wallet's currency
            'currency' => store_options($store->id, 'currency_code'),
        ];
    }

    /**
     * AO3 #15: "the usual" — the customer's most recent order rebuilt as a cart
     * draft (ids + quantities), ready for validate_cart/create_order. Sold-out or
     * removed items are skipped so the reorder never references stale menu ids.
     */
    public static function reorderLast(Store $store, string $phone): array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return ['found' => false, 'items' => []];
        }

        $order = Order::where('store_id', $store->id)
            ->where('phone_number', $phone)
            ->with('items')->orderByDesc('id')->first();
        if (! $order) {
            return ['found' => false, 'items' => []];
        }

        return ['found' => true, 'from_order' => $order->id, 'items' => self::draftCartFromOrder($order)];
    }

    /**
     * Rebuild a past order as a cart draft ([{item_id, quantity}]), skipping any
     * item that is no longer active or is sold out — so a reorder never references
     * a stale menu id. Shared by reorderLast (agent, by phone) and the Customer
     * Account MCP reorder (P3-3, by order id).
     */
    public static function draftCartFromOrder(Order $order): array
    {
        $items = [];
        foreach ($order->items as $it) {
            $menu = Menu::where('store_id', $order->store_id)->where('active', '1')->find($it->item_id);
            if (! $menu || $menu->is_sold_out) {
                continue;
            } // never reorder a stale/unavailable item
            $items[] = ['item_id' => $menu->id, 'quantity' => (int) $it->quantity];
        }

        return $items;
    }

    /** AO8 #42: kitchen-load → quoted wait. Tunable so the estimate can be calibrated. */
    public const ETA_BASE_MIN = 5;    // minimum quote even with an empty kitchen

    public const ETA_PER_ITEM = 2;    // added minutes per open kitchen item

    public const ETA_MAX_MIN = 90;   // never quote longer than this

    /**
     * AO8 #42: quote a prep ETA from the CURRENT kitchen load so the agent can set
     * expectations at order time. Load = open OrderItems (queued/preparing) for the
     * restaurant; eta = base + load × per-item, capped. Read-only + tenant-scoped.
     */
    public static function orderEta(Store $store): array
    {
        $load = OrderItem::whereHas('order', fn ($q) => $q->where('store_id', $store->id))
            ->whereIn('status', ['queued', 'preparing'])
            ->count();

        $eta = min(self::ETA_MAX_MIN, self::ETA_BASE_MIN + $load * self::ETA_PER_ITEM);

        return ['eta_minutes' => $eta, 'kitchen_load' => $load];
    }

    /**
     * P1-16: the chat channels' tool schemas (web chat, WhatsApp, SMS, voice), read from the shared
     * AgentToolRegistry — defined once in StorefrontTools.
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public static function toolSchemas(): array
    {
        $out = [];
        foreach (app(AgentToolRegistry::class)->tools(AgentTool::STOREFRONT, AgentTool::CHANNEL_CHAT) as $tool) {
            $out[] = ['name' => $tool->name, 'description' => $tool->description, 'parameters' => $tool->inputSchema];
        }

        return $out;
    }
}
