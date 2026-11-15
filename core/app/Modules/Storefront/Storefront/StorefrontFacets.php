<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Illuminate\Support\Str;

/**
 * The catalogue facets of a storefront: the SIZES, the COLOURS and the PRICE
 * range a customer can filter a goods shop by.
 *
 * The platform already knows this — a variant carries its matrix cell
 * (`{"Size":"M","Color":"Red"}`), and the platform's own retail storefront
 * (maison) paints its colour rail and size pills from it. This service is
 * that knowledge in one place, so the Liquid runtime's filter panel and the
 * first-party storefronts cannot drift apart.
 *
 * Two rules worth stating:
 *
 *  · an AXIS is found by its NAME, case-insensitively and as a substring —
 *    "Size", "Sizes", "SIZE" and "المقاس" all mean the size axis; "Color"
 *    and "Colour" both mean the colour axis. A merchant types the name, the
 *    platform reads it.
 *  · an item matches a filter when ONE OF ITS VARIANTS satisfies every
 *    chosen facet. A shop with "M / Red" in stock is a "size M" shop and a
 *    "red" shop, but not necessarily both at once — the variant-level rule
 *    is the only one that never shows an item the customer cannot buy.
 */
final class StorefrontFacets
{
    /** The facet key for the size axis. */
    public const SIZE = 'size';

    /** The facet key for the colour axis. */
    public const COLOR = 'color';

    /**
     * The axis names a variant's matrix uses, as ['size' => 'Size',
     * 'color' => 'Color'] — a null for an axis the variant does not carry.
     *
     * @param  array<string,string>  $dims  the variant's matrix cell
     * @return array{size:?string,color:?string}
     */
    public static function axes(array $dims): array
    {
        $size = null;
        $color = null;
        foreach ($dims as $name => $value) {
            $n = Str::lower((string) $name);
            if ($size === null && str_contains($n, 'size')) {
                $size = (string) $name;
            }
            if ($color === null && str_contains($n, 'colo')) {
                $color = (string) $name;
            }
        }

        return ['size' => $size, 'color' => $color];
    }

    /**
     * One variant's facet values: ['size' => 'M', 'color' => 'Red'] — only
     * the axes the variant actually carries.
     *
     * @param  array<string,string>  $dims
     * @return array<string,string>
     */
    public static function values(array $dims): array
    {
        $axes = self::axes($dims);
        $out = [];
        foreach ([self::SIZE => $axes['size'], self::COLOR => $axes['color']] as $key => $axis) {
            if ($axis === null) {
                continue;
            }
            $value = trim((string) ($dims[$axis] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The store-level facet summary: the sizes on offer, the colours on
     * offer (with their swatch) and the price range.
     *
     * @param  array<int,array<string,mixed>>  $items  the runtime's item rows
     * @return array{sizes:list<string>,colors:list<array{value:string,hex:?string}>,priceMin:float,priceMax:float}|array{}
     */
    public static function summary(array $items): array
    {
        $sizes = [];
        $colors = [];
        $min = null;
        $max = null;

        foreach ($items as $item) {
            $variants = (array) ($item['variants'] ?? []);
            if ($variants === []) {
                // An item without variants still has a price.
                $price = (float) ($item['price'] ?? 0);
                $min = $min === null ? $price : min($min, $price);
                $max = $max === null ? $price : max($max, $price);

                continue;
            }

            foreach ($variants as $variant) {
                $variant = (array) $variant;
                $price = (float) ($variant['price'] ?? 0);
                $min = $min === null ? $price : min($min, $price);
                $max = $max === null ? $price : max($max, $price);

                $size = (string) ($variant['size'] ?? '');
                if ($size !== '') {
                    $sizes[$size] = true;
                }
                $color = (string) ($variant['color'] ?? '');
                if ($color !== '') {
                    $colors[$color] = true;
                }
            }
        }

        if ($sizes === [] && $colors === []) {
            // No facets at all — a shop with nothing to filter by.
            return [];
        }

        $colorRows = [];
        foreach (array_keys($colors) as $label) {
            $colorRows[] = ['value' => $label, 'hex' => SwatchColor::for($label)];
        }

        return [
            'sizes' => array_map('strval', array_keys($sizes)),
            'colors' => $colorRows,
            'priceMin' => (float) ($min ?? 0),
            'priceMax' => (float) ($max ?? 0),
        ];
    }
}
