<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// crave data preparation (the item map the page ships to script.js), shared by index.blade.php and items-fragment.blade.php.
// Included INSIDE a view, so it reads and sets the view's variables: needs $total_menus, $store, $postOptions, $allergies;
// optional $craveOnlyIds (int[]) limits the map. Sets $items, $rootOf, $rootItemCount, $catItemCount, $jsItems and the rest below.

use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Services\Storefront\FoodCatalogPaging;
use Juwri\Modules\Storefront\Storefront\DisplayRates;

$allMenus = collect($total_menus)->values();
$items = $allMenus->values();
// P4-16: the lazy-load fragment (`?fragment=crave`) maps only the ids it was asked for
if (isset($craveOnlyIds)) {
    $items = $items->filter(fn ($m) => in_array((int) $m->id, $craveOnlyIds, true))->values();
}
// Two-tier category nav (primary parent chips + a secondary sub-category chip
// row that swaps per active parent — the Talabat/UberEats pattern).
//
// menu_categories() is whereNull('parent') (top-level only), but a menu item
// keys off its OWN category_id, which may be a subcategory id. So each item
// carries BOTH ids to JS: 'cat' = its real category_id (drives sub-chip
// filtering) and 'root' = its top-level ancestor (drives parent-chip filtering,
// so a parent chip shows every descendant item instead of dropping subcategory
// items). $rootOf maps any category id → its top-level ancestor.
$rootOf = [];
foreach ($store->menu_categories as $parentCat) {
    $rootOf[$parentCat->id] = $parentCat->id;
    foreach ($parentCat->subcategories as $subCat) {
        $rootOf[$subCat->id] = $parentCat->id;
    }
}
$rootItemCount = [];   // top-level id  => descendant item count (parent chips)
$catItemCount = [];   // real cat id   => own item count (sub-chips)

$mcRates = DisplayRates::fromOptions($postOptions, store_currency($store->id))->rates; // MC-32: unified rate parser (manual mode byte-identical to the hand-rolled loop)

$curSign = cur_isolate($postOptions->currency_sign ?? '$');
$curPos = (string) currency_pos_effective($postOptions->id ?? null);
$curGap = currency_symbol_gap($postOptions->currency_sign ?? '$'); // U+20C3 UAE Dirham only
$deliveryCharge = (float) (@$postOptions->restaurant_delivery_charge ?: 0);
$taxCharge = (float) (@$postOptions->restaurant_taxx_charge ?: 0);
$taxLabel = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
$minOrderValue = (float) (@$postOptions->min_order_value ?: 0);
$allowOrderSched = ! empty($postOptions->allow_order_scheduling);
$allowCoupons = @$postOptions->allow_coupons == 1;
$allowTips = @$postOptions->allow_tips == 1;
$allowWaiter = ! empty($postOptions->allow_call_waiter);
$allowDietary = ! isset($postOptions->allow_dietary_filters) || $postOptions->allow_dietary_filters == 1;
$isOpen = store_open_now($store);

$featured = $items->first(fn ($m) => $m->image && $m->image !== 'default.png') ?? $items->first();
$splashImgs = $items->filter(fn ($m) => $m->image && $m->image !== 'default.png')->take(3)->values();

/* helper: human label for a variant combination */
$variantLabel = function ($menu, $variant) {
    $parts = [];
    foreach ((array) ($variant->options ?? []) as $optId => $idx) {
        $vo = $menu->variantOptions->firstWhere('id', (int) $optId);
        if ($vo) {
            $choices = (array) $vo->options;
            if (isset($choices[$idx])) {
                $parts[] = $choices[$idx];
            }
        }
    }

    return ! empty($parts) ? implode(' · ', $parts) : ___('Option');
};

/* JS data map — same contract as aurora (variants absolute, groups/extras additive) */
$jsItems = [];

// counts for every item (cheap); the heavy per-item map below is built only for the items the page ships
foreach ($items as $m) {
    $rootCat = $rootOf[$m->category_id] ?? $m->category_id;
    $rootItemCount[$rootCat] = ($rootItemCount[$rootCat] ?? 0) + 1;
    $catItemCount[$m->category_id] = ($catItemCount[$m->category_id] ?? 0) + 1;
}

// P6-24: a very large catalogue maps its first page (+ up to 24 specials / popular, which the rails need at once) and
// leaves the rest as id => [category, root] for script.js to fetch on demand; the fragment (craveOnlyIds) maps what it was asked for
$craveLazy = null;
$mapItems = $items;
if (! isset($craveOnlyIds) && $items->count() > (int) config('perf.storefront_home_page_threshold', 200)) {
    $__page = FoodCatalogPaging::pageSize();
    $__extra = 0;
    $__keepIds = [];
    foreach ($items as $__i => $m) {
        if ($__i < $__page) {
            $__keepIds[(int) $m->id] = true;
        } elseif ($__extra < 24 && ((bool) ($m->is_daily_special ?? false) || (bool) $m->is_popular)) {
            $__keepIds[(int) $m->id] = true;
            $__extra++;
        }
    }
    $craveLazy = [];
    foreach ($items as $m) {
        if (! isset($__keepIds[(int) $m->id])) {
            $craveLazy[$m->id] = [$m->category_id, $rootOf[$m->category_id] ?? $m->category_id];
        }
    }
    $mapItems = $items->filter(fn ($m) => isset($__keepIds[(int) $m->id]))->values();
}
foreach ($mapItems as $m) {
    $itemAllergies = empty($m->allergies)
        ? collect()
        : $allergies->whereIn('id', explode(',', $m->allergies));

    $variants = [];
    if (isset($m->variants)) {
        foreach ($m->variants as $v) {
            $variants[] = ['id' => $v->id, 'label' => $variantLabel($m, $v), 'price' => (float) $v->price];
        }
    }
    $extras = [];
    if (isset($m->extras)) {
        foreach ($m->extras as $e) {
            $extras[] = [
                'id' => $e->id,
                'title' => $e->title,
                'price' => (float) $e->price,
                'img' => $e->image ? media_url('storage/menu/', $e->image, $store->id ?? null) : '',
            ];
        }
    }

    // 'root' = the item's top-level ancestor (parent-chip filter); 'cat' stays
    // the item's real category id (sub-chip filter). Orphan → falls back to self.
    $rootCat = $rootOf[$m->category_id] ?? $m->category_id;

    $jsItems[$m->id] = [
        'id' => $m->id,
        'cat' => $m->category_id,
        'root' => $rootCat,
        'name' => $m->name,
        'desc' => strip_tags((string) $m->description),
        'img' => media_url('storage/menu/', $m->image, $store->id ?? null),
        'price' => (float) $m->price,
        'type' => $m->type === 'nonveg' ? 'nonveg' : 'veg',
        'rating' => $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : '',
        'allergies' => $itemAllergies->map(fn ($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'img' => Str::of($a->image)->isUrl() ? $a->image : media_url('storage/allergies/', $a->image, null),
        ])->values(),
        'variants' => $variants,
        'extras' => $extras,
        'is_vegan' => (bool) $m->is_vegan,
        'is_halal' => (bool) $m->is_halal,
        'is_gluten_free' => (bool) $m->is_gluten_free,
        'is_popular' => (bool) $m->is_popular,
        'is_daily_special' => (bool) ($m->is_daily_special ?? false),
        'spice_level' => (int) $m->spice_level,
        'is_sold_out' => (bool) $m->is_sold_out,
        'available_from' => $m->available_from ?? null,
        'available_to' => $m->available_to ?? null,
        'available_days' => $m->available_days ?? null,
        'available_now' => ($m->available_now ?? true),                 // T5: server-resolved (store clock, category window, order type)
        'available_from_label' => ($m->available_from_label ?? null),          // T5: "available from HH:MM"
        'original_price' => ($m->original_price ?? null),               // T5: pre-happy-hour price, for a strike-through
        'schedule_label' => ($m->schedule_label ?? null),               // T5: "Happy Hour" badge
        'calories' => $m->calories ?? null,
        'protein' => $m->protein ?? null,
        'carbs' => $m->carbs ?? null,
        'fat' => $m->fat ?? null,
        'gallery' => ! empty($m->gallery_images)
            ? collect((array) $m->gallery_images)->map(fn ($f) => media_url('storage/menu/', $f, $store->id ?? null))->values()
            : [],
        'optionGroups' => collect($m->globalGroups ?? [])->map(fn ($g) => [
            'id' => $g->id,
            'title' => $g->title,
            'required' => (bool) $g->required,
            'min' => (int) ($g->min_select ?? 0),
            'max' => $g->max_select !== null ? (int) $g->max_select : null,
            'options' => collect((array) $g->options)->values()->map(fn ($label, $i) => [
                'i' => $i, 'label' => $label, 'price' => (float) (($g->option_prices[$i] ?? 0)),
            ])->values(),
        ])->values(),
    ];
}
