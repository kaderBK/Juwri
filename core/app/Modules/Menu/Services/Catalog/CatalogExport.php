<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Catalog;

use App\Models\MenuCategory;
use Illuminate\Support\Facades\Cache;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Menu;

/**
 * I0 — Canonical, provider-agnostic catalog snapshot.
 *
 * One normalized menu tree that every menu-pushing driver (Keeta, Jahez, Deliverect,
 * Foodics/Marn mapping) consumes, so menu structure is mapped ONCE, not per driver.
 * Branch-aware pricing/availability (priceForBranch / availableAtBranch), multi-locale
 * names/descriptions from the existing `translations` object, and per-item allergen
 * codes (from the `menu.allergies` CSV → Allergy catalog) so downstream drivers can map
 * to their own allergen enums (e.g. Keeta SFDA).
 *
 * Shape (stable — treat as a contract):
 *   {
 *     store_id, branch_id, currency, generated_at,
 *     categories: [{ id, name:{locale:str}, sort,
 *       items: [{ id, sku, name:{locale}, description:{locale}, price, branch_price,
 *                 currency, image_url, sold_out, available, allergens:[codes],
 *                 calories?, gallery:[url],
 *                 modifier_groups:[{ id, name:{locale}, required, min, max,
 *                     options:[{ id, name:{locale}, price }] }] }] }]
 *   }
 */
class CatalogExport
{
    /** Cache TTL in seconds per (restaurant, branch) snapshot. */
    public const CACHE_TTL = 60;

    /**
     * Build the canonical catalog snapshot for a restaurant (optionally branch-scoped).
     *
     * @param  int|null  $branchId  when set, prices/availability resolve for that branch
     * @param  bool  $fresh  bypass the 60s cache
     */
    public function forRestaurant(int $storeId, ?int $branchId = null, bool $fresh = false): array
    {
        $key = "catalog_export:{$storeId}:".($branchId ?? 'all');

        $build = fn () => $this->build($storeId, $branchId);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_TTL, $build);
    }

    /** Invalidate the cached snapshot(s) for a restaurant (all branches). */
    public static function bust(int $storeId): void
    {
        // Branch keys are unbounded; bust the common "all" key and let the 60s TTL
        // sweep branch-scoped ones. Callers that need a specific branch pass fresh=true.
        Cache::forget("catalog_export:{$storeId}:all");
    }

    private function build(int $storeId, ?int $branchId): array
    {
        $currency = (string) (store_options($storeId, 'currency_code')
            ?: store_options($storeId, 'currency_sign') ?: 'SAR');

        $defaultLocale = $this->defaultLocale($storeId);
        $allergens = $this->allergenMap($storeId);

        $categories = MenuCategory::where('store_id', $storeId)
            ->where('active', '1')
            ->orderBy('position')
            ->get();

        $items = Menu::where('store_id', $storeId)
            ->where('active', '1')
            // N+1: eager-load what modifierGroups()'s resolved_variant_options /
            // resolved_extras read, so each item resolves in memory not 4 queries.
            ->with(['variants', 'branchSettings', 'attachedExtras', 'extras', 'attachedVariantOptions', 'variantOptions'])
            ->orderBy('position')
            ->get()
            ->groupBy('category_id');

        $cats = [];
        foreach ($categories as $cat) {
            $catItems = ($items[$cat->id] ?? collect())->map(
                fn (Menu $m) => $this->item($m, $branchId, $defaultLocale, $currency, $allergens)
            )->values()->all();

            $cats[] = [
                'id' => $cat->id,
                'name' => $this->localized($cat, 'name', $defaultLocale),
                'sort' => (int) ($cat->position ?? 0),
                'items' => $catItems,
            ];
        }

        return [
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'currency' => $currency,
            'generated_at' => now()->toIso8601String(),
            'categories' => $cats,
        ];
    }

    private function item(Menu $m, ?int $branchId, string $defaultLocale, string $currency, array $allergens): array
    {
        $rid = $m->store_id;

        return [
            'id' => $m->id,
            'sku' => $m->external_sku ?? (string) $m->id,
            'name' => $this->localizedName($m, $defaultLocale),
            'description' => $this->localizedDescription($m, $defaultLocale),
            'price' => (float) $m->price,
            'branch_price' => $m->priceForBranch($branchId),
            'currency' => $currency,
            'image_url' => $m->image ? media_url('storage/menu/', $m->image, $rid) : null,
            'gallery' => collect($m->gallery_images ?? [])->filter()
                ->map(fn ($g) => media_url('storage/menu/', $g, $rid))->values()->all(),
            'sold_out' => (bool) $m->is_sold_out,
            'available' => $m->availableAtBranch($branchId) && ! $m->is_sold_out,
            'allergens' => $this->itemAllergens($m, $allergens),
            'calories' => $m->calories !== null ? (int) $m->calories : null,
            'modifier_groups' => $this->modifierGroups($m, $defaultLocale),
        ];
    }

    /**
     * Canonical modifier groups: the item's resolved variant-option groups (choice
     * groups with min/max) plus its extras exposed as an optional (min 0) group so a
     * single provider mapping covers both.
     */
    private function modifierGroups(Menu $m, string $defaultLocale): array
    {
        $groups = [];

        foreach ($m->resolved_variant_options as $g) {
            $options = [];
            $rawOptions = $g->options ?? [];
            if (is_string($rawOptions)) {
                // Defensive: legacy rows may hold a double-encoded JSON string.
                $rawOptions = json_decode($rawOptions, true) ?: [];
            }
            foreach ((array) $rawOptions as $i => $optName) {
                if (! is_string($optName) && ! is_numeric($optName)) {
                    continue;
                }
                $prices = (array) ($g->option_prices ?? []);
                $options[] = [
                    'id' => $g->id.':'.$i,
                    'name' => [$defaultLocale => (string) $optName],
                    'price' => (float) ($prices[$i] ?? 0),
                ];
            }
            $groups[] = [
                'id' => 'grp_'.$g->id,
                'name' => $this->localized($g, 'title', $defaultLocale),
                'required' => (bool) ($g->required ?? false),
                'min' => (int) ($g->min_select ?? 0),
                'max' => (int) ($g->max_select ?? 1),
                'options' => $options,
            ];
        }

        $extras = $m->resolved_extras;
        if ($extras->isNotEmpty()) {
            $groups[] = [
                'id' => 'extras_'.$m->id,
                'name' => [$defaultLocale => 'Extras'],
                'required' => false,
                'min' => 0,
                'max' => $extras->count(),
                'options' => $extras->map(fn ($e) => [
                    'id' => 'ext_'.$e->id,
                    'name' => $this->localized($e, 'title', $defaultLocale),
                    'price' => (float) $e->price,
                ])->values()->all(),
            ];
        }

        return $groups;
    }

    /** Item allergen codes/titles resolved from the CSV of allergen ids. */
    private function itemAllergens(Menu $m, array $allergens): array
    {
        $csv = (string) ($m->allergies ?? '');
        if ($csv === '') {
            return [];
        }
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $csv))) as $id) {
            if (isset($allergens[$id])) {
                $out[] = $allergens[$id];
            }
        }

        return $out;
    }

    /** id => code(title) map of allergens available to this restaurant. */
    private function allergenMap(int $storeId): array
    {
        return Allergy::availableFor($storeId)->get()
            ->mapWithKeys(fn ($a) => [(string) $a->id => $a->getRawOriginal('title')])
            ->all();
    }

    /**
     * Build a {locale: value} map for a translatable model, keyed by the base default
     * locale plus every locale present in the `translations` object.
     */
    private function localized($model, string $field, string $defaultLocale, ?string $rawFieldGetter = null): array
    {
        $base = $model->getRawOriginal($field);
        $out = [$defaultLocale => (string) $base];

        $translations = $model->translations ?? null;
        if (is_object($translations)) {
            foreach ((array) $translations as $locale => $t) {
                if (is_object($t) && ! empty($t->title)) {
                    $out[$locale] = (string) $t->title;
                }
            }
        }

        return $out;
    }

    private function localizedName(Menu $m, string $defaultLocale): array
    {
        $out = [$defaultLocale => (string) $m->getRawOriginal('name')];
        $translations = $m->translations ?? null;
        if (is_object($translations)) {
            foreach ((array) $translations as $locale => $t) {
                if (is_object($t) && ! empty($t->title)) {
                    $out[$locale] = (string) $t->title;
                }
            }
        }

        return $out;
    }

    private function localizedDescription(Menu $m, string $defaultLocale): array
    {
        $out = [$defaultLocale => (string) $m->getRawOriginal('description')];
        $translations = $m->translations ?? null;
        if (is_object($translations)) {
            foreach ((array) $translations as $locale => $t) {
                if (is_object($t) && ! empty($t->description)) {
                    $out[$locale] = (string) $t->description;
                }
            }
        }

        return $out;
    }

    /** Restaurant default menu language, falling back to 'en'. */
    private function defaultLocale(int $storeId): string
    {
        $langs = store_options($storeId, 'menu_languages');
        if (is_array($langs) && ! empty($langs)) {
            return (string) $langs[0];
        }

        return 'en';
    }
}
