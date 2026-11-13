<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreTable;
use App\Services\Verticals\FulfillmentRegistry;
use App\Support\OrderCreatedWebhookPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Core\Support\OwnerPwa;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Contracts\QuoteItems;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Menu\Services\Combos\ComboService;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Juwri\Modules\Ordering\Jobs\SendStaffOrderPushJob;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Pos\Services\Printing\PrintDispatcher;

/**
 * Session-free order builder for the public API. The dashboard's
 * StoreController@submitOrder is deeply coupled to the HTTP request, cookies and
 * the customer session, so the API cannot reuse it — this service builds the
 * same Order + OrderItem + OrderItemExtra rows from a plain array and fires the
 * `order.created` webhook, with no request/session dependency.
 *
 * Line prices are not snapshotted (Order->total re-derives from the live Menu),
 * matching the existing dashboard behaviour.
 */
class OrderApiService
{
    private const TYPES = ['on-table', 'takeaway', 'delivery', 'room_service'];

    /**
     * PARITY-RUN T5: channels where the person choosing the item is the GUEST.
     * Only these have scheduled availability enforced — see resolveLines().
     * `pos`, `staff_pwa`, `api` and every aggregator source are staff/partner
     * channels and deliberately bypass it.
     */
    // Customer-driven channels where item availability (active/sold-out/schedule) is
    // enforced server-side. 'kiosk' is included: a client `kiosk_mode` flag must not let a
    // remote customer skip the schedule/publish gates (a real kiosk shows the same menu).
    // P3-08 bug fix: `storefront_api` (the headless storefront API, a customer channel) was missing — its orders
    // skipped the guest availability and pharmacy sales rules at creation (only its quote applied them).
    private const GUEST_SOURCES = ['qr', 'customer_app', 'agent', 'phone', 'marketplace', 'kiosk', 'storefront_api']; // P6-09 V-15: + phone (AI phone agent)

    /**
     * @param  int  $ownerId  Restaurant owner (for webhook routing).
     * @param  int  $storeId  Store id the order belongs to.
     * @param  array  $data  Validated order payload.
     */
    /**
     * T6-2: validate + price posted choice-group selections for one line.
     * Input: [{group_id, selected:[label, …]}, …]. Returns [snapshot, unitPrice]
     * where snapshot = [{group_id, title, selected:[{option, price}]}] (title+price
     * snapshotted). Throws a 422 on an unknown group, an option not in the group,
     * or a required/min/max violation. No posted options → [[], 0.0].
     *
     * @param  list<int>  $answeredGroupIds  P3-08: groups a variant cell already answers (its axes: not re-priced)
     */
    public static function resolveOptions(Menu $menu, array $postedOptions, $i, array $answeredGroupIds = []): array
    {
        /* P3-08: the axes of the line's matrix cell (a colour × size variant: {group id: option index}) are answered
           by the cell itself — its price replaces the item's and the option is not priced again — so they are left
           out here (no minimum, no snapshot). */
        $answered = array_map('intval', $answeredGroupIds);
        $groups = $menu->resolved_variant_options->reject(fn ($g) => in_array((int) $g->id, $answered, true))->values(); // Collection<MenuVariantOption>

        // Index posted selections by group id.
        $posted = [];
        foreach ($postedOptions as $g) {
            $gid = (int) ($g['group_id'] ?? 0);
            $posted[$gid] = array_values((array) ($g['selected'] ?? []));
        }

        foreach ($answered as $gid) {
            unset($posted[$gid]); // the cell's own choice; never priced on top of the cell
        }

        // A posted group must belong to this item.
        foreach (array_keys($posted) as $gid) {
            if (! $groups->contains('id', $gid)) {
                throw ValidationException::withMessages(["items.$i.options" => 'Invalid option group for this item.']);
            }
        }

        $snapshot = [];
        $unitPrice = 0.0;

        foreach ($groups as $grp) {
            $labels = array_values((array) json_decode(json_encode($grp->options), true)); // normalise object/array → list
            $prices = (array) ($grp->option_prices ?? []);
            $sel = $posted[$grp->id] ?? [];
            $count = count($sel);

            $min = $grp->required ? max(1, (int) ($grp->min_select ?? 0)) : (int) ($grp->min_select ?? 0);
            $max = (int) ($grp->max_select ?? 0); // 0/null = unlimited

            if ($count < $min) {
                throw ValidationException::withMessages(["items.$i.options" => "Choose at least {$min} for “{$grp->title}”."]);
            }
            if ($max > 0 && $count > $max) {
                throw ValidationException::withMessages(["items.$i.options" => "Choose at most {$max} for “{$grp->title}”."]);
            }

            $selSnap = [];
            foreach ($sel as $label) {
                $idx = array_search($label, $labels, true);
                if ($idx === false) {
                    throw ValidationException::withMessages(["items.$i.options" => "Invalid option for “{$grp->title}”."]);
                }
                $price = (float) ($prices[$idx] ?? 0);
                $selSnap[] = ['option' => $label, 'price' => $price];
                $unitPrice += $price;
            }

            if ($selSnap) {
                $snapshot[] = ['group_id' => (int) $grp->id, 'title' => $grp->title, 'selected' => $selSnap];
            }
        }

        // P6-258: KWD/BHD/JOD option prices keep 3 decimals.
        return [$snapshot, money_round($unitPrice, money_decimals(store_currency((int) $menu->store_id)))];
    }

    /**
     * Validate + resolve raw item lines (tenant/orderable checks, variation, extras,
     * choice-group snapshot). Shared by create() and appendItems(). Throws on any
     * bad line so the whole batch fails rather than silently dropping items.
     */
    protected static function resolveLines(int $storeId, array $lines, ?string $source = null, ?string $orderType = null): array
    {
        /* P6-148: the order's items are read once for all its lines (one store-scoped query, each relation the pricing
           reads one query for all of them) instead of an item + choice-group + add-on read per line. A fresh read for
           this order: the scope opens empty here and closes when the lines are resolved (inside a quote, an open
           scope already holds the cart's items, as before). */
        $items = app(QuoteItems::class);

        return $items->during(function () use ($items, $storeId, $lines, $source, $orderType) {
            $items->primeIds($storeId, array_map(fn ($l) => is_array($l) ? ($l['item_id'] ?? 0) : 0, array_values($lines)));

            return self::resolveLinesNow($storeId, $lines, $source, $orderType);
        });
    }

    /**
     * @param  array<int|string, mixed>  $lines
     * @return list<array<string, mixed>>
     */
    private static function resolveLinesNow(int $storeId, array $lines, ?string $source, ?string $orderType): array
    {
        /* PARITY-RUN T5: scheduled availability is enforced for GUEST-driven
           channels only. A guest must not be able to buy the breakfast menu at
           midnight, so those lines are rejected with a message rather than
           silently dropped. Staff and partner channels deliberately bypass it:
           a cashier ringing up an off-menu item is a legitimate override (the
           register badges it), and refusing an aggregator webhook would throw
           away a real, already-paid Zomato/Deliverect order over a clock. */
        $enforceAvailability = in_array((string) $source, self::GUEST_SOURCES, true);

        $resolved = [];
        foreach ($lines as $i => $line) {
            $menu = app(QuoteItems::class)->byId($storeId, $line['item_id'] ?? 0); // fresh outside a quote
            if (! $menu) {
                throw ValidationException::withMessages(["items.$i.item_id" => 'Menu item not found for this restaurant.']);
            }
            if ($menu->is_sold_out) {
                throw ValidationException::withMessages(["items.$i.item_id" => "'{$menu->getRawOriginal('name')}' is sold out."]);
            }
            if ($enforceAvailability
                && ! MenuScheduleService::isAvailable($menu, null, $orderType)) {
                throw ValidationException::withMessages([
                    "items.$i.item_id" => "'{$menu->getRawOriginal('name')}' is not available right now.",
                ]);
            }
            // A customer must not be able to order an UNPUBLISHED (active=0) item by posting
            // its id — the storefront hides it, but resolveLines only checked is_sold_out +
            // schedule. Staff/owner/aggregator channels keep the deliberate override.
            if ($enforceAvailability && (string) $menu->getRawOriginal('active') === '0') {
                throw ValidationException::withMessages([
                    "items.$i.item_id" => "'{$menu->getRawOriginal('name')}' is not available right now.",
                ]);
            }

            $variation = 0;
            $variant = null; // T5: must reset per line — a leaked previous
            // variant would price the next line off the wrong base.
            if (! empty($line['variation'])) {
                // P4-16b: inside a quote the item comes with its variants loaded (QuoteItems) — the same row.
                $variant = $menu->relationLoaded('variants') && (is_int($line['variation']) || (is_string($line['variation']) && ctype_digit($line['variation'])))
                    ? $menu->variants->first(fn (MenuVariant $v) => (int) $v->id === (int) $line['variation'])
                    : MenuVariant::where('menu_id', $menu->id)->find($line['variation']);
                // A hidden/discontinued variant (active=0) must not be orderable by a
                // customer — its price REPLACES the base, so it can't be sold off-menu.
                if (! $variant || ($enforceAvailability && ! $variant->active)) {
                    throw ValidationException::withMessages(["items.$i.variation" => 'Invalid variation for this item.']);
                }
                $variation = (int) $variant->id;
            }
            // P3-08: a customer cannot order more of a stock-tracked cell (or item) than it has; the race between two
            // shoppers for the last units is settled by the conditional take in persistLines().
            $wanted = max(1, (int) ($line['quantity'] ?? 1));
            if ($enforceAvailability && $variant !== null && $variant->stock_qty !== null && (int) $variant->stock_qty < $wanted) {
                throw ValidationException::withMessages(["items.$i.stock" => ___(':item does not have enough stock left.', ['item' => (string) $menu->getRawOriginal('name')])]);
            }
            if ($enforceAvailability && $menu->track_inventory && $menu->stock_qty !== null && (int) $menu->stock_qty < $wanted
                && inventory_mode($storeId) !== 'advanced') {
                throw ValidationException::withMessages(["items.$i.stock" => ___(':item does not have enough stock left.', ['item' => (string) $menu->getRawOriginal('name')])]);
            }

            // Resolve extras against the ITEM'S OWN offered set (resolved_extras) rather
            // than a bare MenuExtra::find. find() had no restaurant scope and skipped the
            // ownership check for global modifiers (menu_id null), so a client could
            // attach a modifier the item does not offer — even another store's global
            // modifier — onto the ticket/KDS/receipt. resolved_extras is exactly what the
            // storefront rendered, and it also carries the per-item price_override, which
            // find() bypassed (charging the library base price instead of the price the
            // diner was quoted). Snapshot that effective price so computeMoney charges it.
            $offeredExtras = $menu->resolved_extras->keyBy('id');
            $extras = [];
            foreach ((array) ($line['extras'] ?? []) as $extraId) {
                $extra = $offeredExtras->get((int) $extraId);
                if (! $extra
                    || ($enforceAvailability && ! $extra->active)) { // no disabled extra for a customer
                    throw ValidationException::withMessages(["items.$i.extras" => 'Invalid extra for this item.']);
                }
                $extras[] = ['id' => (int) $extra->id, 'price' => (float) $extra->price];
            }

            // T6-2: choice-group (variant-option) selections. Validate each posted
            // group belongs to the item, enforce required/min/max, price from
            // option_prices (default 0) and SNAPSHOT title+price onto the line.
            [$optionsSnapshot, $optionsUnitPrice] = self::resolveOptions($menu, (array) ($line['options'] ?? []), $i, array_keys((array) $variant?->cell()));

            $resolved[] = [
                'menu' => $menu,
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'variation' => $variation,
                'variant' => $variant,              // T5: base for the scheduled price
                'extras' => $extras,
                'note' => isset($line['note']) ? (string) $line['note'] : null,
                'options' => $optionsSnapshot,      // JSON snapshot for order_items.options
                'options_unit' => $optionsUnitPrice,     // (unused downstream — total reads the snapshot)
            ];
        }

        // P1-23 (AUP 3.1): on a customer's channel a pharmacy sells only when published and within the per-order
        // cap of each medicine. Staff and partner channels (POS, aggregators) are the store's own in-person sales.
        if ($enforceAvailability && ($store = Store::find($storeId)) !== null) {
            $refusal = app(SalesRestrictions::class)->orderRefusal(
                $store,
                array_map(fn (array $r) => ['menu' => $r['menu'], 'quantity' => (int) $r['quantity']], $resolved)
            );
            if ($refusal !== null) {
                throw ValidationException::withMessages(['items' => $refusal]);
            }
        }

        return $resolved;
    }

    /**
     * Bug batch 7 · 4: an UNSAVED order carrying $lines exactly as persistLines() would price them — the line's
     * menu / variant, the scheduled (happy-hour) price snapshot, the item's own extras at their effective price and
     * the choice-group snapshot — in the store currency. Nothing is written, no stock moves. Used by the storefront
     * coupon pre-flight so a buy-X-get-Y preview runs CouponService::bogoDiscount() on the same lines (and so the
     * same sold unit prices) the placed order will carry. Lines are resolved as a guest order (source `qr`); an
     * invalid line → null (the caller keeps its line-less answer).
     *
     * P3-13: `$combos` (the storefront's posted combo picks) ride along as ComboService::persist() writes them — a
     * combo line priced by its options snapshot (bundle + upcharges) — so a quote of a cart with combos is the order's.
     * A combo pick ComboService refuses → null, like an invalid line.
     *
     * @param  list<array<string, mixed>>  $lines  OrderApiService line shape (item_id, quantity, variation, extras, options)
     * @param  array<int|string, mixed>  $combos  posted combo picks (ComboService::resolve() shape)
     */
    public static function previewOrder(int $storeId, array $lines, ?string $orderType = null, ?int $branchId = null, array $combos = []): ?Order
    {
        try {
            $resolved = self::resolveLines($storeId, $lines, 'qr', $orderType);
            $resolvedCombos = $combos !== [] ? ComboService::resolve($storeId, $combos, $branchId) : [];
        } catch (ValidationException) {
            return null;
        }

        $currency = strtoupper(store_currency($storeId));
        $order = (new Order)->forceFill(['store_id' => $storeId, 'currency_code' => $currency, 'type' => $orderType, 'branch_id' => $branchId]);
        $items = [];
        foreach ($resolved as $r) {
            $item = (new OrderItem)->forceFill(['currency_code' => $currency]); // the currency first: prices settle in it
            $item->forceFill(array_merge([
                'item_id' => $r['menu']->id,
                'quantity' => $r['quantity'],
                'variation' => $r['variation'],
                'options' => ! empty($r['options']) ? $r['options'] : null,
            ], MenuScheduleService::snapshotFor($r['menu'], $r['variant'] ?? null, $orderType, $branchId),
                self::weighedLine($storeId, $r['menu'], $r['options'] ?? null, (int) $r['quantity'], $currency)));
            $item->setRelation('menu', $r['menu']);
            $item->setRelation('variant', $r['variant'] ?? null);
            $item->setRelation('itemExtras', collect(array_map(
                fn (array $extra) => (new OrderItemExtra)->forceFill(['extra_id' => $extra['id'], 'price' => $extra['price']]),
                $r['extras']
            )));
            $item->setRelation('order', $order);
            $items[] = $item;
        }
        foreach ($resolvedCombos as $r) {
            $item = (new OrderItem)->forceFill(['currency_code' => $currency]);
            $item->forceFill(['item_id' => null, 'combo_id' => (int) $r['combo']->id, 'quantity' => (int) $r['quantity'], 'variation' => 0, 'options' => $r['options']]);
            $item->setRelation('menu', null);
            $item->setRelation('variant', null);
            $item->setRelation('itemExtras', collect());
            $item->setRelation('order', $order);
            $items[] = $item;
        }
        $order->setRelation('items', collect($items));

        return $order;
    }

    /**
     * P3-09 (REVIEW, Opus: money): a line of an item sold by weight. Its weight step (a required choice) is the whole
     * line price, so no base price is snapshotted (`unit_price` 0 replaces the per-kg item price in
     * Order::itemsSubtotal); the line keeps the grams per unit and its cap — the estimate × (1 + the store's cap %),
     * the most picking may charge for it (basket §9). Any other line: nothing (an empty array).
     *
     * @param  array<int, mixed>|null  $options  the line's choice-group snapshot
     * @return array<string, mixed>
     */
    public static function weighedLine(int $storeId, Menu $menu, ?array $options, int $quantity, string $currency): array
    {
        $grocery = app(GroceryCatalog::class);
        $grams = $grocery->stepGrams($menu, $options);
        if ($grams === null) {
            return [];
        }
        $stepMinor = 0;
        foreach ((array) $options as $group) {
            foreach ((array) (((array) $group)['selected'] ?? []) as $selected) {
                $stepMinor += Money::minorFromMajor((float) (((array) $selected)['price'] ?? 0), strtoupper($currency));
            }
        }
        $store = Store::query()->find($storeId);

        return [
            'unit_price' => 0,
            'weight_g' => $grams,
            'cap_minor' => $store !== null ? $grocery->capMinor($store, $stepMinor * max(1, $quantity)) : $stepMinor * max(1, $quantity),
        ];
    }

    /** Persist resolved lines onto an order (items + extras + stock + KDS station). */
    protected static function persistLines(Order $order, array $resolved, bool $held, int $storeId): void
    {
        // P3-08 (REVIEW, Opus: stock race): a customer's order takes its stock or is refused — never oversold.
        $strict = in_array((string) $order->source, self::GUEST_SOURCES, true);
        /* P6-148: each line (and add-on) used to read its order's store and currency back from the orders row (the
           tenant fill and the minor-money currency) — the same row for every line. Read it once and hand both to the
           rows, exactly the values those reads returned; nothing below changes them. */
        $orderRow = $resolved !== [] ? DB::table('orders')->where('id', $order->id)->first(['store_id', 'currency_code']) : null;
        $stamp = [];
        if ($orderRow !== null && ! empty($orderRow->store_id)) {
            $stamp['store_id'] = (int) $orderRow->store_id;
        }
        if ($orderRow !== null && ! empty($orderRow->currency_code)) {
            $stamp['currency_code'] = strtoupper((string) $orderRow->currency_code);
        }
        foreach (array_values($resolved) as $lineIndex => $r) {
            $menu = $r['menu'];
            $itemCourse = (int) ($menu->course ?? 1);
            $stationId = null;
            try {
                $stationId = KdsService::resolveStationForOrderItem($order->branch_id ?: null, $menu);
            } catch (\Throwable $e) {
            }

            /* PARITY-RUN T5: scheduled (happy-hour / day-part) price, resolved
               server-side at ORDER time and snapshotted onto the line — the same
               mechanism as the T6-2 choice-group snapshot below it, and for the
               same reason: a historical order must re-total to what was actually
               charged after the schedule changes or is deleted. This is the one
               place POS, kiosk, agent, public API and aggregator orders share. */
            $priceSnapshot = MenuScheduleService::snapshotFor(
                $menu,
                $r['variant'] ?? null,
                $order->type,
                $order->branch_id ?: null
            );

            $orderItem = OrderItem::create(array_merge($stamp, [
                'order_id' => $order->id,
                'item_id' => $menu->id,
                'station_id' => $stationId,
                'status' => 'queued',
                'course' => $itemCourse,
                'fired_at' => $itemCourse <= 1 ? now() : null,
                'quantity' => $r['quantity'],
                'variation' => $r['variation'],
                'order_note' => $r['note'],
                'options' => ! empty($r['options']) ? $r['options'] : null, // T6-2 snapshot
            ], $priceSnapshot, self::weighedLine($storeId, $menu, $r['options'] ?? null, (int) $r['quantity'], (string) ($order->currency_code ?: store_currency($storeId)))));

            foreach ($r['extras'] as $extra) {
                OrderItemExtra::create($stamp + [
                    'order_item_id' => $orderItem->id,
                    'extra_id' => $extra['id'],
                    'price' => $extra['price'], // effective (price_override-applied) snapshot
                ]);
            }

            // Deduct stock like the dashboard flow does — but NOT for a held/parked
            // order; deduction happens when it is resumed (see PosService::resume).
            if (! $held && $strict && inventory_mode($storeId) !== 'advanced') {
                /* P3-08: one conditional UPDATE per row (Menu / MenuVariant::reserveStock, `where stock_qty >= qty`),
                   which MySQL and PostgreSQL run under the row lock against the latest committed value: of two
                   shoppers racing for the last unit exactly one gets it. The other's whole order rolls back (we are
                   inside its transaction) with `items.N.stock` → 422 out_of_stock; nothing is taken for it. Lock
                   errors are not swallowed here (a PostgreSQL transaction is unusable after one). */
                $name = (string) $menu->getRawOriginal('name');
                if (! Menu::reserveStock((int) $menu->id, (int) $r['quantity'], $menu)
                    || ($r['variation'] && ! MenuVariant::reserveStock((int) $r['variation'], (int) $r['quantity']))) {
                    throw ValidationException::withMessages(["items.$lineIndex.stock" => ___(':item does not have enough stock left.', ['item' => (string) $name])]);
                }
            } elseif (! $held) {
                try {
                    if (inventory_mode($storeId) === 'advanced') {
                        InventoryService::deductForOrderItem((int) $menu->id, $r['quantity'], $order->id, $order->branch_id ?: null);
                    } else {
                        Menu::deductStock($menu->id, $r['quantity'], $menu);
                    }

                    // rev/multi-vertical Lane A2: a variant with its OWN stock_qty
                    // (the retail size×color matrix) decrements independently of
                    // the parent item's simple-mode stock. A null stock_qty (every
                    // legacy modifier/pizza-size variant) makes this a no-op.
                    if ($r['variation']) {
                        MenuVariant::deductStock((int) $r['variation'], $r['quantity']);
                    }
                } catch (\Throwable $e) {
                    /* H6: MySQL aborts the ENTIRE transaction on a deadlock (1213)
                       and may on a lock-wait timeout (1205). Swallowing those leaves
                       every subsequent statement failing against a dead transaction
                       and the caller seeing a confusing cascade instead of one
                       honest failure. Re-throw exactly those two so the order rolls
                       back cleanly; keep swallowing everything else — a missing
                       recipe or a deleted ingredient must still never fail an order.
                       sqlite never reports 1213/1205, so tests are unaffected. */
                    if ($e instanceof QueryException
                        && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Append items to an EXISTING unpaid order (POS "Add products" on an occupied
     * table). Reuses the same line resolution + persistence as create(), then
     * re-syncs the KDS ticket and fires an add-on KOT for the new lines. Never
     * touches a paid order.
     */
    public static function appendItems(Order $order, array $lines, array $combos = []): Order
    {
        if ($order->is_paid) {
            throw ValidationException::withMessages(['order' => 'A paid order cannot be modified.']);
        }
        if (empty($lines) && empty($combos)) {
            throw ValidationException::withMessages(['items' => 'At least one item is required.']);
        }

        // Capture the high-water mark BEFORE persisting so the add-on KOT can pick
        // out exactly the lines this append created (combo lines are order items too).
        $maxItemId = (int) OrderItem::where('order_id', $order->id)->max('id');

        $updated = DB::transaction(function () use ($order, $lines, $combos) {
            $resolved = self::resolveLines((int) $order->store_id, $lines);
            // T6: same server-side resolution as create(), so a POS "add products"
            // cannot smuggle in a combo price the storefront path would refuse.
            $resolvedCombos = ComboService::resolve((int) $order->store_id, $combos, $order->branch_id ?: null);
            $held = $order->held_at !== null;
            self::persistLines($order, $resolved, $held, (int) $order->store_id);
            ComboService::persist($order, $resolvedCombos, $held);

            if (! $held) {
                try {
                    KdsService::syncOrderStatus($order->id);
                } catch (\Throwable $e) {
                }
            }

            return Order::find($order->id);
        });

        if (! $updated->held_at) {
            $newIds = OrderItem::where('order_id', $order->id)->where('id', '>', $maxItemId)->pluck('id')->all();
            if ($newIds !== []) {
                try {
                    PrintDispatcher::onItemsAppended($updated, array_map('intval', $newIds));
                } catch (\Throwable $e) {
                }
            }
        }

        return $updated;
    }

    /**
     * Replace ALL items on an existing unpaid order (POS "Edit order"). Restores
     * quick stock for the current lines (mirrors void), removes them, then persists
     * the new set — inside one transaction. Paid orders are rejected.
     */
    public static function replaceItems(Order $order, array $lines, array $combos = []): Order
    {
        if ($order->is_paid) {
            throw ValidationException::withMessages(['order' => 'A paid order cannot be modified.']);
        }
        if (empty($lines) && empty($combos)) {
            throw ValidationException::withMessages(['items' => 'At least one item is required.']);
        }

        return DB::transaction(function () use ($order, $lines, $combos) {
            $held = $order->held_at !== null;
            // Validate the NEW set before removing anything (so a bad edit is a no-op).
            $resolved = self::resolveLines((int) $order->store_id, $lines);
            $resolvedCombos = ComboService::resolve((int) $order->store_id, $combos, $order->branch_id ?: null);

            $order->load('items.itemExtras');
            /* Restore stock for the outgoing lines, MODE-AWARE — mirrors
               PosService::restoreLineStock (and void). The old code called
               Menu::restoreStock directly, which is a no-op for advanced/recipe items
               (their stock is the ingredient ledger, not stock_qty). persistLines +
               ComboService::persist below re-deduct through the ingredient ledger in
               advanced mode, so a raw restore left the OLD lines' ingredients never
               reversed → every POS "Edit order" silently leaked ingredient stock.
               Advanced → reverse the ingredient ledger; simple → bump stock_qty;
               combos → per component. Gated on !held (a held line deducted nothing). */
            $advanced = inventory_mode((int) $order->store_id) === 'advanced';
            $branchId = $order->branch_id ?: null;
            $restore = function (int $menuId, int $qty) use ($advanced, $branchId, $order) {
                if ($menuId <= 0 || $qty <= 0) {
                    return;
                }
                try {
                    if ($advanced) {
                        InventoryService::reverseForOrderItem($menuId, $qty, $order->id, $branchId);
                    } else {
                        Menu::restoreStock($menuId, $qty);
                    }
                } catch (\Throwable $e) {
                    logger()->warning('[order] stock restore failed', ['menu_id' => $menuId, 'error' => $e->getMessage()]);
                }
            };
            foreach ($order->items as $item) {
                if (! $held) {
                    if ($item->isCombo()) {
                        foreach ($item->comboSnapshot()['components'] as $c) {
                            $restore((int) ($c['menu_id'] ?? 0), (int) $item->quantity);
                        }
                    } else {
                        $restore((int) $item->item_id, (int) $item->quantity);
                        // rev/multi-vertical Lane A2: mirror of the deduct side in
                        // persistLines() — restore the OUTGOING line's own variant
                        // stock (no-op for a variant with a null stock_qty).
                        if ((int) $item->variation) {
                            MenuVariant::restoreStock((int) $item->variation, (int) $item->quantity);
                        }
                    }
                }
                OrderItemExtra::where('order_item_id', $item->id)->delete();
                $item->delete();
            }

            self::persistLines($order, $resolved, $held, (int) $order->store_id);
            ComboService::persist($order, $resolvedCombos, $held);

            if (! $held) {
                try {
                    KdsService::syncOrderStatus($order->id);
                } catch (\Throwable $e) {
                }
            }

            return Order::find($order->id);
        });
    }

    public static function create(int $ownerId, int $storeId, array $data): Order
    {
        return self::createOrReplay($ownerId, $storeId, $data)[0];
    }

    /**
     * J27-A2: create() that also says whether the order came back from its client key (`client_uuid`) instead of
     * being created now — callers with their own after-create work (POS KDS sync, storefront tail) skip it on a
     * replay. A key resent with a DIFFERENT cart throws ClientOrderConflict (409), never a second order. The cart
     * fingerprint is `$data['client_order_hash']` when the entry point computed one from its own body, else the
     * type + item lines + combos of $data.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Order, 1: bool} [order, replayed]
     *
     * @throws ClientOrderConflict
     * @throws ValidationException an item, option or quantity the store does not take
     * @throws \InvalidArgumentException a scheduled time the store does not take
     */
    public static function createOrReplay(int $ownerId, int $storeId, array $data): array
    {
        // rev/multi-vertical: the food TYPES set is not the whole story — a
        // non-food store (retail/laundry/pharmacy…) accepts its own fulfillment
        // modes (ship/pickup_slot/appointment/in_store). Coercing those to
        // 'takeaway' (the old default) silently created the wrong order type.
        $store = Store::find($storeId);
        $registry = app(FulfillmentRegistry::class);
        $isFood = $store ? $store->isFoodVertical() : true;
        $verticalModes = $store ? $registry->modesFor($store->store_type) : [];
        $allowedTypes = $isFood
            ? array_values(array_unique(array_merge(self::TYPES, $verticalModes)))
            : $verticalModes;
        $default = $isFood ? 'takeaway' : ($verticalModes[0] ?? 'takeaway');
        $type = in_array($data['type'] ?? null, $allowedTypes, true) ? $data['type'] : $default;
        $lines = $data['items'] ?? [];

        /* H15: two post-commit side effects can be opted out by a caller that
           already fires its own equivalent. The storefront fold (StoreController::
           submitOrder) dispatches its own order.created webhook (a different payload
           shape — table_number, no track_token/source) and its own Web Push job
           (SendPushNotificationJob), so it suppresses these two to avoid double
           alerts. Default true, so no existing caller changes behaviour. KDS sync
           and PrintDispatcher deliberately get NO opt-out — closing those two gaps
           on the storefront is the whole point of the fold. */
        $sideEffects = (array) ($data['side_effects'] ?? []);
        $wantWebhook = ! array_key_exists('webhook', $sideEffects) || (bool) $sideEffects['webhook'];
        $wantStaffPush = ! array_key_exists('staff_push', $sideEffects) || (bool) $sideEffects['staff_push'];

        // Idempotency: a repeated client_uuid (retry / offline replay) returns the
        // already-created order instead of duplicating it. Scoped per restaurant.
        // J27-A2: unless the cart differs from the one that created it → 409.
        $clientHash = null;
        if (! empty($data['client_uuid'])) {
            $clientHash = $data['client_order_hash'] ?? ClientOrderId::fingerprint([
                'type' => $data['type'] ?? null,
                'items' => $lines,
                'combos' => $data['combos'] ?? [],
            ]);
            $existing = ClientOrderId::replay($storeId, (string) $data['client_uuid'], $clientHash);
            if ($existing) {
                return [$existing, true];
            }
        }

        /* Pricing follow-ups (PRICING.md §3): the store's own delivery needs a plan that includes it (a plan without
           the key keeps it). Orders a delivery partner brings and delivers itself (delivery-app connectors, the
           platform marketplace) set `delivery_by_partner` server-side — never from a request (no API validator
           accepts the key). Checked after the replay above, so a retry of an existing order still answers. */
        if ($type === 'delivery' && empty($data['delivery_by_partner']) && ! PlanGate::storeAllows($store, PlanGate::DELIVERY)) {
            throw ValidationException::withMessages(['type' => PlanGate::customerMessage(PlanGate::DELIVERY)]);
        }

        /* PARITY-RUN T6: combos ride the same payload. Resolved (validated +
           SERVER-priced) before anything is written, so a bad combo fails the
           whole order exactly like a bad item line does. An order may be combos
           only — "one Family Meal" is a complete order. */
        $combos = ComboService::resolve(
            $storeId,
            (array) ($data['combos'] ?? []),
            $data['branch_id'] ?? null
        );

        if (empty($lines) && empty($combos)) {
            throw ValidationException::withMessages(['items' => 'At least one item is required.']);
        }

        // Pre-validate every referenced menu item belongs to this restaurant and
        // is orderable. Fail the whole order rather than silently dropping lines.
        $resolved = self::resolveLines($storeId, $lines, $data['source'] ?? 'qr', $type);

        $order = new Order;
        $order->store_id = $storeId;
        $order->type = $type;
        $order->customer_name = $data['customer_name'] ?? null;
        $order->phone_number = $data['phone'] ?? null;
        // orders.table_number is an integer column: MySQL / PostgreSQL reject a non-numeric label.
        $tableLabel = $type === 'on-table' ? ($data['table_number'] ?? null) : null;
        $order->table_number = is_numeric($tableLabel) ? (int) $tableLabel : null;
        if ($tableLabel !== null && $tableLabel !== '' && ! is_numeric($tableLabel)) {
            $order->service_location = mb_substr((string) $tableLabel, 0, 120); // a non-numeric label ('T12') is kept here
        }
        $order->covers = $type === 'on-table' ? ($data['covers'] ?? null) : null; // guests/covers (dine-in)
        // Capture a shipping/delivery address for any mode that needs one:
        // legacy 'delivery' plus vertical modes like 'ship' (FulfillmentRegistry).
        $order->address = ($type === 'delivery' || $registry->needsAddress($type))
            ? ($data['address'] ?? null)
            : null;

        /* C8 — room-service orders from the API/agent/app path had no room: create()
           nulled table_number and never set service_location, so only the storefront's
           post-create forceFill repaired them and any other caller produced a
           location-less ticket the kitchen could not deliver. Resolve the room here,
           exactly as the storefront does (tenant + area_type='room', branch-aware and
           tolerating NULL-branch rooms via the `key`), and reject an unresolvable one.
           Charge-to-room stays out of the API v1 — this is the order binding only. */
        if ($type === 'room_service') {
            $roomKey = $data['room'] ?? ($data['room_number'] ?? null);
            $room = null;
            if ($roomKey !== null && $roomKey !== '') {
                $room = StoreTable::query()
                    ->where('store_id', $storeId)
                    ->where('area_type', 'room')
                    ->when(! empty($data['branch_id']), function ($q) use ($data) {
                        $bid = (int) $data['branch_id'];
                        $q->where(fn ($w) => $w->where('branch_id', $bid)->orWhereNull('branch_id'));
                    })
                    ->where(function ($q) use ($roomKey) {
                        $q->where('key', $roomKey);
                        if (ctype_digit((string) $roomKey)) {
                            $q->orWhere('id', (int) $roomKey);
                        }
                    })
                    ->first();
            }
            if (! $room) {
                throw ValidationException::withMessages(['room' => 'A valid room is required for a room-service order.']);
            }
            $order->service_location = mb_substr($room->label ?: (string) $room->table_no, 0, 120);
            $order->table_number = is_numeric($room->table_no) ? (int) $room->table_no : null; // keep the legacy int field populated for KDS/printing
        }
        // Delivery pin → order coordinates (same normalisation the storefront uses),
        // so dispatch has real coords on app/API orders too. Non-delivery = null.
        if ($type === 'delivery') {
            [$order->delivery_lat, $order->delivery_lng] = storefront_delivery_coords($data);
        }
        $order->branch_id = $data['branch_id'] ?? null;
        $order->customer_id = $data['customer_id'] ?? null;
        $order->tip_amount = $data['tip_amount'] ?? 0;
        $order->donation_amount = $data['donation_amount'] ?? 0; // T3-3 marketplace round-up
        // Bug batch 4 · 3: a SERVER-quoted delivery fee (CrossCartService: the marketplace zone fee the bag showed).
        // Never from a request — no API validator accepts this key. Absent → NULL → the store's own delivery charge.
        if ($type === 'delivery' && isset($data['delivery_fee_trusted']) && is_numeric($data['delivery_fee_trusted'])) {
            $order->delivery_fee = max(0.0, (float) $data['delivery_fee_trusted']);
        }
        /* P3-08: a ship order's shipping method, its SERVER-quoted fee (ShippingQuotes, the same option the quote
           priced) and its delivery window. Server-set only — no API validator accepts these keys. The fee is the
           order's delivery charge (Order::deliveryCharge), so the total keeps its one derivation. */
        if ($type === 'ship' && ! empty($data['shipping_method_id'])) {
            $order->shipping_method_id = (int) $data['shipping_method_id'];
            $order->delivery_fee = max(0.0, (float) ($data['shipping_fee_trusted'] ?? 0));
            $order->shipping_eta_from = $data['shipping_eta_from'] ?? null;
            $order->shipping_eta_to = $data['shipping_eta_to'] ?? null;
        }
        $order->message = $data['note'] ?? null; // order-level note (orders.message)
        $held = ! empty($data['held']);
        $order->status = 'pending';
        /* P3-06b (API-2, API-10): a headless order paid on the core-hosted payment page waits as `unpaid` (kept out
           of the kitchen, KdsService ignores it) until the gateway settles it (PaymentController::paySuccess), and is
           cancelled with its stock released when the payment window ends unpaid (orders:expire-hosted-payments).
           Server-set only: the headless API controller passes these, no request validator accepts them. */
        $awaitingPayment = ! empty($data['awaiting_payment']);
        if (isset($data['payment_mode']) && in_array($data['payment_mode'], ['pay_later', 'hosted'], true)) {
            $order->payment_mode = $data['payment_mode'];
        }
        if ($awaitingPayment) {
            $order->status = 'unpaid';
            $order->payment_expires_at = now()->addMinutes(max(1, (int) ($data['payment_window_minutes'] ?? config('api.storefront.hosted_window_minutes', 15))));
        }
        $order->delivery_status = $type === 'delivery' ? 'pending' : null;
        $order->track_token = self::uniqueTrackToken();
        $order->client_uuid = $data['client_uuid'] ?? null;
        $order->client_order_hash = $clientHash;
        $order->source = $data['source'] ?? 'qr';       // channel: qr|pos|customer_app|agent|api
        $order->created_by_staff_id = $data['created_by_staff_id'] ?? null;
        $order->service_charge_amount = $data['service_charge_amount'] ?? null;
        if (isset($data['discount_amount'])) {
            $order->discount_amount = (float) $data['discount_amount']; // POS manual discount
        }
        $order->held_at = $held ? now() : null;          // parked → KDS + inventory ignore it
        $order->is_paid = 0;
        $order->created_at = now();
        // T1: pre-order scheduling for the API/agent/POS path. Validated against the
        // restaurant's rules (enabled, lead, horizon, hours, capacity); ASAP if absent.
        $slotLock = null; // held across the INSERT below to make the per-slot capacity check atomic
        if (! empty($data['scheduled_at'])) {
            $scheduling = app(SchedulingService::class);
            $schedBranch = $order->branch_id ? Branch::find($order->branch_id) : null;
            $sched = $scheduling->validate($storeId, $schedBranch, $type, (string) $data['scheduled_at']);
            if ($sched) {
                $order->scheduled_at = $sched;
                $order->fire_at = $scheduling->computeFireAt($storeId, $sched);

                // Per-slot capacity was a check-then-insert race: validate() counted the
                // slot above, but the INSERT happens later, so two concurrent bookers could
                // both pass and overfill a limited pre-order window. lockSlot() serialises
                // THIS exact slot and re-checks occupancy while held; the lock is kept until
                // the order row commits (released in the finally on the transaction below).
                // The SAME helper guards the storefront pipelines, so the key can never drift.
                // Returns null (no-op release) for an unlimited-capacity store.
                $slotLock = $scheduling->lockSlot($storeId, $schedBranch, $sched);
            }
        } elseif (! empty($data['scheduled_at_trusted'])) {
            // Pre-validated future time (e.g. a catering event weeks out, beyond the
            // normal pre-order horizon). Set it BEFORE the KDS/push below so an
            // unreleased scheduled order does not fire the kitchen immediately.
            $scheduling = app(SchedulingService::class);
            $order->scheduled_at = Carbon::parse($data['scheduled_at_trusted']);
            $order->fire_at = $scheduling->computeFireAt($storeId, $order->scheduled_at);
        }
        /* H6: the order, its lines, its combos and its pricing write inside ONE
           transaction (see self::atomically + config platform.orders.atomic_create).
           Before this, a mid-flight throw left a committed orders row plus whatever
           lines survived, and because Order::getTotalAttribute() always re-derives,
           the KDS/printer/report all accepted a wrong-but-plausible total. The
           duplicate-uuid recovery returns normally with a `duplicate` flag so the
           caller does NOT re-fire the post-commit side effects for a row that
           already did. */
        try {
            $result = self::atomically(function () use (&$order, $resolved, $held, $storeId, $combos, $ownerId, $data, $clientHash) {
                // A2: the SELECT above narrows the window but two concurrent retries
                // with the same client_uuid can both pass it. The unique index
                // orders_rid_cuuid_uniq makes the loser's INSERT fail — catch it and
                // hand back the winning row instead of a 500.
                try {
                    // Savepoint: on PostgreSQL a failed insert would abort the enclosing transaction.
                    DB::transaction(fn () => $order->save());
                } catch (QueryException $e) {
                    if (! empty($data['client_uuid'])) {
                        $winner = Order::where('store_id', $storeId)
                            ->where('client_uuid', $data['client_uuid'])
                            ->first();
                        if ($winner) {
                            // J27-A2: the concurrent winner was created from another cart → 409.
                            ClientOrderId::assertSameCart($winner, $clientHash);

                            // H6: nothing was written; returning normally commits an
                            // empty transaction, and `duplicate` keeps the caller from
                            // re-firing KDS/webhook/push/print for an order that did.
                            return ['order' => $winner, 'duplicate' => true];
                        }
                    }
                    throw $e;
                }

                self::persistLines($order, $resolved, $held, $storeId);
                ComboService::persist($order, $combos, $held);

                // Fresh instance so the total accessor sees the persisted items.
                $fresh = Order::find($order->id);

                /* K3 (A1 fix): loyalty reward redemption MUST run BEFORE the coupon/
                   wallet/gift-card step below, so those TENDERS size against the
                   loyalty-reduced total. Applying loyalty AFTER the tenders (the old
                   H15 controller ordering) let a full-balance wallet cap at the
                   pre-loyalty total, then loyalty lowered `total` again → amount_due
                   went negative and store credit was over-drained. Redeeming INSIDE
                   this transaction also makes the point debit and the discount commit
                   or roll back together (the legacy path's guarantee). Opt-in via
                   $data['loyalty_redeem']; other create() callers pass nothing and are
                   unaffected. The reward is server-derived — never client-supplied. */
                if (! empty($data['loyalty_redeem'])) {
                    // The ONE implementation (OrderPricing::redeemLoyalty); P1-23: never on medicine lines.
                    OrderPricing::redeemLoyalty($fresh, (int) $storeId);
                }

                // Coupons & Wallets P7-1b: additive post-persist coupon + store-credit
                // step (the ONE shared concern, D1). Server-authoritative (D2);
                // account-bound credit (D3); idempotent per order (D4).
                if (! empty($data['coupon_code']) || ! empty($data['use_wallet']) || ! empty($data['gift_card_code'])) {
                    OrderPricing::applyCouponAndCredit($fresh, [
                        'coupon_code' => $data['coupon_code'] ?? null,
                        'owner_id' => $ownerId,
                        'customer_id' => $fresh->customer_id,
                        'branch_id' => $fresh->branch_id,
                        'use_wallet' => ! empty($data['use_wallet']),
                        'wallet_amount' => $data['wallet_amount'] ?? null,
                        'gift_card_code' => $data['gift_card_code'] ?? null, // T2 tender
                    ]);
                    $fresh->refresh();
                }

                /* P3-09: a server-side step that must commit with the order (the headless API binds the slot hold
                   and snapshots the out-of-stock choices here). A Closure is never request input. */
                if (isset($data['after_persist']) && $data['after_persist'] instanceof \Closure) {
                    ($data['after_persist'])($fresh);
                    $fresh->refresh();
                }

                /* H6: the snapshot moves INSIDE the transaction and UP from below.
                   Every line, combo, coupon and tender is persisted by this point and
                   nothing after the commit changes money (KDS sync writes
                   status/token_number, the rest are external), so the frozen figures
                   are identical — but now they cannot survive a rollback of the rows
                   they summarise. Still guarded: a snapshot failure must never fail
                   order creation. */
                try {
                    $fresh->snapshotTotals();
                } catch (\Throwable $e) {
                    report($e); // BL-049: never silent — a missing snapshot is a money bug
                }

                return ['order' => $fresh, 'duplicate' => false];
            });
        } catch (ClientOrderConflict $e) {
            throw $e; // a refused resend, not a failed create
        } catch (\Throwable $e) {
            self::recordCreateFailure((int) $ownerId, (string) ($data['source'] ?? 'api'));
            throw $e;
        } finally {
            // Release the per-slot capacity lock now that the order row is committed
            // (or the transaction rolled back). No-op when no lock was taken.
            optional($slotLock)->release();
        }

        if ($result['duplicate']) {
            return [$result['order'], true];
        }
        $order = $result['order'];

        // Make the order visible to the KDS: derive its ticket status ('new') from
        // the queued items. Without this an API/agent/customer order stays 'pending'
        // and never reaches the kitchen. Held orders stay out (KDS filters held_at).
        if (! $held) {
            try {
                KdsService::syncOrderStatus($order->id);
                $order->refresh();
            } catch (\Throwable $e) {
            }
        }

        // A held order has not really "happened" yet — defer the webhook to resume. P3-06b: neither has an order
        // waiting for its hosted payment — PaymentController::paySuccess announces it once paid (announceCreated()).
        if (! $held && ! $awaitingPayment) {
            self::announceCreated((int) $ownerId, (int) $storeId, $order, (string) ($data['source'] ?? 'api'), $wantWebhook, $wantStaffPush);
        }

        return [$order, false];
    }

    /**
     * The post-commit announcement of a new order: the partner `order.created` webhook, the staff PWA push (deferred
     * for a not-yet-fired scheduled order) and auto-print. P3-06b: split out of createOrReplay() so an order that
     * waited for its hosted payment is announced exactly once, when it is paid (PaymentController::paySuccess).
     */
    public static function announceCreated(int $ownerId, int $storeId, Order $order, string $source, bool $wantWebhook = true, bool $wantStaffPush = true): void
    {
        if ($wantWebhook) {
            try {
                WebhookService::dispatch($ownerId, 'order.created', OrderCreatedWebhookPayload::build($order, $source));
            } catch (\Throwable $e) {
            }
        }

        // SP1: notify the restaurant's staff PWA (scoped, queued).
        // T1: defer the alert for a not-yet-fired scheduled order to release time.
        if ($wantStaffPush && ! $order->isPendingSchedule()) {
            try {
                $pushPayload = [
                    'title' => 'New order #'.$order->id,
                    'order_id' => $order->id,
                    'source' => $source,
                ];
                // Owner PWA / native shell: where a tap should land (the store's Orders
                // tab, per vertical, focused on this order). `owner_url` so the staff
                // PWA's own `url` routing is untouched.
                $pushPayload['owner_url'] = OwnerPwa::orderUrl($storeId, (int) $order->id);
                $pushPayload['tag'] = 'order-'.$order->id;
                SendStaffOrderPushJob::dispatch($storeId, $pushPayload);
            } catch (\Throwable $e) {
            }
        }

        /* PARITY-RUN T1: auto-print. This is the ONE order-creation path, so
           hooking it here covers POS, QR storefront, agent/MCP, owner REST
           and every aggregator ingest at once — no forked order paths. The
           dispatcher renders receipts for `new_order` profiles and
           per-station KOT tickets for `kot` profiles, and is idempotent on
           its job key so a retry cannot produce a second kitchen ticket. */
        try {
            PrintDispatcher::onOrderCreated($order);
        } catch (\Throwable $e) {
        }
    }

    /**
     * H6: run an order write inside ONE transaction. Both creation paths call
     * this so the kill switch can never apply to one of them and not the other.
     *
     * NOTE the missing second argument: DB::transaction() defaults to ONE
     * attempt and must stay that way. The closure claims coupons, redeems
     * loyalty points and spends store credit; a deadlock retry would re-run all
     * of that, and the idempotency guards inside those services are keyed on
     * rows this transaction just rolled back. One attempt, one honest failure.
     *
     * @param  \Closure(): mixed  $fn
     */
    public static function atomically(\Closure $fn): mixed
    {
        return config('platform.orders.atomic_create', true) ? DB::transaction($fn) : $fn();
    }

    /**
     * H6: a rolled-back order leaves NO trace — which is correct, and is exactly
     * why it has to be counted. Two fire-and-forget counters: a per-tenant one the
     * merchant and reseller dashboards read, and a platform one the superadmin
     * reads. Wrapped in its own try/catch because the failure that got us here may
     * well be the database.
     */
    public static function recordCreateFailure(int $ownerId, string $channel): void
    {
        try {
            PlatformMetrics::record('order.create_failed', $channel);
            if ($ownerId > 0) {
                $resellerId = User::whereKey($ownerId)->value('reseller_id');
                TenantUsageDaily::bump($ownerId, 'orders_failed', 1, $resellerId ? (int) $resellerId : null);
            }
            Log::error('[order] creation rolled back', ['owner' => $ownerId, 'channel' => $channel]);
        } catch (\Throwable $e) {
        }
    }

    private static function uniqueTrackToken(): string
    {
        do {
            $token = Str::lower(Str::random(32));
        } while (Order::where('track_token', $token)->exists());

        return $token;
    }
}
