<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Grocery;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;

/**
 * P3-09 (grocery backend, REVIEW Opus: money) — {@see GroceryCatalog}.
 *
 * Sold by weight: the item's price is its price per kg (`attributes.pricing_unit`, default kg); the shopper picks a
 * weight step from an item-owned choice group (`purpose = weight`, required, one choice) whose option price is the
 * step's whole price (per-kg price × grams / 1000, rounded half up to the minor unit). The order line then snapshots
 * no base price (OrderApiService), so the line total is the step price × quantity — one derivation for quote, order
 * and receipt. The cap of a weighed line is its estimate × (1 + the store's cap %) (basket §9, owner-approved
 * 2026-10-02).
 *
 * Packs: `attributes.pack_size` + `pack_unit` (g, kg, ml, l, pc) give the unit price per kg / l / piece.
 *
 * Substitution (API-22, API-25, API-30): one store-level group (`purpose = substitution`, library, never attached to
 * an item and never priced); every option names a fixed picker action, a `call_customer` option its no-answer fallback
 * (default `remove_item`).
 */
final class GroceryCatalogService implements GroceryCatalog
{
    public const DEFAULT_CAP_PERCENT = 10;

    public const PICKER_ACTIONS = ['offer_replacement', 'call_customer', 'remove_item'];

    public const FALLBACKS = ['offer_replacement', 'remove_item'];

    public const PACK_UNITS = ['g', 'kg', 'ml', 'l', 'pc'];

    public function isWeighed(Menu $item): bool
    {
        return ! empty($this->attributes($item)['sold_by_weight']) && $this->weightGroup($item) !== null;
    }

    public function sellRule(Store $store, Menu $item): array
    {
        $currency = $this->currency($store);
        $price = Money::minorFromMajor((float) $item->price, $currency);
        $attributes = $this->attributes($item);

        $group = ! empty($attributes['sold_by_weight']) ? $this->weightGroup($item) : null;
        if ($group !== null) {
            $steps = $group->modifiers->where('active', true)->pluck('weight_g')->filter()->map(fn ($g) => (int) $g)->values()->all();
            $rule = [
                'kind' => 'weighed',
                'pack_unit' => 'g',
                'unit_price_minor' => $price,
                'unit_price_per' => 'kg',
                'weight_option_group' => (string) $group->public_id,
                'cap_percent' => $this->capPercent($store),
            ];
            if ($steps !== []) {
                $rule['step_g'] = min($steps);
            }

            return $rule;
        }

        $size = $attributes['pack_size'] ?? null;
        $unit = $attributes['pack_unit'] ?? null;
        if (is_numeric($size) && (float) $size > 0 && is_string($unit) && in_array($unit, self::PACK_UNITS, true)) {
            [$per, $base] = match ($unit) {
                'g' => ['kg', (float) $size / 1000],
                'kg' => ['kg', (float) $size],
                'ml' => ['l', (float) $size / 1000],
                'l' => ['l', (float) $size],
                default => ['pc', (float) $size],
            };

            return [
                'kind' => 'pack',
                'pack_size' => (float) $size,
                'pack_unit' => $unit,
                'unit_price_minor' => (int) round($price / $base, 0, PHP_ROUND_HALF_UP),
                'unit_price_per' => $per,
            ];
        }

        return ['kind' => 'unit'];
    }

    public function capPercent(Store $store): int
    {
        $raw = StoreSettings::get((int) $store->id, 'grocery_weighed_cap_percent');
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return self::DEFAULT_CAP_PERCENT;
        }

        return max(0, min(100, (int) $raw));
    }

    public function capMinor(Store $store, int $estimateMinor): int
    {
        // integer arithmetic: estimate × (100 + p) / 100, half up — never a float near a money boundary
        $numerator = $estimateMinor * (100 + $this->capPercent($store));

        return intdiv($numerator + 50, 100);
    }

    public function stepGrams(Menu $item, ?array $optionsSnapshot): ?int
    {
        if (empty($this->attributes($item)['sold_by_weight'])) {
            return null;
        }
        $group = $this->weightGroup($item);
        if ($group === null) {
            return null;
        }
        foreach ((array) $optionsSnapshot as $snap) {
            $snap = (array) $snap;
            if ((int) ($snap['group_id'] ?? 0) !== (int) $group->id) {
                continue;
            }
            $label = (string) (((array) (((array) ($snap['selected'] ?? []))[0] ?? []))['option'] ?? '');
            // the snapshot carries the label the resolver matched (OrderApiService::resolveOptions: the group's option
            // list, translated for a storefront language) — match it the same way, then by the stored title
            $labels = array_values((array) json_decode((string) json_encode($group->options), true));
            $index = array_search($label, $labels, true);
            $modifiers = $group->modifiers->values();
            $modifier = $index !== false ? $modifiers->get((int) $index) : $modifiers->first(fn (Modifier $m) => (string) $m->title === $label);

            return $modifier instanceof Modifier && $modifier->weight_g !== null ? (int) $modifier->weight_g : null;
        }

        return null;
    }

    public function syncWeightSteps(Menu $item, array $grams): ModifierGroup
    {
        $grams = array_values(array_unique(array_filter(array_map('intval', $grams), fn (int $g) => $g > 0 && $g <= 100000)));
        sort($grams);
        if ($grams === []) {
            throw new \InvalidArgumentException('A weighed item needs at least one weight step.');
        }
        $currency = strtoupper(store_currency((int) $item->store_id));
        $perKg = Money::minorFromMajor((float) $item->price, $currency);

        return DB::transaction(function () use ($item, $grams, $perKg, $currency) {
            $group = $this->weightGroup($item);
            $labels = array_map(fn (int $g) => $this->stepLabel($g), $grams);
            $prices = array_map(fn (int $g) => Money::majorFromMinor(intdiv($perKg * $g + 500, 1000), $currency), $grams);
            if ($group === null) {
                $group = new MenuVariantOption;
                $group->fill([
                    'store_id' => $item->store_id, 'menu_id' => $item->id, 'title' => ___('How much?'), 'is_global' => false,
                    'required' => true, 'min_select' => 1, 'max_select' => 1, 'position' => -1, 'active' => true,
                ]);
                $group->setAttribute('purpose', ModifierGroup::PURPOSE_WEIGHT);
                $group->setAttribute('display', 'weight');
            }
            $group->setAttribute('options', $labels);
            $group->setAttribute('option_prices', $prices);
            $group->save();
            foreach ($group->modifiers()->get()->values() as $i => $modifier) {
                Modifier::query()->whereKey($modifier->id)->update(['weight_g' => $grams[$i] ?? null]);
            }
            $attributes = $this->attributes($item);
            $attributes['sold_by_weight'] = true;
            $attributes['pricing_unit'] = 'kg';
            $item->setAttribute('attributes', $attributes);
            $item->save();

            return $group->fresh(['modifiers']) ?? $group;
        });
    }

    public function substitutionGroup(Store $store): ?ModifierGroup
    {
        $group = ModifierGroup::query()->with('modifiers')
            ->where('store_id', $store->id)->whereNull('menu_id')
            ->where('purpose', ModifierGroup::PURPOSE_SUBSTITUTION)->where('active', true)
            ->orderBy('id')->first();
        if ($group === null || $group->public_id === null) {
            return null;
        }
        $active = $group->modifiers->filter(fn (Modifier $m) => (bool) $m->active && $m->public_id !== null && in_array($m->picker_action, self::PICKER_ACTIONS, true))->values();
        if ($active->isEmpty()) {
            return null;
        }
        $group->setRelation('modifiers', $active);

        return $group;
    }

    public function substitutionGroupFor(Store $store, Menu $item): ?ModifierGroup
    {
        if ((int) $item->store_id !== (int) $store->id) {
            return null;
        }
        $attributes = $this->attributes($item);
        if (array_key_exists('substitution', $attributes) && $attributes['substitution'] === false) {
            return null;
        }

        return $this->substitutionGroup($store);
    }

    public function defaultSubstitution(Store $store): ?Modifier
    {
        $group = $this->substitutionGroup($store);
        if ($group === null) {
            return null;
        }
        $setting = trim((string) StoreSettings::get((int) $store->id, 'substitution_default_option', ''));
        if ($setting !== '') {
            $chosen = $group->modifiers->first(fn (Modifier $m) => (string) $m->public_id === $setting);
            if ($chosen instanceof Modifier) {
                return $chosen;
            }
        }
        $first = $group->modifiers->first();

        return $first instanceof Modifier ? $first : null;
    }

    public function substitutionOption(Store $store, string $publicId): ?Modifier
    {
        $group = $this->substitutionGroup($store);
        $found = $group?->modifiers->first(fn (Modifier $m) => (string) $m->public_id === $publicId);

        return $found instanceof Modifier ? $found : null;
    }

    public function pickerActions(ModifierGroup $group): array
    {
        $out = [];
        foreach ($group->modifiers as $modifier) {
            /** @var Modifier $modifier */
            if ($modifier->public_id === null || ! in_array($modifier->picker_action, self::PICKER_ACTIONS, true)) {
                continue;
            }
            $row = ['option_id' => (string) $modifier->public_id, 'action' => (string) $modifier->picker_action];
            if ($modifier->picker_action === 'call_customer') {
                $row['fallback'] = in_array($modifier->picker_fallback, self::FALLBACKS, true) ? (string) $modifier->picker_fallback : 'remove_item';
            }
            $out[] = $row;
        }

        return $out;
    }

    public function saveSubstitutionGroup(Store $store, string $title, array $options): ModifierGroup
    {
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 120) : ___("If it's out of stock");

        return DB::transaction(function () use ($store, $title, $options) {
            $group = ModifierGroup::query()->where('store_id', $store->id)->whereNull('menu_id')
                ->where('purpose', ModifierGroup::PURPOSE_SUBSTITUTION)->lockForUpdate()->orderBy('id')->first();
            if ($group === null) {
                $group = new ModifierGroup;
                $group->forceFill([
                    'store_id' => $store->id, 'menu_id' => null, 'kind' => ModifierGroup::KIND_VARIANT, 'is_global' => false,
                    'required' => true, 'min_select' => 1, 'max_select' => 1, 'position' => 0, 'active' => true,
                    'purpose' => ModifierGroup::PURPOSE_SUBSTITUTION,
                ]);
            }
            $group->forceFill(['title' => $title, 'active' => true])->save();

            $existing = Modifier::query()->where('modifier_group_id', $group->id)->get()->keyBy(fn (Modifier $m) => (string) $m->public_id);
            $kept = [];
            foreach (array_values($options) as $i => $option) {
                $action = (string) ($option['action'] ?? '');
                if (! in_array($action, self::PICKER_ACTIONS, true)) {
                    throw new \InvalidArgumentException('Unknown picker action.');
                }
                $fallback = $action === 'call_customer'
                    ? (in_array($option['fallback'] ?? null, self::FALLBACKS, true) ? (string) $option['fallback'] : 'remove_item')
                    : null;
                $id = isset($option['id']) ? (string) $option['id'] : '';
                $modifier = $id !== '' ? $existing->get($id) : null;
                $modifier ??= new Modifier;
                $modifier->forceFill([
                    'modifier_group_id' => $group->id, 'store_id' => $store->id, 'menu_id' => null,
                    'kind' => ModifierGroup::KIND_VARIANT, 'is_global' => false,
                    'title' => mb_substr(trim((string) $option['title']), 0, 120), 'position' => $i, 'active' => true,
                    'price_minor' => null, 'picker_action' => $action, 'picker_fallback' => $fallback,
                ])->save();
                $kept[] = (int) $modifier->id;
            }
            // Options left out stay (orders name them) but are no longer offered.
            Modifier::query()->where('modifier_group_id', $group->id)->whereNotIn('id', $kept === [] ? [0] : $kept)->update(['active' => false]);

            return $group->fresh(['modifiers']) ?? $group;
        });
    }

    /** "250 g", "1 kg", "1.5 kg" */
    public function stepLabel(int $grams): string
    {
        if ($grams >= 1000) {
            $kg = rtrim(rtrim(number_format($grams / 1000, 3, '.', ''), '0'), '.');

            return ___(':kg kg', ['kg' => $kg]);
        }

        return ___(':g g', ['g' => (string) $grams]);
    }

    /** The item's weight-step group (item-owned, `purpose = weight`, active), with its options. */
    private function weightGroup(Menu $item): ?MenuVariantOption
    {
        $group = MenuVariantOption::query()->where('menu_id', $item->id)
            ->where('purpose', ModifierGroup::PURPOSE_WEIGHT)->where('active', true)->orderBy('id')->first();

        return $group instanceof MenuVariantOption ? $group : null;
    }

    /** @return array<string, mixed> */
    private function attributes(Menu $item): array
    {
        $attributes = json_decode((string) json_encode($item->getAttribute('attributes')), true);

        return is_array($attributes) ? $attributes : [];
    }

    private function currency(Store $store): string
    {
        return strtoupper(store_currency((int) $store->id));
    }
}
