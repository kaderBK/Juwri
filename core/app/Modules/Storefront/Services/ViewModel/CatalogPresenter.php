<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use App\Models\MenuCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P3-02: the `catalog` block (categories → item ids, items keyed by public id as ItemCards) and the item page
 * payload (ItemDetail) of the view model, from the Menu module's StorefrontCatalog. Ids are public ids only.
 */
final class CatalogPresenter
{
    /** @var array<int, string> */
    private array $allergens = [];

    /** @var array<string, list<array<string, mixed>>> memo: item public id => option groups */
    private array $groups = [];

    private readonly RetailCatalog $retail;

    /** @var array<int, bool|null>|null P3-08b: menu id => fits the shopper's vehicle (null = no fitments entered); null = no vehicle */
    private ?array $fits = null;

    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly Store $store,
        private readonly MoneyFormat $money,
        private readonly bool $food,
        private readonly bool $ageGate,
        ?RetailCatalog $retail = null,
    ) {
        $this->retail = $retail ?? app(RetailCatalog::class);
        if ($food) {
            $this->allergens = $catalog->allergenNames($store);
        }
    }

    /**
     * P3-08b: cards (and the detail) carry `fits_vehicle` for the shopper's vehicle.
     *
     * @param  array<int, bool|null>  $fits
     */
    public function forVehicle(array $fits): self
    {
        $this->fits = $fits;

        return $this;
    }

    /**
     * @param  Collection<int, Menu>  $items
     * @return array{categories: list<array<string, mixed>>, items: array<string, array<string, mixed>>, total: int}
     */
    public function catalog(Collection $items): array
    {
        $byCategory = $items->groupBy(fn (Menu $m) => (int) $m->category_id);
        $categories = [];
        foreach ($this->catalog->categories($this->store) as $category) {
            /** @var MenuCategory $category */
            $ids = ($byCategory[(int) $category->id] ?? collect())->map(fn (Menu $m) => (string) $m->public_id)->values()->all();
            if ($ids === [] || $category->public_id === null) {
                continue; // §2.4: an empty category is hidden from the navigation
            }
            $row = ['id' => (string) $category->public_id, 'name' => (string) $category->name];
            if (! empty($category->image) && $category->image !== 'default.png'
                && media_exists('storage/menu/', (string) $category->image, (int) $this->store->id)) {
                $row['image'] = $this->cardImage((string) $category->image, (string) $category->name);
            }
            $categories[] = $row + ['item_ids' => $ids, 'count' => count($ids)];
        }

        $cards = [];
        foreach ($items as $item) {
            $cards[(string) $item->public_id] = $this->card($item);
        }

        return ['categories' => $categories, 'items' => $cards, 'total' => count($cards)];
    }

    /** @return array<string, mixed> ItemCard */
    public function card(Menu $item): array
    {
        $groups = $this->groups($item);
        // P3-09: the weight steps of an item sold by weight are not an upcharge on its price (which is per kg): the card
        // shows the price per kg with the "Sold by weight" unit.
        $required = array_values(array_filter($groups, fn (array $g) => $g['required'] && ($g['display'] ?? null) !== 'weight'));
        $variants = $this->catalog->variants($item);
        $base = $this->priceMinor($item);

        $card = [
            'id' => (string) $item->public_id,
            'name' => (string) $item->name,
            'url' => $this->itemUrl($item),
        ];
        $short = trim(Str::limit(trim(strip_tags((string) $item->description)), 140, '…'));
        if ($short !== '') {
            $card['short'] = $short;
        }
        if (! empty($item->image) && $item->image !== 'default.png') {
            $card['image'] = $this->cardImage((string) $item->image, (string) $item->name);
        }

        $from = $this->fromPrice($base, $required, $variants);
        if ($required !== [] || $variants !== []) {
            $card['from_price'] = $this->money->money($from);
        } else {
            $card['price'] = $this->money->money($base);
        }
        $compare = $this->compareAtMinor($item, $base);
        if ($compare !== null) {
            $card['compare_at'] = $this->money->money($compare);
        }

        $card['badges'] = $this->badges($item, $compare !== null);
        $card['sold_out'] = (bool) $item->is_sold_out;
        $card['has_required_choices'] = $required !== [] || array_filter($groups, fn (array $g) => ($g['display'] ?? null) === 'weight') !== [];

        if ($this->food) {
            if ($item->calories !== null) {
                $card['calories'] = (int) $item->calories;
            }
            $allergens = $this->allergenList($item);
            if ($allergens !== []) {
                $card['allergens'] = $allergens;
            }
        } else {
            if ($item->brand !== null && trim((string) $item->brand->name) !== '') {
                $card['brand'] = (string) $item->brand->name;
            }
            if (! empty($item->external_sku)) {
                $card['sku'] = (string) $item->external_sku;
            }
            // P3-08 (aisle): the colours a product comes in (the card shows three and a count).
            $swatches = [];
            foreach ($groups as $group) {
                if (($group['display'] ?? null) !== 'swatch') {
                    continue;
                }
                foreach ($group['options'] as $option) {
                    if (isset($option['swatch']) && $option['active']) {
                        $swatches[] = ['label' => $option['title'], 'hex' => $option['swatch']];
                    }
                }
            }
            if ($swatches !== []) {
                $card['swatches'] = $swatches;
            }
        }

        $attributes = $this->attributes($item);
        if (! empty($attributes['sold_by_weight'])) {
            // P3-09 (basket §6): the price of a weighed item is per kg ("SAR 11.80 / kg"); the shopper picks the weight
            $card['unit'] = ['label' => ___(':price / kg', ['price' => $this->money->format($this->priceMinor($item))]), 'sold_by_weight' => true];
        }
        if (isset($attributes['duration_min']) && is_numeric($attributes['duration_min'])) {
            $card['duration_min'] = (int) $attributes['duration_min'];
        }
        if (isset($attributes['pack_size'], $attributes['pack_unit']) && is_scalar($attributes['pack_size']) && is_scalar($attributes['pack_unit'])) {
            $card['pack_label'] = trim($attributes['pack_size'].' '.$attributes['pack_unit']);
            // P3-09 (basket §6): a pack shows what its kilo / litre / piece costs ("250 g · SAR 59.80 / kg"), the server's arithmetic
            if (! $this->food && empty($attributes['sold_by_weight'])) {
                $rule = app(GroceryCatalog::class)->sellRule($this->store, $item);
                if ($rule['kind'] === 'pack' && isset($rule['unit_price_minor'], $rule['unit_price_per'])) {
                    $price = $this->money->format((int) $rule['unit_price_minor']);
                    $perUnit = match ($rule['unit_price_per']) {
                        'kg' => ___(':price / kg', ['price' => $price]),
                        'l' => ___(':price / l', ['price' => $price]),
                        default => ___(':price / pc', ['price' => $price]),
                    };
                    $card['unit'] = ['label' => $card['pack_label'].' · '.$perUnit];
                }
            }
        }
        if ($this->ageGate) {
            $card['age_restricted'] = (bool) $item->age_restricted;
        }
        if ($this->fits !== null) {
            $card['fits_vehicle'] = $this->fits[(int) $item->id] ?? null;
        }

        return $card;
    }

    /** @return array<string, mixed> ItemDetail */
    public function detail(Menu $item): array
    {
        $card = $this->card($item);
        $groups = $this->groups($item);
        $base = $this->priceMinor($item);

        $detail = ['id' => $card['id'], 'name' => $card['name']];
        if (isset($card['brand'])) {
            $detail['brand'] = $card['brand'];
        }
        $description = trim(strip_tags((string) $item->description));
        if ($description !== '') {
            $detail['description'] = $description;
        }
        $gallery = [];
        if (isset($card['image'])) {
            $gallery[] = $card['image'];
        }
        foreach (array_filter((array) ($item->gallery_images ?? [])) as $file) {
            $gallery[] = ['src' => media_url('storage/menu/', (string) $file, (int) $this->store->id), 'alt' => (string) $item->name];
        }
        if ($gallery !== []) {
            $detail['gallery'] = $gallery;
        }
        $detail['price'] = $this->money->money($base);
        if (isset($card['from_price'])) {
            $detail['from_price'] = $card['from_price'];
        }
        if (isset($card['compare_at'])) {
            $detail['compare_at'] = $card['compare_at'];
            $detail['saving_label'] = ___('Save :amount', ['amount' => $this->money->format(max(0, $this->savingMinor($item, $base)))]);
        }
        $detail['badges'] = $card['badges'];
        if (array_key_exists('fits_vehicle', $card)) {
            $detail['fits_vehicle'] = $card['fits_vehicle'];
        }

        // P3-08: an option of the matrix whose every cell is sold out is shown, not offered.
        $matrix = $this->food ? [] : $this->retail->matrix($this->store, $item);
        $soldOutOptions = [];
        if ($matrix !== []) {
            $inStock = [];
            $seen = [];
            foreach ($matrix as $cell) {
                foreach ($cell['options'] as $optionId) {
                    $seen[$optionId] = true;
                    if ($cell['stock']['state'] !== 'out') {
                        $inStock[$optionId] = true;
                    }
                }
            }
            $soldOutOptions = array_diff_key($seen, $inStock);
        }

        $optionGroups = [];
        $selectedDelta = 0;
        foreach ($groups as $group) {
            $options = [];
            // a size is never chosen for the shopper (aisle §7: without a size, Add asks for one)
            $preselect = $group['required'] && $group['max'] === 1 && ($group['display'] ?? null) !== 'size';
            $selectedLabels = [];
            foreach ($group['options'] as $i => $option) {
                $row = [
                    'id' => $option['public_id'],
                    'label' => $option['title'],
                    'price_delta' => $this->money->money($option['price_minor']),
                ];
                if (($group['display'] ?? null) === 'weight') {
                    $row['sub_label'] = '~ '.$this->money->format($option['price_minor']); // P3-09: a weight step's estimate
                }
                if (isset($option['swatch'])) {
                    $row['swatch'] = $option['swatch'];
                }
                if (! $option['active'] || isset($soldOutOptions[$option['public_id']])) {
                    $row['available'] = false;
                    if (isset($soldOutOptions[$option['public_id']])) {
                        $row['stock_label'] = ___('Sold out');
                    }
                }
                // A required single choice starts on its first available option (what the add button prices).
                if ($preselect && $option['active'] && ! isset($soldOutOptions[$option['public_id']]) && $selectedLabels === []) {
                    $row['selected'] = true;
                    $selectedLabels[] = $option['title'];
                    $selectedDelta += $option['price_minor'];
                }
                $options[] = $row;
            }
            $row = [
                'id' => $group['public_id'],
                'label' => $group['title'],
                'kind' => ($group['display'] ?? null) !== null ? (string) $group['display'] : ($group['max'] === 1 ? 'radio' : 'checkbox'),
                'required' => $group['required'],
                'min' => $group['min'],
            ];
            if ($group['max'] !== null) {
                $row['max'] = $group['max'];
            }
            $row['rule_label'] = $this->ruleLabel($group);
            $row['options'] = $options;
            if ($selectedLabels !== []) {
                $row['selected_label'] = implode(', ', $selectedLabels);
            }
            $optionGroups[] = $row;
        }
        // P3-09 (basket): an item sold by weight shows its price per kg, the weight steps and the estimate + cap note;
        // a goods item of a store with an out-of-stock choice shows that choice too (API-22: its own group, not priced).
        $weighed = null;
        if (! $this->food) {
            $grocery = app(GroceryCatalog::class);
            foreach ($groups as $group) {
                if (($group['display'] ?? null) === 'weight') {
                    $weighed = $group;
                    break;
                }
            }
            if ($weighed !== null) {
                $steps = array_values(array_filter(array_map(fn (array $o) => (int) ($o['weight_g'] ?? 0), $weighed['options'])));
                $detail['unit'] = array_filter([
                    'label' => ___(':price / kg', ['price' => $this->money->format($base)]),
                    'sold_by_weight' => true,
                    'step_g' => $steps !== [] ? min($steps) : null,
                ], fn ($v) => $v !== null);
                $cap = $grocery->capMinor($this->store, $selectedDelta);
                $detail['estimate_note'] = GroceryPresenter::estimateNote($this->money, $selectedDelta, $cap, $grocery->capPercent($this->store));
            }
            $substitution = $grocery->substitutionGroupFor($this->store, $item);
            if ($substitution !== null) {
                $optionGroups[] = GroceryPresenter::substitutionGroup($substitution, $grocery->defaultSubstitution($this->store));
            }
        }
        $detail['option_groups'] = $optionGroups;
        $detail['quantity'] = ['min' => 1, 'max' => $weighed !== null ? 10 : 99, 'step' => 1, 'value' => 1];

        if ($this->food) {
            $nutrition = [];
            if ($item->calories !== null) {
                $nutrition[] = ['label' => ___('Calories'), 'value' => ___(':kcal kcal', ['kcal' => (string) $item->calories])];
            }
            if ($nutrition !== []) {
                $detail['nutrition'] = $nutrition;
            }
            $allergens = $this->allergenList($item);
            if ($allergens !== []) {
                $detail['details'] = [['title' => ___('Allergens'), 'html' => '<p>'.e(implode(', ', $allergens)).'</p>']];
            }
        }

        $variants = $this->catalog->variants($item);
        if ($variants !== []) {
            $states = [];
            foreach ($matrix as $cell) {
                $states[$cell['public_id']] = $cell['stock'];
            }
            $detail['variants'] = array_map(function (array $v) use ($states): array {
                $row = ['sku' => $v['sku'] ?? $v['public_id'], 'options' => $v['options'], 'price' => $this->money->money($v['price_minor'])];
                // P3-08 bug fix (API-6): the exact quantity only at or below the low-stock threshold (0 when out);
                // before, every tracked cell's stock count was published.
                $state = $states[$v['public_id']] ?? null;
                if ($state !== null && $state['state'] === 'low') {
                    $row['stock'] = (int) $state['qty'];
                } elseif ($state !== null && $state['state'] === 'out') {
                    $row['stock'] = 0;
                }

                return $row;
            }, $variants);
        }
        if (! $this->food) {
            $guide = $this->retail->sizeGuide($this->store, $item);
            if ($guide !== null) {
                $detail['size_guide_url'] = route('storefront.sizeGuide', ['slug' => $this->store->slug, 'guide' => $guide->public_id], false);
            }
            $rows = $this->infoRows();
            if ($rows !== []) {
                $detail['info_rows'] = $rows;
            }
        }
        if (! empty($item->external_sku)) {
            $detail['sku'] = (string) $item->external_sku;
        }

        $detail['add_label'] = $weighed !== null
            // the weight step is the whole price of a weighed line (the item price is per kg)
            ? ___('Add · estimated :amount', ['amount' => $this->money->format($selectedDelta)])
            : ___('Add · :amount', ['amount' => $this->money->format($base + $selectedDelta)]);

        return $detail;
    }

    /**
     * P3-08 (aisle item page): returns policy and pickup rows, from the store's settings.
     *
     * @return list<array{icon: string, label: string, sub?: string}>
     */
    private function infoRows(): array
    {
        $rows = [];
        $days = StoreSettings::int((int) $this->store->id, 'returns_window_days');
        if ($days > 0) {
            $rows[] = ['icon' => 'rotate-ccw', 'label' => ___(':days-day returns', ['days' => (string) $days]),
                'sub' => RefundSwitches::allows((int) $this->store->id, 'returns')
                    ? ___('Exchange, store credit or refund') : ___('Exchange or store credit')];
        }

        return $rows;
    }

    /**
     * Item price in minor units (branch override and scheduled price already applied by the catalogue).
     */
    public function priceMinor(Menu $item): int
    {
        return Money::minorFromMajor((float) $item->price, $this->money->currency);
    }

    /** @return list<array<string, mixed>> */
    public function groups(Menu $item): array
    {
        return $this->groups[(string) $item->public_id] ??= $this->catalog->optionGroups($item);
    }

    /**
     * The lowest price the add button can show: the cheapest variant when the item has variants, else the base
     * plus the cheapest option of each required group.
     *
     * @param  list<array<string, mixed>>  $required
     * @param  list<array<string, mixed>>  $variants
     */
    private function fromPrice(int $base, array $required, array $variants): int
    {
        if ($variants !== []) {
            return min(array_map(fn (array $v) => (int) $v['price_minor'], $variants));
        }
        $from = $base;
        foreach ($required as $group) {
            $prices = array_map(fn (array $o) => (int) $o['price_minor'], array_filter($group['options'], fn (array $o) => $o['active']));
            if ($prices !== []) {
                $from += min($prices) * max(1, (int) $group['min']);
            }
        }

        return $from;
    }

    /** the saving the was-price shows on the page: compare-at minus the price the page leads with (the cheapest variant's) */
    private function savingMinor(Menu $item, int $base): int
    {
        $lead = $base;
        foreach ($this->catalog->variants($item) as $variant) {
            $lead = min($lead === $base ? PHP_INT_MAX : $lead, (int) $variant['price_minor']);
        }

        return (int) $this->compareAtMinor($item, $base) - ($lead === PHP_INT_MAX ? $base : $lead);
    }

    private function compareAtMinor(Menu $item, int $base): ?int
    {
        $original = $item->getAttribute('original_price'); // a live price schedule (happy hour)
        if ($original !== null && (float) $original > 0) {
            $minor = Money::minorFromMajor((float) $original, $this->money->currency);

            return $minor > $base ? $minor : null;
        }
        $compare = $this->attributes($item)['compare_at_price'] ?? null;
        if (is_numeric($compare)) {
            $minor = Money::minorFromMajor((float) $compare, $this->money->currency);

            return $minor > $base ? $minor : null;
        }

        // P3-08: a product sold in variants may carry the was-price on its variants (aisle): the page leads with the
        // cheapest variant, so it shows that variant's was-price
        $lead = null;
        foreach ($item->variants as $variant) {
            if (! $variant->active) {
                continue;
            }
            $price = Money::minorFromMajor((float) $variant->price, $this->money->currency);
            if ($lead === null || $price < $lead[0]) {
                $lead = [$price, $variant->compare_at_minor !== null ? (int) $variant->compare_at_minor : null];
            }
        }
        if ($lead !== null && $lead[1] !== null && $lead[1] > $lead[0]) {
            return $lead[1];
        }

        return null;
    }

    /** @return list<array{kind: string, label: string}> */
    private function badges(Menu $item, bool $onSale): array
    {
        $badges = [];
        if ((bool) $item->is_sold_out) {
            $badges[] = ['kind' => 'sold_out', 'label' => ___('Sold out')];
        }
        if (($this->attributes($item)['badge'] ?? null) === 'new') {
            $badges[] = ['kind' => 'new', 'label' => ___('New')];
        }
        if ((bool) $item->is_popular) {
            $badges[] = ['kind' => 'popular', 'label' => ___('Popular')];
        }
        if ($item->getAttribute('schedule_label')) {
            $badges[] = ['kind' => 'offer', 'label' => (string) $item->getAttribute('schedule_label')];
        } elseif ($onSale) {
            $badges[] = ['kind' => 'sale', 'label' => ___('On sale')];
        }
        if (! $item->is_sold_out && $item->track_inventory && $item->stock_qty !== null
            && (int) $item->stock_qty > 0 && (int) $item->stock_qty <= (int) $item->low_stock_threshold) {
            $badges[] = ['kind' => 'low_stock', 'label' => ___('Few left')];
        }
        if ($this->food && (int) $item->spice_level > 0) {
            $badges[] = ['kind' => 'spicy', 'label' => ___('Spicy')];
        }
        if ($this->food && (bool) $item->is_vegan) {
            $badges[] = ['kind' => 'vegan', 'label' => ___('Vegan')];
        }

        return $badges;
    }

    /** @param  array<string, mixed>  $group */
    private function ruleLabel(array $group): string
    {
        $min = (int) $group['min'];
        $max = $group['max'];
        $rule = match (true) {
            $max !== null && $min === $max => ___('Choose :count', ['count' => (string) $max]),
            $max !== null && $min > 0 => ___('Choose :min–:max', ['min' => (string) $min, 'max' => (string) $max]),
            $max !== null => ___('Choose up to :count', ['count' => (string) $max]),
            default => null,
        };

        return implode(' · ', array_filter([Str::ucfirst((string) ($group['required'] ? ___('Required') : ___('Optional'))), $rule]));
    }

    /** @return list<string> */
    private function allergenList(Menu $item): array
    {
        $names = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $item->allergies))) as $id) {
            if (isset($this->allergens[(int) $id])) {
                $names[] = $this->allergens[(int) $id];
            }
        }

        return $names;
    }

    /** @return array<string, mixed> */
    private function attributes(Menu $item): array
    {
        $attributes = json_decode((string) json_encode($item->getAttribute('attributes')), true);

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * The item opens in the storefront's item sheet: the store page with the item's public id as the fragment
     * (P3-05 renders the sheet); a goods store's product is its own page (`?item=`).
     */
    private function itemUrl(Menu $item): string
    {
        $home = route('publicView', ['slug' => $this->store->slug], false);
        // P3-08 (aisle): a product of a goods store is a page of its own (shareable, indexable): `?item=<public id>`
        if (! $this->food && in_array('ship', (array) config('verticals.types.'.($this->store->store_type ?? '').'.fulfillment', []), true)) {
            return $home.'?item='.$item->public_id;
        }

        return $home.'#item-'.$item->public_id;
    }

    /** P6-308: a card / thumb image: width variants (srcset) when they exist locally, else the original upload. */
    /** @return array<string, string> */
    private function cardImage(string $file, string $alt): array
    {
        $v = media_card_variants('storage/menu/', $file, (int) $this->store->id);

        return $v !== null
            ? ['src' => $v['src'], 'srcset' => $v['srcset'], 'alt' => $alt]
            : ['src' => media_url('storage/menu/', $file, (int) $this->store->id), 'alt' => $alt];
    }
}
