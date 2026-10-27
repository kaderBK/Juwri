<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Combos;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\FeatureAccess;
use App\Services\InventoryService;
use App\Services\KdsService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * PARITY-RUN T6 — the ONE server-side authority for combo pricing.
 *
 * Every surface that can sell a combo (the storefront checkout in
 * StoreController@submitOrder, OrderApiService for POS/kiosk/agent/API, and the
 * aggregator ingestor by extension) resolves its picks through {@see resolve()}
 * and persists them through {@see persist()}. Nothing prices a combo anywhere
 * else — least of all the browser, which only ever *displays* what this class
 * would charge.
 *
 * ── The money contract ───────────────────────────────────────────────────────
 * A combo is ONE order line whose entire price lives in the `order_items.options`
 * snapshot that `Order::getTotalAttribute()` already sums:
 *
 *     subtotal += Σ options[*].selected[*].price × quantity
 *
 * so the accessor needs no combo-specific arithmetic, the ESC/POS receipt
 * reconciles for free, and a historical order re-totals to the same number after
 * the owner edits the combo price — the property that makes a snapshot worth
 * having.
 *
 * The snapshot's first group is the BUNDLE (the combo's own price); one further
 * group per slot carries the picked components, priced at their per-option
 * `upcharge` only. A component's own menu price is deliberately NOT charged:
 * that is the whole point of a bundle price.
 *
 * Tax, delivery, tray, tip and discount are untouched by all of this — they are
 * computed by the order total accessor exactly as before, on a subtotal that now
 * happens to include combo lines. Tax stays on the bare pre-discount subtotal.
 */
class ComboService
{
    /**
     * Is the combo feature live for this store?
     *
     * Subject = the STORE OWNER, never the requester: the storefront caller is an
     * anonymous diner. Putting `feature:combos` middleware on the public
     * configurator or the order POST would 403 every customer — the dead end
     * recorded in the feature-gate contract, hit three times in this codebase.
     */
    public static function enabled(?int $storeId): bool
    {
        return $storeId ? FeatureAccess::storeAllows((int) $storeId, 'combos') : false;
    }

    /** Store timezone, for component availability windows. */
    private static function tz(int $storeId): string
    {
        return (string) (store_options($storeId, 'timezone') ?: config('app.timezone', 'UTC'));
    }

    /**
     * Combos a diner may see right now: feature on, combo active, branch-matched,
     * and at least one slot with at least one orderable component (a combo whose
     * components all went sold-out is not a combo, it is a dead end).
     *
     * @return Collection<int, Combo>
     */
    public static function sellable(int $storeId, $branchId = null): Collection
    {
        if (! self::enabled($storeId)) {
            return collect();
        }

        $tz = self::tz($storeId);

        return Combo::sellable($storeId, $branchId)
            ->with(['slots.options.menu', 'slots.options.variant'])
            ->get()
            ->filter(function (Combo $combo) use ($tz) {
                if ($combo->slots->isEmpty() || self::holdsBlockedItem($combo)) {
                    return false;
                }
                // Every REQUIRED slot must still have something to pick.
                foreach ($combo->slots as $slot) {
                    if ($slot->isOptional()) {
                        continue;
                    }
                    $live = $slot->options->filter(fn ($o) => $o->isOrderable($tz))->count();
                    if ($live < max(1, (int) $slot->min_select)) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * P1-23 follow-up: a combo is a bundle price, i.e. a promotion. Where promotions may not touch an item (a medicine
     * of a pharmacy whose market blocks promotions) a combo offering that item among its choices is not sold at all.
     */
    public static function holdsBlockedItem(Combo $combo): bool
    {
        $rules = app(PromotionRules::class);
        if (! $rules->storeBlocksPromotions((int) $combo->store_id)) {
            return false;
        }
        /** @var iterable<\Juwri\Modules\Menu\Models\ComboSlot> $slots */
        $slots = $combo->getRelationValue('slots') ?? [];
        foreach ($slots as $slot) {
            /** @var iterable<ComboSlotOption> $options */
            $options = $slot->getRelationValue('options') ?? [];
            foreach ($options as $opt) {
                $menu = $opt->getRelationValue('menu');
                if ($menu instanceof Menu && $rules->blocksPromotions($menu)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The configurator's payload for one combo — plain arrays, safe to @json into
     * a Blade view. Prices here are for DISPLAY; the server re-derives every one
     * of them in resolve() before charging anything.
     */
    public static function payload(Combo $combo, ?string $tz = null): array
    {
        $tz = $tz ?: self::tz((int) $combo->store_id);
        $decimals = money_decimals(store_options($combo->store_id, 'currency_code') ?: null);

        $slots = [];
        foreach ($combo->slots as $slot) {
            $options = [];
            foreach ($slot->options as $opt) {
                if (! $opt->isOrderable($tz)) {
                    continue;
                }
                $options[] = [
                    'id' => (int) $opt->id,
                    'name' => self::componentName($opt),
                    'image' => $opt->menu?->image,
                    // COMBOS-V2: resolved like every other storefront image so the
                    // configurator can render option thumbnails on any CDN/tenant path.
                    'image_url' => $opt->menu?->image
                        ? media_url('storage/menu/', $opt->menu->image, (int) $combo->store_id)
                        : null,
                    'upcharge' => Money::round((float) $opt->upcharge, $decimals),
                ];
            }
            if (! $options && ! $slot->isOptional()) {
                // Guarded by sellable(), but never emit a required slot nobody can satisfy.
                continue;
            }
            $slots[] = [
                'id' => (int) $slot->id,
                'title' => (string) $slot->title,
                'min' => (int) $slot->min_select,
                'max' => (int) $slot->max_select,
                'options' => $options,
            ];
        }

        $alaCarte = $combo->alaCarteFloor();
        $price = Money::round((float) $combo->price, $decimals);

        return [
            'id' => (int) $combo->id,
            'name' => (string) $combo->name,
            'description' => (string) ($combo->description ?? ''),
            'image' => $combo->image,
            // Resolved through media_url() like every other storefront image, so a
            // store on the CDN/tenant-scoped path gets the right URL.
            'image_url' => $combo->image
                ? media_url('storage/menu/', $combo->image, (int) $combo->store_id)
                : null,
            'price' => $price,
            'ala_carte' => $alaCarte,
            'saving' => (float) max(0, Money::round($alaCarte - $price, $decimals)),
            'slots' => $slots,
        ];
    }

    /** "Cheeseburger (Large)" — component label as it is snapshotted onto the order. */
    public static function componentName(ComboSlotOption $opt): string
    {
        $name = (string) ($opt->menu?->getRawOriginal('name') ?? '');
        if ($opt->variant_id && $opt->variant) {
            $variantTitle = self::variantTitle($opt->variant);
            if ($variantTitle !== '') {
                $name .= ' ('.$variantTitle.')';
            }
        }

        return $name;
    }

    /**
     * Human label for a MenuVariant, e.g. "Large, Spicy".
     *
     * `menu_variants` has NO `name` column (2024_08_10_162235) — a variant is
     * identified only by the option keys in its `options` JSON, resolved through
     * MenuVariantOption. Reading `$variant->name` anywhere yields null.
     */
    public static function variantTitle($variant): string
    {
        $parts = [];
        foreach ((array) ($variant->options ?? []) as $optionId => $optionKey) {
            $group = is_numeric($optionId) ? MenuVariantOption::find((int) $optionId) : null;
            if (! $group) {
                continue;
            }
            $labels = $group->getOriginal('options');
            $labels = is_string($labels) ? json_decode($labels, true) : (array) $labels;
            if (is_array($labels) && array_key_exists($optionKey, $labels)) {
                $parts[] = $labels[$optionKey];
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Validate + price posted combo lines. THE choke point.
     *
     * Input shape (the same on every surface — storefront JSON field, API body,
     * POS payload):
     *
     *   [{ "combo_id": 4, "quantity": 2, "note": "no ice",
     *      "picks": [{ "slot_id": 9, "option_ids": [31] }, …] }]
     *
     * Client-sent prices are ignored entirely; there is no field for one.
     *
     * @param  string  $field  Validation error key prefix ('combos' on the
     *                         storefront, 'combos' in the API — kept a parameter
     *                         so a caller can scope errors into its own payload).
     * @return array<int, array{combo: Combo, quantity: int, note: ?string,
     *                          unit_price: float, options: array, components: array}>
     *
     * @throws ValidationException on a killed feature, an unknown/inactive combo,
     *                             a slot violated (missing, too few, too many, duplicated), an option
     *                             that is not in the slot, or a component that is not orderable.
     */
    public static function resolve(int $storeId, array $posted, $branchId = null, string $field = 'combos'): array
    {
        if (! $posted) {
            return [];
        }

        if (! self::enabled($storeId)) {
            // A killed kill-switch must refuse the ORDER, not silently drop the
            // line the diner is looking at a price for.
            throw ValidationException::withMessages([$field => ___('Combo meals are not available right now.')]);
        }

        $tz = self::tz($storeId);
        $decimals = money_decimals(store_options($storeId, 'currency_code') ?: null);
        $resolved = [];

        foreach (array_values($posted) as $i => $rawLine) {
            $line = (array) json_decode(json_encode($rawLine), true); // normalise stdClass|array
            $comboId = (int) ($line['combo_id'] ?? 0);

            $combo = Combo::sellable($storeId, $branchId)
                ->with(['slots.options.menu', 'slots.options.variant'])
                ->find($comboId);

            if (! $combo) {
                throw ValidationException::withMessages([
                    "$field.$i.combo_id" => ___('That combo is no longer available.'),
                ]);
            }
            if (self::holdsBlockedItem($combo)) {
                throw ValidationException::withMessages([
                    "$field.$i.combo_id" => app(PromotionRules::class)->reason(),
                ]);
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));

            // Index the posted picks by slot.
            $postedPicks = [];
            foreach ((array) ($line['picks'] ?? []) as $pick) {
                $pick = (array) $pick;
                $slotId = (int) ($pick['slot_id'] ?? 0);
                $ids = array_values(array_map('intval', (array) ($pick['option_ids'] ?? [])));
                // A repeated slot in the payload merges rather than overwrites, so a
                // sloppy client cannot smuggle a second free pick past the max check.
                $postedPicks[$slotId] = array_merge($postedPicks[$slotId] ?? [], $ids);
            }

            $slotIds = $combo->slots->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach (array_keys($postedPicks) as $slotId) {
                if (! in_array($slotId, $slotIds, true)) {
                    throw ValidationException::withMessages([
                        "$field.$i.picks" => ___('That choice does not belong to this combo.'),
                    ]);
                }
            }

            // Group 0 is the bundle price itself.
            $snapshot = [[
                'combo' => true,
                'combo_id' => (int) $combo->id,
                'title' => (string) $combo->name,
                'selected' => [[
                    'option' => (string) $combo->name,
                    'price' => Money::round((float) $combo->price, $decimals),
                    'base' => true,
                ]],
            ]];
            $unitPrice = Money::round((float) $combo->price, $decimals);
            $components = [];

            foreach ($combo->slots as $slot) {
                $picked = $postedPicks[(int) $slot->id] ?? [];

                // Duplicate picks are a violation, not something to quietly de-dupe:
                // "two Cokes" in a pick-1 slot is a tampered payload.
                if (count($picked) !== count(array_unique($picked))) {
                    throw ValidationException::withMessages([
                        "$field.$i.picks" => ___('Please choose different options for “:slot”.', ['slot' => $slot->title]),
                    ]);
                }

                $min = max(0, (int) $slot->min_select);
                $max = (int) $slot->max_select; // 0 = unlimited, matching MenuVariantOption
                $count = count($picked);

                if ($count < $min) {
                    throw ValidationException::withMessages([
                        "$field.$i.picks" => ___('Choose at least :n for “:slot”.', ['n' => $min, 'slot' => $slot->title]),
                    ]);
                }
                if ($max > 0 && $count > $max) {
                    throw ValidationException::withMessages([
                        "$field.$i.picks" => ___('Choose at most :n for “:slot”.', ['n' => $max, 'slot' => $slot->title]),
                    ]);
                }

                $selected = [];
                foreach ($picked as $optionId) {
                    $opt = $slot->options->firstWhere('id', $optionId);
                    if (! $opt) {
                        throw ValidationException::withMessages([
                            "$field.$i.picks" => ___('That choice does not belong to this combo.'),
                        ]);
                    }
                    if (! $opt->isOrderable($tz)) {
                        // Sold out or outside its availability window. Named, because
                        // "something in your combo is unavailable" is useless at a table.
                        throw ValidationException::withMessages([
                            "$field.$i.picks" => ___('“:item” is not available right now.', [
                                'item' => self::componentName($opt),
                            ]),
                        ]);
                    }

                    $upcharge = Money::round((float) $opt->upcharge, $decimals);
                    $unitPrice += $upcharge;

                    $selected[] = [
                        'option' => self::componentName($opt),
                        'price' => $upcharge,
                        'option_id' => (int) $opt->id,
                        'menu_id' => (int) $opt->menu_id,
                        'variant_id' => $opt->variant_id ? (int) $opt->variant_id : null,
                    ];

                    $components[] = [
                        'menu_id' => (int) $opt->menu_id,
                        'variant_id' => $opt->variant_id ? (int) $opt->variant_id : null,
                        'name' => self::componentName($opt),
                        'quantity' => 1,
                    ];
                }

                if ($selected) {
                    $snapshot[] = [
                        'slot_id' => (int) $slot->id,
                        'title' => (string) $slot->title,
                        'selected' => $selected,
                    ];
                }
            }

            $resolved[] = [
                'combo' => $combo,
                'quantity' => $quantity,
                'note' => isset($line['note']) ? (string) $line['note'] : null,
                'unit_price' => Money::round($unitPrice, $decimals),
                'options' => $snapshot,
                'components' => $components,
            ];
        }

        return $resolved;
    }

    /**
     * Persist resolved combos onto an order as one OrderItem each, and deduct
     * every component's stock/recipe through the SAME path a normal line uses.
     *
     * `item_id` stays NULL — a combo is not a menu item — and `combo_id` carries
     * the identity. The kitchen still sees the real components, because they are
     * in the snapshot and every KOT/KDS surface reads it.
     */
    public static function persist(Order $order, array $resolvedCombos, bool $held = false): void
    {
        if (! $resolvedCombos) {
            return;
        }

        $storeId = (int) $order->store_id;

        foreach ($resolvedCombos as $r) {
            /** @var Combo $combo */
            $combo = $r['combo'];

            // KDS routing: a combo has no menu row of its own, so it rides the
            // station of its first component. Better than "Unassigned", which
            // would drop the whole ticket off a station-filtered KDS screen.
            $stationId = null;
            $firstMenu = null;
            if (! empty($r['components'])) {
                $firstMenu = Menu::find($r['components'][0]['menu_id']);
                if ($firstMenu) {
                    try {
                        $stationId = KdsService::resolveStationForOrderItem($order->branch_id ?: null, $firstMenu);
                    } catch (\Throwable $e) {
                    }
                }
            }

            $orderItem = OrderItem::create([
                'order_id' => $order->id,
                'item_id' => null,
                'combo_id' => (int) $combo->id,
                'station_id' => $stationId,
                'status' => 'queued',
                'course' => 1,
                'fired_at' => now(),
                'quantity' => (int) $r['quantity'],
                'variation' => 0,
                'order_note' => $r['note'] ?: null,
                'options' => $r['options'],
            ]);

            if (! $held) {
                self::deductComponents($storeId, $order, $r['components'], (int) $r['quantity']);
            }

            unset($orderItem);
        }
    }

    /**
     * Ingredient-level (advanced) or per-item (simple) deduction, per component —
     * the kitchen consumed a burger and a cola, not "one combo".
     */
    public static function deductComponents(int $storeId, Order $order, array $components, int $comboQty): void
    {
        foreach ($components as $c) {
            $qty = max(1, (int) ($c['quantity'] ?? 1)) * max(1, $comboQty);
            try {
                if (inventory_mode($storeId) === 'advanced') {
                    InventoryService::deductForOrderItem(
                        (int) $c['menu_id'],
                        $qty,
                        $order->id,
                        $order->branch_id ?: null
                    );
                } else {
                    Menu::deductStock((int) $c['menu_id'], $qty);
                }
            } catch (\Throwable $e) {
                // Deduction is best-effort everywhere else in this app too; a stock
                // ledger must never fail an order that has already been taken.
            }
        }
    }

    /**
     * Every sellable combo as its public payload (see payload()); used by store_templates/_partials/combos.blade.php.
     *
     * @param  iterable<int, Combo>  $combos
     * @return list<array<string, mixed>>
     */
    public static function payloads(iterable $combos): array
    {
        $out = [];
        foreach ($combos as $c) {
            $out[] = self::payload($c);
        }

        return $out;
    }
}
