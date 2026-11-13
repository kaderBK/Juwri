<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;

use Juwri\Modules\Menu\Services\Catalog\CatalogExport;

/**
 * N2-P2 — builds Keeta's full-replace menu payload from the canonical CatalogExport
 * (I0). Keeta's ONLY menu mode is full replace via `merchantUpdate`, so every push
 * carries the WHOLE menu.
 *
 * Hierarchy: Shop → ShopCategory → SPU (product) → SKU (buyable) → ChoiceGroups
 * (modifiers) → ChoiceGroupSkus (options). ID mode = OpenItemCode (LOCKED): every code
 * is derived from our own IDs so re-pushes are stable and orders map straight back.
 *
 * Compliance: each SKU carries SFDA allergen enums (KeetaAllergenMap). Anything that
 * can't be mapped is surfaced in `unmapped_allergens` — NEVER silently dropped.
 * Sold-out items are excluded (Keeta has no "hidden but present" state in a full push).
 */
class KeetaMenuPusher
{
    public function __construct(private ?CatalogExport $catalog = null)
    {
        $this->catalog = $catalog ?: new CatalogExport;
    }

    public function build(int $storeId, ?int $branchId = null, ?string $storeCode = null): array
    {
        $tree = $this->catalog->forRestaurant($storeId, $branchId, true);
        $currency = $tree['currency'];
        $unmappedAllergens = [];

        $categories = [];
        $spus = [];

        foreach ($tree['categories'] as $cat) {
            $catCode = 'cat_'.$cat['id'];
            $categories[] = [
                'code' => $catCode,
                'name' => $this->names($cat['name']),
                'sort' => $cat['sort'],
            ];

            foreach ($cat['items'] as $item) {
                if (! empty($item['sold_out'])) {
                    continue; // excluded from a full-replace push
                }

                [$sfda, $unmapped] = KeetaAllergenMap::map($item['allergens'] ?? []);
                if ($unmapped) {
                    $unmappedAllergens = array_merge($unmappedAllergens, $unmapped);
                }

                $price = $item['branch_price'] ?? $item['price'];

                $spus[] = [
                    'code' => 'spu_'.$item['id'],
                    'categoryCode' => $catCode,
                    'name' => $this->names($item['name']),
                    'description' => $this->names($item['description'] ?? []),
                    'image' => $item['image_url'],        // 4:3 preferred; Keeta auto-crops
                    'skus' => [[
                        'code' => 'sku_'.$item['id'],
                        'price' => $this->cents($price),
                        'currency' => $currency,
                        'calories' => $item['calories'] ?? null,
                        'sfdaAllergens' => $sfda,
                        'available' => (bool) ($item['available'] ?? true),
                        'choiceGroups' => $this->choiceGroups($item['modifier_groups'] ?? [], $currency),
                    ]],
                ];
            }
        }

        return [
            'shop' => [
                'code' => $storeCode ?: ('shop_'.$storeId),
                'name' => ['en' => (string) ($tree['store_id'])],
            ],
            'categories' => $categories,
            'spus' => $spus,
            'currency' => $currency,
            'unmapped_allergens' => array_values(array_unique($unmappedAllergens)),
        ];
    }

    /** @return array<int, array> Keeta ChoiceGroups from our canonical modifier_groups. */
    private function choiceGroups(array $groups, string $currency): array
    {
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'code' => 'cg_'.$g['id'],
                'name' => $this->names($g['name']),
                'required' => (bool) $g['required'],
                'min' => (int) $g['min'],
                'max' => (int) $g['max'],
                'choiceGroupSkus' => array_map(fn ($o) => [
                    'code' => 'cgs_'.$o['id'],
                    'name' => $this->names($o['name']),
                    'price' => $this->cents($o['price'] ?? 0),
                    'currency' => $currency,
                ], $g['options'] ?? []),
            ];
        }

        return $out;
    }

    /** Keep only en/ar (+zh-Hant if present) locales Keeta accepts; fall back to en. */
    private function names(array $localized): array
    {
        $accepted = array_intersect_key($localized, array_flip(['en', 'ar', 'zh-Hant']));
        if (empty($accepted)) {
            $accepted = ['en' => (string) reset($localized)];
        }

        return $accepted;
    }

    private function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
