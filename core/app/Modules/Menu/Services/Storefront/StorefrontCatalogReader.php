<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Storefront;

use App\Models\MenuCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;

/**
 * P3-02: the Menu module's answer to {@see StorefrontCatalog}. The same rules the storefront page applies
 * (StoreController::storefrontMenus + the branch / schedule block of publicView), and the same option and add-on
 * resolution the order path prices with (Menu::resolved_variant_options / resolved_extras, OrderApiService), so a
 * view model never shows a choice or a price the order would refuse or charge differently.
 */
final class StorefrontCatalogReader implements StorefrontCatalog
{
    public function categories(Store $store): Collection
    {
        return MenuCategory::query()
            ->where('store_id', $store->id)
            ->where('active', '1')
            ->orderBy('position')->orderBy('id')
            ->get();
    }

    public function items(Store $store, ?Branch $branch = null, ?string $orderType = null, ?int $categoryId = null): Collection
    {
        $items = $this->query($store)->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))->get();

        return $this->shape($store, $items, $branch, $orderType);
    }

    public function item(Store $store, string $publicId, ?Branch $branch = null, ?string $orderType = null): ?Menu
    {
        $items = $this->query($store)->where('public_id', $publicId)->get();

        return $this->shape($store, $items, $branch, $orderType)->first();
    }

    public function optionGroups(Menu $item): array
    {
        $currency = $this->currency($item);
        $groups = [];

        foreach ($item->resolved_variant_options as $group) {
            /** @var MenuVariantOption $group */
            if (! $group->active || $group->public_id === null) {
                continue;
            }
            $labels = array_values((array) json_decode((string) json_encode($group->options), true));
            $prices = array_values((array) ($group->option_prices ?? []));
            $options = [];
            foreach ($group->modifiers->values() as $i => $modifier) {
                /** @var Modifier $modifier */
                if ($modifier->public_id === null) {
                    continue;
                }
                $option = [
                    'public_id' => (string) $modifier->public_id,
                    'title' => (string) ($labels[$i] ?? $modifier->title),
                    'price_minor' => Money::minorFromMajor((float) ($prices[$i] ?? 0), $currency),
                    'active' => (bool) $modifier->active,
                ];
                if ($modifier->getAttribute('weight_g') !== null) {
                    $option['weight_g'] = (int) $modifier->getAttribute('weight_g'); // P3-09: a weight step
                }
                $swatch = (string) $modifier->getAttribute('swatch');
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $swatch) === 1) {
                    $option['swatch'] = strtoupper($swatch); // P3-08: a colour option's swatch
                }
                $options[] = $option;
            }
            $required = (bool) $group->required;
            $display = $group->getAttribute('display');
            $groups[] = [
                'public_id' => (string) $group->public_id,
                'title' => (string) $group->title,
                'kind' => 'variant',
                // P3-08 swatch / size / chips; P3-09: the weight steps of an item sold by weight
                'display' => $group->getAttribute('purpose') === ModifierGroup::PURPOSE_WEIGHT ? 'weight' : (in_array($display, ['swatch', 'size', 'chips'], true) ? $display : null),
                'required' => $required,
                'min' => $required ? max(1, (int) $group->min_select) : (int) $group->min_select,
                'max' => (int) $group->max_select > 0 ? (int) $group->max_select : null,
                'options' => $options,
            ];
        }

        // Add-ons: the item's own and attached library add-ons (price override applied), grouped by their group.
        $byGroup = [];
        foreach ($item->resolved_extras as $extra) {
            /** @var MenuExtra $extra */
            if (! $extra->active || $extra->public_id === null || $extra->modifier_group_id === null) {
                continue;
            }
            $byGroup[(int) $extra->modifier_group_id][] = $extra;
        }
        if ($byGroup !== []) {
            $addonGroups = ModifierGroup::query()->whereIn('id', array_keys($byGroup))->get()->keyBy('id');
            foreach ($byGroup as $groupId => $extras) {
                $group = $addonGroups->get($groupId);
                if (! $group instanceof ModifierGroup || ! $group->active || $group->public_id === null) {
                    continue;
                }
                $required = (bool) $group->required;
                $groups[] = [
                    'public_id' => (string) $group->public_id,
                    'title' => (string) ($this->translated($group) ?? $group->title),
                    'kind' => 'addon',
                    'required' => $required,
                    'min' => $required ? max(1, (int) $group->min_select) : (int) $group->min_select,
                    'max' => (int) $group->max_select > 0 ? (int) $group->max_select : null,
                    'options' => array_map(fn (MenuExtra $e) => [
                        'public_id' => (string) $e->public_id,
                        'title' => (string) $e->title,
                        'price_minor' => Money::minorFromMajor((float) $e->price, $currency),
                        'active' => true,
                    ], $extras),
                ];
            }
        }

        return $groups;
    }

    public function variants(Menu $item): array
    {
        $currency = $this->currency($item);
        $groupPublicIds = [];
        $optionPublicIds = [];
        foreach ($item->resolved_variant_options as $group) {
            $groupPublicIds[(int) $group->id] = (string) $group->public_id;
            foreach ($group->modifiers->values() as $i => $modifier) {
                $optionPublicIds[(int) $group->id][$i] = (string) $modifier->public_id;
            }
        }

        $out = [];
        foreach ($item->variants as $variant) {
            /** @var MenuVariant $variant */
            if (! $variant->active || $variant->public_id === null) {
                continue;
            }
            $cell = [];
            foreach ((array) json_decode((string) json_encode($variant->options), true) as $key => $value) {
                // A priced combo stores {"<group id>": <option index>}: never expose the internal ids.
                if (is_numeric($key) && isset($groupPublicIds[(int) $key])) {
                    $cell[$groupPublicIds[(int) $key]] = $optionPublicIds[(int) $key][(int) $value] ?? (string) $value;
                } elseif (! is_numeric($key)) {
                    $cell[(string) $key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
                }
            }
            $out[] = [
                'public_id' => (string) $variant->public_id,
                'sku' => $variant->sku !== null && $variant->sku !== '' ? (string) $variant->sku : null,
                'options' => $cell,
                'price_minor' => Money::minorFromMajor((float) $variant->price, $currency),
                'stock' => $variant->stock_qty,
            ];
        }

        return $out;
    }

    public function decorationSignature(Store $store, ?string $orderType = null): string
    {
        return MenuScheduleService::decorationSignature((int) $store->id, $orderType);
    }

    public function allergenNames(Store $store): array
    {
        return Allergy::query()->availableFor($store->id)->orderBy('position')->get()
            ->mapWithKeys(fn (Allergy $a) => [(int) $a->id => (string) $a->title])->all();
    }

    /** @return Builder<Menu> */
    private function query(Store $store)
    {
        $onlyActive = fn ($q) => $q->where('active', 1);

        return Menu::query()
            ->with([
                'category', 'brand', 'branchSettings',
                'variantOptions' => $onlyActive,
                'variants' => $onlyActive,
                'extras' => $onlyActive,
                'attachedExtras' => fn ($q) => $q->where('modifiers.active', true)->wherePivot('active', true),
                'attachedVariantOptions' => fn ($q) => $q->wherePivot('active', true),
            ])
            ->visibleOnStorefront()
            ->where('store_id', $store->id)
            ->where('active', '1')
            ->orderBy('position')->orderBy('id');
    }

    /**
     * Branch availability + price override, then the schedule (in memory only), then the availability window.
     *
     * @param  Collection<int, Menu>  $items
     * @return Collection<int, Menu>
     */
    private function shape(Store $store, Collection $items, ?Branch $branch, ?string $orderType): Collection
    {
        $branchId = $branch !== null && (int) $branch->store_id === (int) $store->id ? (int) $branch->id : null;
        if ($branchId !== null) {
            $items = $items->filter(fn (Menu $m) => $m->availableAtBranch($branchId))
                ->each(function (Menu $m) use ($branchId): void {
                    $m->price = $m->priceForBranch($branchId);
                })->values();
        }

        MenuScheduleService::decorate($items, (int) $store->id, $orderType);

        if (! StoreSettings::bool((int) $store->id, 'menu_show_unavailable')) {
            $items = $items->filter(fn (Menu $m) => $m->getAttribute('available_now') !== false)->values();
        }

        return $items->values();
    }

    private function currency(Menu $item): string
    {
        return strtoupper(store_currency((int) $item->store_id));
    }

    /** A library add-on group's title in the storefront language (ModifierGroup has no translating accessor). */
    private function translated(ModifierGroup $group): ?string
    {
        $language = $_COOKIE['Quick_user_lang_code'] ?? null;
        if (! is_string($language) || $language === '') {
            return null;
        }
        $translations = json_decode((string) json_encode($group->getAttribute('translations')), true);
        $value = is_array($translations) ? ($translations[$language]['title'] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
