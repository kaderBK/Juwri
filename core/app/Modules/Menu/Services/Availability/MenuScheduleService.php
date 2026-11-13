<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Availability;

use App\Models\MenuCategory;
use App\Models\PriceSchedule;
use App\Services\FeatureAccess;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/**
 * PARITY-RUN T5: the ONE answer to "can this be ordered now, and for how much?"
 *
 * Every surface routes through here — the storefront, the POS register, the
 * public/agent order API, the Fusion marketplace and the order path itself — so
 * a diner, a cashier and the receipt can never disagree.
 *
 * ─── The two questions ───────────────────────────────────────────────────────
 *   isAvailable(Menu, ?at, ?orderType)  — item window ∧ category window ∧
 *                                          order-type scope
 *   quote(Menu, ?variant, ?at, ?orderType) — base price → scheduled price +
 *                                          the schedule and label behind it
 *
 * ─── The rules that are locked ───────────────────────────────────────────────
 *   • **Time is the STORE's.** Everything resolves against
 *     store_options(rid, 'timezone'), never the server clock and never the
 *     browser's. A Riyadh store's happy hour must not start when the Frankfurt
 *     web head says so.
 *   • **Schedules do not stack.** The single best (lowest resulting price) rule
 *     wins; ties break on `priority` then `id`. Stacking percentages is how
 *     restaurants accidentally sell at 0.
 *   • **Schedules apply BEFORE coupons.** A coupon lands in
 *     `orders.discount_amount`, which Order::getTotalAttribute() subtracts LAST
 *     — after tax. That ordering is already correct and this feature does not
 *     touch it: a schedule changes the item price (and therefore the taxable
 *     subtotal); a coupon discounts the finished bill.
 *   • **Schedules price the ITEM, not its add-ons.** The base price and the
 *     variant price are adjusted (a variant price REPLACES the base, so it is
 *     the same number for this purpose). Extras and choice-group options are
 *     never discounted — a half-price burger with full-price bacon is what
 *     every POS in this category does, and discounting modifiers makes a
 *     "−20%" rule impossible for an owner to reason about.
 *   • **The kill-switch is asked store-scoped.** `menu_schedules` is read via
 *     FeatureAccess::storeAllows(), NEVER as `feature:` route middleware — the
 *     storefront menu is reached by anonymous diners and that middleware would
 *     403 every one of them (the dead end recorded in FIX-1, hit 3× already).
 *     Killed feature ⇒ base prices and no availability gating, i.e. exactly
 *     pre-T5 behaviour, never a gate nobody can pass.
 */
class MenuScheduleService
{
    public const FEATURE = 'menu_schedules';

    /** Per-request memo: storeId => Collection<PriceSchedule> (active only). */
    private static array $scheduleMemo = [];

    /** Per-request memo: storeId => Collection<MenuCategory> keyed by id. */
    private static array $categoryMemo = [];

    /** Per-request memo: storeId => timezone string. */
    private static array $tzMemo = [];

    /** The application instance the memos above were filled for. */
    private static ?int $memoApp = null;

    /**
     * Bind the memos to the current application instance — the same guard
     * StoreOption uses for its option memo.
     *
     * These memos are keyed by restaurant id only, so nothing in them tells the
     * memo which DATABASE the ids came from. Under a test runner that is a
     * cross-test leak: every test boots a fresh app and RefreshDatabase hands
     * the next test the same low ids, so a store that had a −50% "Happy Hour"
     * PriceSchedule in one test kept discounting the unrelated store that
     * inherited its id in the next (POS totals came out halved, 24 -> 12). It
     * stayed invisible in the full suite only because some other class happened
     * to call flush() in between; a --filter subset that skipped that class
     * failed. Rebinding here makes every request — and every test — start from
     * an empty bucket, so correctness no longer depends on run order.
     */
    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            // Own buckets only — PriceSchedule guards its own, and clearing it
            // from here would drop a memo this app legitimately filled.
            self::$scheduleMemo = [];
            self::$categoryMemo = [];
            self::$tzMemo = [];
        }
    }

    /** Testing seam + a safety valve for long-running queue workers. */
    public static function flush(): void
    {
        self::$scheduleMemo = [];
        self::$categoryMemo = [];
        self::$tzMemo = [];
        PriceSchedule::flushDecimalsCache();
    }

    /* ───────────────────────────── gate + clock ──────────────────────────── */

    public static function enabled(?int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, self::FEATURE);
    }

    /** The store's own timezone, falling back to the app default. */
    public static function timezoneFor(?int $storeId): string
    {
        if (! $storeId) {
            return (string) config('app.timezone', 'UTC');
        }

        self::scopeMemo();

        return self::$tzMemo[$storeId] ??= (string) (
            store_options($storeId, 'timezone') ?: config('app.timezone', 'UTC')
        );
    }

    /** "Now" on the store's wall clock. */
    public static function nowFor(?int $storeId): Carbon
    {
        return Carbon::now(self::timezoneFor($storeId));
    }

    /**
     * The time bucket the current menu render belongs to, for cache keys.
     *
     * Menu payloads are cached in several places, and a cache keyed only by
     * store id would serve a breakfast menu at dinner. Five-minute buckets in
     * the STORE's timezone: a window that opens at 11:00 is live by 11:05 at
     * the latest, and the key changes 288×/day instead of 86,400×.
     */
    public static function cacheBucket(?int $storeId, int $minutes = 5): string
    {
        $now = self::nowFor($storeId);
        $slot = (int) floor(($now->hour * 60 + $now->minute) / max(1, $minutes));

        return $now->format('Y-m-d').'-'.$slot;
    }

    /* ─────────────────────────── availability ────────────────────────────── */

    /**
     * Is this item orderable right now?
     *
     * The item's own window AND its category's window must both be open, and
     * both must admit the order type. Sold-out is a separate, pre-existing gate
     * that callers already check — this method deliberately does not fold it in
     * so the two reasons stay distinguishable in the UI.
     */
    public static function isAvailable(Menu $menu, ?Carbon $at = null, ?string $orderType = null): bool
    {
        $rid = (int) $menu->store_id;

        if (! self::enabled($rid)) {
            /* Killed switch ⇒ pre-T5 behaviour. The item-level columns shipped
               long before this feature key existed and stores rely on them, so
               they keep gating; only the T5 additions (category windows,
               order-type scope) stand down. */
            return $menu->windowOpenNow(null, self::timezoneFor($rid));
        }

        $at = $at ?: self::nowFor($rid);

        if (! $menu->windowOpenNow($at, null, $orderType)) {
            return false;
        }

        $category = self::categoryFor($menu);

        return ! $category || $category->windowOpenNow($at, null, $orderType);
    }

    /**
     * When does a currently-closed item open again? Returns 'HH:MM' or null.
     * Used for the "available from 17:00" storefront state — the narrower of
     * the item's and its category's opening times, because both must be open.
     */
    public static function opensAt(Menu $menu): ?string
    {
        $times = [];
        foreach ([$menu, self::categoryFor($menu)] as $row) {
            if ($row && ! empty($row->available_from) && ! empty($row->available_to)) {
                $times[] = substr((string) $row->available_from, 0, 5);
            }
        }

        return $times ? max($times) : null;
    }

    /* ───────────────────────────── pricing ───────────────────────────────── */

    /**
     * Resolve the effective unit price for one line.
     *
     * @param  float|null  $base  the price BEFORE any schedule (branch override
     *                            or variant price already applied by the caller);
     *                            null = derive from the item.
     * @return array{price: float, base: float, schedule: ?PriceSchedule, label: ?string}
     */
    public static function quote(Menu $menu, $base = null, ?Carbon $at = null, ?string $orderType = null): array
    {
        $rid = (int) $menu->store_id;
        $base = $base === null ? (float) $menu->price : (float) $base;
        $none = ['price' => $base, 'base' => $base, 'schedule' => null, 'label' => null];

        if (! self::enabled($rid) || $base <= 0) {
            return $none;
        }
        // P1-23 follow-up: no scheduled, sale or happy-hour price on an item promotions may not touch (a medicine
        // of a pharmacy whose market blocks promotions). Every surface prices through here — display and order.
        if (app(PromotionRules::class)->blocksPromotions($menu)) {
            return $none;
        }

        $schedule = self::bestSchedule($menu, $base, $at ?: self::nowFor($rid), $orderType);
        if (! $schedule) {
            return $none;
        }

        $price = $schedule->apply($base);
        if ($price >= $base) {
            // Not actually cheaper — do not advertise a discount that is not one.
            return $none;
        }

        return [
            'price' => $price,
            'base' => $base,
            'schedule' => $schedule,
            'label' => $schedule->displayLabel(),
        ];
    }

    /**
     * The single winning schedule for an item: cheapest resulting price, then
     * highest `priority`, then highest id (the most recently created rule).
     */
    public static function bestSchedule(Menu $menu, float $base, Carbon $at, ?string $orderType = null): ?PriceSchedule
    {
        $best = null;
        $bestPrice = null;

        foreach (self::schedulesFor((int) $menu->store_id) as $schedule) {
            if (! self::covers($schedule, $menu)) {
                continue;
            }
            if (! $schedule->isLiveAt($at, $orderType)) {
                continue;
            }

            $price = $schedule->apply($base);
            if ($bestPrice === null
                || $price < $bestPrice
                || ($price === $bestPrice && [(int) $schedule->priority, (int) $schedule->id] > [(int) $best->priority, (int) $best->id])) {
                $best = $schedule;
                $bestPrice = $price;
            }
        }

        return $best;
    }

    /** Does a schedule's scope cover this item? */
    private static function covers(PriceSchedule $schedule, Menu $menu): bool
    {
        if ($schedule->scope === 'store') {
            return true;
        }

        $targets = $schedule->relationLoaded('targets')
            ? $schedule->targets
            : $schedule->targets()->get();

        if ($schedule->scope === 'item') {
            return $targets->contains(
                fn ($t) => $t->target_type === 'item' && (int) $t->target_id === (int) $menu->id
            );
        }

        // category scope — the item's own category, or any ancestor of it.
        $categoryIds = self::categoryChainIds($menu);

        return $targets->contains(
            fn ($t) => $t->target_type === 'category' && in_array((int) $t->target_id, $categoryIds, true)
        );
    }

    /* ─────────────────────────── decoration ──────────────────────────────── */

    /**
     * Apply availability + scheduled pricing to a collection of menu items
     * IN MEMORY, for rendering.
     *
     * This is what gives all 40-odd storefront themes the happy-hour price for
     * free: `$menu->price` is the attribute every theme already prints, so
     * overwriting it here means no theme markup has to change for the money to
     * be right. It mirrors exactly what the #76 branch price-override block in
     * StoreController@publicView already does a few lines earlier.
     *
     * The decorated attributes a theme MAY additionally read:
     *   original_price  — the pre-schedule price, for a strike-through
     *   schedule_label  — "Happy Hour −20%", the badge
     *   available_now   — false ⇒ show the "not available right now" state
     *   available_from_label — 'HH:MM' when known, for "available from 17:00"
     *
     * ⚠️ Deliberately NOT a global accessor on Menu::price. Order totals derive
     * from the live Menu for pre-T5 lines, so a time-varying `price` accessor
     * would silently re-price historical orders every time a happy hour came
     * round. Decoration is opt-in, per render, and never persisted.
     */
    public static function decorate($menus, ?int $storeId = null, ?string $orderType = null): void
    {
        $items = $menus instanceof Collection ? $menus : collect($menus);
        if ($items->isEmpty()) {
            return;
        }

        $rid = $storeId ?: (int) $items->first()->store_id;
        $at = self::nowFor($rid);

        /* P6-24b: the answer is exactly isAvailable() + quote() per item, but everything that does not depend on the
           item is worked out once per store instead of once per item: the kill-switch, the timezone, which schedules
           are live at $at for this order type (date + day + time-window parsing), each schedule's target sets, the
           category ancestor chain per category, and apply() per distinct base price. On a 2,000-item store that was
           ~380 ms of repeated work per home render. Same order of schedules, same tie-break, same rounding. */
        $rules = app(PromotionRules::class);
        $stores = [];
        $chains = [];
        $applied = [];

        $categoryOpen = [];

        foreach ($items as $menu) {
            if (! $menu instanceof Menu) {
                continue;
            }

            /* P6-49: this loop runs for every item of the store on every storefront render (2,000 items on a busy store),
               and the model's attribute magic (casts, mutators, relation lookups) cost more than the decision itself. So:
               the raw columns are read once, an item with no window is open without touching a clock, a category's
               window is worked out once per category, and the money cast of `price` is read only when a live schedule
               could change it. The result is the same as isAvailable() + quote() per item. */
            $attrs = $menu->getAttributes();
            $sid = (int) ($attrs['store_id'] ?? 0);
            $ctx = $stores[$sid] ??= self::decorateContext($sid, $at, $orderType);

            if (! $ctx['enabled']) {
                $available = self::rawWindowOpen($attrs, null, $ctx['tz'], null);
            } else {
                $available = self::rawWindowOpen($attrs, $at, null, $orderType);
                if ($available) {
                    $cid = $attrs['category_id'] ?? null;
                    if ($cid) {
                        $categoryOpen[$sid.':'.$cid] ??= (function () use ($menu, $at, $orderType): bool {
                            $category = self::categoryFor($menu);

                            return ! $category || $category->windowOpenNow($at, null, $orderType);
                        })();
                        $available = $categoryOpen[$sid.':'.$cid];
                    }
                }
            }
            $availableFrom = $available ? null : self::opensAt($menu);

            // The base is whatever the caller already resolved into `price`
            // (a branch override lands there before us), NOT the raw column.
            $base = 0.0;
            $winner = null;
            $winnerPrice = null;
            if ($ctx['enabled'] && $ctx['live'] !== [] && ($base = (float) $menu->price) > 0 && ! $rules->blocksPromotions($menu)) {
                $chain = null;
                foreach ($ctx['live'] as $live) {
                    $schedule = $live['schedule'];
                    if ($live['scope'] === 'item') {
                        if (! isset($live['items'][(int) $menu->id])) {
                            continue;
                        }
                    } elseif ($live['scope'] !== 'store') {
                        $chain ??= $chains[$sid.':'.(int) $menu->category_id] ??= self::categoryChainIds($menu);
                        $hit = false;
                        foreach ($chain as $categoryId) {
                            if (isset($live['categories'][$categoryId])) {
                                $hit = true;
                                break;
                            }
                        }
                        if (! $hit) {
                            continue;
                        }
                    }

                    $price = $applied[(int) $schedule->id][sprintf('%.17g', $base)] ??= $schedule->apply($base);
                    if ($winnerPrice === null
                        || $price < $winnerPrice
                        || ($price === $winnerPrice && [(int) $schedule->priority, (int) $schedule->id] > [(int) $winner->priority, (int) $winner->id])) {
                        $winner = $schedule;
                        $winnerPrice = $price;
                    }
                }
                if ($winner !== null && $winnerPrice >= $base) {
                    // Not actually cheaper — do not advertise a discount that is not one.
                    $winner = null;
                }
            }

            $menu->setDecoration($available, $availableFrom, $winner ? $base : null, $winner?->displayLabel());
            if ($winner) {
                $menu->price = $winnerPrice;

                /* Variants REPLACE the base price, so a discounted item whose
                   variants kept their list price would sell the "Large" at full
                   whack. Decorate them with the same rule. */
                if ($menu->relationLoaded('variants')) {
                    foreach ($menu->variants as $variant) {
                        if ((float) $variant->price <= 0) {
                            continue;
                        }
                        $variant->original_price = (float) $variant->price;
                        $variant->price = $winner->apply((float) $variant->price);
                    }
                }
            }
        }
    }

    /**
     * P6-49: a short fingerprint of everything TIME-DEPENDENT that decorate() (and the "hide unavailable" filter after it)
     * writes on a store's items, worked out WITHOUT loading the items: the kill-switch, which schedules are live now for
     * this order type, whether each category's window is open, and whether each distinct item window (days / from / to /
     * order types) is open. Two moments with the same signature decorate every item the same way (same price, original
     * price, label, availability), so a cache that is also keyed by the store's menu version can serve the cards without
     * hydrating 2,000 models. Prices, targets, windows and labels themselves change only by an edit, which bumps that
     * version (BustsMenuCache).
     */
    public static function decorationSignature(int $storeId, ?string $orderType = null): string
    {
        $at = self::nowFor($storeId);
        $enabled = self::enabled($storeId);
        // decorate(): item windows see the order type only when the feature is on (the pre-T5 gate ignores it)
        $itemType = $enabled ? $orderType : null;
        $parts = [$enabled ? 'on' : 'off'];

        if ($enabled) {
            foreach (self::schedulesFor($storeId) as $schedule) {
                if ($schedule->isLiveAt($at, $orderType)) {
                    $parts[] = 's'.(int) $schedule->id;
                }
            }
            foreach (self::categories($storeId) as $category) {
                $parts[] = 'c'.(int) $category->id.($category->windowOpenNow($at, null, $orderType) ? '1' : '0');
            }
        }

        $windows = [];
        foreach (Menu::query()->where('store_id', $storeId)->distinct()
            ->get(['available_days', 'available_from', 'available_to', 'available_order_types']) as $row) {
            $key = implode('|', [(string) $row->available_days, (string) $row->available_from, (string) $row->available_to, (string) $row->available_order_types]);
            $windows[$key] = $row->windowOpenNow($at, null, $itemType) ? '1' : '0';
        }
        ksort($windows);
        foreach ($windows as $key => $open) {
            $parts[] = 'w'.$key.'='.$open;
        }

        return hash('xxh128', implode("\x1e", $parts));
    }

    /**
     * P6-49: windowOpenNow() on raw columns (days / from / to / order types), without a model: no window at all is open
     * without reading a clock. Same rules and arguments as {@see \Juwri\Core\Models\Concerns\AppliesTimeWindow::windowOpenNow()}.
     *
     * @param  array<string, mixed>  $attrs
     */
    private static function rawWindowOpen(array $attrs, ?Carbon $at, ?string $tz, ?string $orderType): bool
    {
        $days = $attrs['available_days'] ?? null;
        $from = $attrs['available_from'] ?? null;
        $to = $attrs['available_to'] ?? null;
        $types = $attrs['available_order_types'] ?? null;
        if (empty($days) && empty($types) && ! ($from && $to)) {
            return true;
        }

        return Menu::scopeCoversOrderType($types, $orderType) && Menu::windowCovers($days, $from, $to, $at, $tz);
    }

    /**
     * P6-24b: the item-independent half of isAvailable()/quote() for one store at one instant — the schedules live at
     * $at in schedulesFor() order, with their item / category targets as lookup sets.
     *
     * @return array{enabled: bool, tz: string, live: list<array{schedule: PriceSchedule, scope: string, items: array<int, true>, categories: array<int, true>}>}
     */
    private static function decorateContext(int $storeId, Carbon $at, ?string $orderType): array
    {
        $enabled = self::enabled($storeId);
        $live = [];
        if ($enabled) {
            foreach (self::schedulesFor($storeId) as $schedule) {
                if (! $schedule->isLiveAt($at, $orderType)) {
                    continue;
                }
                $targets = $schedule->scope === 'store' ? collect() : ($schedule->relationLoaded('targets') ? $schedule->targets : $schedule->targets()->get());
                $itemIds = [];
                $categoryIds = [];
                foreach ($targets as $t) {
                    if ($t->target_type === 'item') {
                        $itemIds[(int) $t->target_id] = true;
                    } elseif ($t->target_type === 'category') {
                        $categoryIds[(int) $t->target_id] = true;
                    }
                }
                $live[] = ['schedule' => $schedule, 'scope' => (string) $schedule->scope, 'items' => $itemIds, 'categories' => $categoryIds];
            }
        }

        return ['enabled' => $enabled, 'tz' => self::timezoneFor($storeId), 'live' => $live];
    }

    /* ─────────────────────── order-time snapshot ─────────────────────────── */

    /**
     * The authoritative line price to persist on an order item.
     *
     * Returns the columns to write. Always includes `unit_price` (BL-049); a live
     * schedule adds `price_schedule_id` + `price_label`. Called by BOTH
     * order-creation paths — the storefront checkout and OrderApiService — so POS,
     * kiosk, agent, API and aggregator orders all snapshot identically.
     *
     * @return array{unit_price: float, price_schedule_id?: int, price_label?: string}
     */
    public static function snapshotFor(Menu $menu, ?MenuVariant $variant = null, ?string $orderType = null, ?int $branchId = null): array
    {
        $rid = (int) $menu->store_id;

        // The base is the price the order path would otherwise have charged:
        // the variant price when a variant is chosen (it REPLACES the base),
        // else the branch-effective item price.
        $useVariant = $variant && (float) $variant->price > 0;
        $base = $useVariant ? (float) $variant->price : (float) $menu->priceForBranch($branchId);

        /* P3-02: a branch price override is the price the storefront, the POS and the catalogue export show at that
           branch — but Order::computeMoney() re-prices a line without a snapshot off the item's base price, so the
           order charged the base price (quoted != charged). Snapshot the override like a scheduled price; a line
           without an override (or with a variant, whose price replaces the base) writes nothing, as before. */
        /* BL-049: every line freezes the unit price it was charged, so a later menu or variant price edit never
           re-totals a placed order. Same price Order::itemsSubtotal() derives for an unsnapshotted line: the variant
           price whenever a variant is chosen, else the branch-effective item price. */
        $branchPrice = ['unit_price' => $variant ? (float) $variant->price : $base];

        if (! self::enabled($rid)) {
            return $branchPrice;
        }

        $quote = self::quote($menu, $base, self::nowFor($rid), $orderType);
        if (! $quote['schedule']) {
            return $branchPrice;
        }

        return [
            'unit_price' => $quote['price'],
            'price_schedule_id' => (int) $quote['schedule']->id,
            'price_label' => mb_substr((string) $quote['label'], 0, 64),
        ];
    }

    /* ──────────────────────────── internals ──────────────────────────────── */

    /** Active schedules for a store, with targets eager-loaded, memoised per request. */
    public static function schedulesFor(int $storeId): Collection
    {
        self::scopeMemo();

        return self::$scheduleMemo[$storeId] ??= PriceSchedule::with('targets')
            ->where('store_id', $storeId)
            ->where('active', true)
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
    }

    /** Categories for a store, keyed by id, memoised per request. */
    private static function categories(int $storeId): Collection
    {
        self::scopeMemo();

        return self::$categoryMemo[$storeId] ??= MenuCategory::where('store_id', $storeId)
            ->get()
            ->keyBy('id');
    }

    /** This item's category, from the memo (or the loaded relation if present). */
    private static function categoryFor(Menu $menu): ?MenuCategory
    {
        if (! $menu->category_id) {
            return null;
        }
        if ($menu->relationLoaded('category') && $menu->category) {
            return $menu->category;
        }

        return self::categories((int) $menu->store_id)->get((int) $menu->category_id);
    }

    /**
     * The item's category id plus every ancestor id, so a schedule attached to
     * a parent category also covers items filed under its subcategories.
     * Depth-capped — a self-referential `parent` loop must not hang a render.
     */
    private static function categoryChainIds(Menu $menu): array
    {
        if (! $menu->category_id) {
            return [];
        }

        $all = self::categories((int) $menu->store_id);
        $ids = [];
        $cursor = (int) $menu->category_id;

        for ($depth = 0; $depth < 10 && $cursor; $depth++) {
            if (in_array($cursor, $ids, true)) {
                break;
            }
            $ids[] = $cursor;
            $cursor = (int) ($all->get($cursor)->parent ?? 0);
        }

        return $ids;
    }

    /**
     * Every schedule that is live right now, for the owner's "active now"
     * indicator on the Schedules screen.
     */
    public static function liveNow(int $storeId): Collection
    {
        $at = self::nowFor($storeId);

        return self::schedulesFor($storeId)->filter(fn ($s) => $s->isLiveAt($at))->values();
    }
}
