<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P3-08 (aisle §6, kit/api `GET /catalog` `filter[...]`): the facets of a goods catalogue and what each item offers
 * in them.
 *
 *  - A facet per option-group title across the store's items (e.g. `size`, `colour` — the slug of the title, so the
 *    same "Size" group on many items is one facet), values = the slugs of the option labels. A swatch group is a
 *    `swatch` facet (with the colours), a size group a `size` facet, any other a `checkbox` facet. A value is
 *    `available` when some item offers it in stock (a matrix option counts only while one of its cells is not out).
 *  - `brand`: the item's brand.
 *
 * {@see index()} is one item's entry (price for the range filter and sorting, in-stock flag, facet values);
 * {@see facets()} turns the definitions into View Model `Facet`s with counts and the selected values.
 */
final class RetailFacets
{
    /** @var array<string, array{label: string, kind: string, options: array<string, array{label: string, swatch?: string}>}> */
    private array $definitions = [];

    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly RetailCatalog $retail,
        private readonly Store $store,
    ) {}

    /**
     * @param  array<string, mixed>  $card  the item's ItemCard
     * @return array{price: int, in_stock: bool, values: array<string, list<string>>, available: array<string, list<string>>, newest: int}
     */
    public function index(Menu $item, array $card, bool $food): array
    {
        $price = (int) (($card['price'] ?? $card['from_price'] ?? ['minor' => 0])['minor']);
        $values = [];
        $available = [];
        $inStock = ! (bool) $item->is_sold_out && $this->retail->stockState($item)['state'] !== 'out';
        if (! $food) {
            $matrix = $this->retail->matrix($this->store, $item);
            $optionInStock = [];
            foreach ($matrix as $cell) {
                if ($cell['stock']['state'] !== 'out') {
                    foreach ($cell['options'] as $optionId) {
                        $optionInStock[$optionId] = true;
                    }
                }
            }
            if ($matrix !== [] && $optionInStock === []) {
                $inStock = false; // every cell is out
            }
            $axisOptions = [];
            foreach ($matrix as $cell) {
                foreach ($cell['options'] as $optionId) {
                    $axisOptions[$optionId] = true;
                }
            }

            foreach ($this->catalog->optionGroups($item) as $group) {
                if ($group['kind'] !== 'variant') {
                    continue;
                }
                $facet = self::slug((string) $group['title'], 'g-'.$group['public_id']);
                $display = $group['display'] ?? null;
                $this->definitions[$facet] ??= [
                    'label' => (string) $group['title'],
                    'kind' => $display === 'swatch' ? 'swatch' : ($display === 'size' ? 'size' : 'checkbox'),
                    'options' => [],
                ];
                foreach ($group['options'] as $option) {
                    if (! $option['active']) {
                        continue;
                    }
                    $value = self::slug((string) $option['title'], 'o-'.$option['public_id']);
                    $this->definitions[$facet]['options'][$value] ??= ['label' => (string) $option['title']] + (isset($option['swatch']) ? ['swatch' => $option['swatch']] : []);
                    $values[$facet][] = $value;
                    if ($inStock && (! isset($axisOptions[$option['public_id']]) || isset($optionInStock[$option['public_id']]))) {
                        $available[$facet][] = $value;
                    }
                }
            }
            if ($item->brand !== null && trim((string) $item->brand->name) !== '') {
                $value = self::slug((string) ($item->brand->slug ?: $item->brand->name), 'b-'.$item->brand->id);
                $this->definitions['brand'] ??= ['label' => ___('Brand'), 'kind' => 'checkbox', 'options' => []];
                $this->definitions['brand']['options'][$value] ??= ['label' => (string) $item->brand->name];
                $values['brand'][] = $value;
                if ($inStock) {
                    $available['brand'][] = $value;
                }
            }
        }

        return ['price' => $price, 'in_stock' => $inStock, 'values' => $values, 'available' => $available, 'newest' => (int) $item->id];
    }

    /**
     * View Model `Facet`s: every value with the number of matching items (with the other facets' filters applied),
     * `selected` for the filter's values, `available` false for a value no item has in stock.
     *
     * @param  array<string, array{values: array<string, list<string>>, available: array<string, list<string>>}>  $index  item id => entry
     * @param  array<string, list<string>>  $selected  facet id => values
     * @param  callable(string): list<string>  $matchingWithout  item ids matching every filter except this facet's
     * @return list<array<string, mixed>>
     */
    public function facets(array $index, array $selected, callable $matchingWithout): array
    {
        $out = [];
        foreach ($this->definitions as $facet => $definition) {
            $ids = $matchingWithout($facet);
            $counts = [];
            $inStock = [];
            foreach ($ids as $id) {
                foreach (array_unique($index[$id]['values'][$facet] ?? []) as $value) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
                foreach (array_unique($index[$id]['available'][$facet] ?? []) as $value) {
                    $inStock[$value] = true;
                }
            }
            $options = [];
            foreach ($definition['options'] as $value => $option) {
                $row = ['value' => $value, 'label' => $option['label'], 'count' => $counts[$value] ?? 0];
                if (isset($option['swatch'])) {
                    $row['swatch'] = $option['swatch'];
                }
                if (in_array($value, $selected[$facet] ?? [], true)) {
                    $row['selected'] = true;
                }
                if (! isset($inStock[$value])) {
                    $row['available'] = false;
                }
                $options[] = $row;
            }
            if ($options !== []) {
                $out[] = ['id' => $facet, 'label' => $definition['label'], 'kind' => $definition['kind'], 'options' => $options];
            }
        }

        return $out;
    }

    /** @return list<string> facet ids found by {@see index()} */
    public function ids(): array
    {
        return array_keys($this->definitions);
    }

    public static function slug(string $text, string $fallback): string
    {
        $slug = Str::slug($text);

        return $slug !== '' ? mb_substr($slug, 0, 60) : $fallback;
    }
}
