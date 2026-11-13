<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Deliverect;

use Juwri\Modules\Menu\Services\Catalog\CatalogExport;

/**
 * N6 — maps the canonical CatalogExport (I0) to Deliverect's product model for the
 * `pos_syncproducts` pull (Deliverect CALLS US to fetch the menu). PLU = our menu id so
 * inbound orders map straight back. Products + categories + modifier groups + options,
 * prices in minor units (cents), sold-out items flagged unavailable.
 */
class DeliverectMenuMapper
{
    public function __construct(private ?CatalogExport $catalog = null)
    {
        $this->catalog = $catalog ?: new CatalogExport;
    }

    public function build(int $storeId, ?int $branchId = null): array
    {
        $tree = $this->catalog->forRestaurant($storeId, $branchId, true);

        $categories = [];
        $products = [];
        $modifierGroups = [];

        foreach ($tree['categories'] as $cat) {
            $categories[] = [
                'plu' => 'cat_'.$cat['id'],
                'name' => $cat['name']['en'] ?? '',
                'productTags' => [],
            ];

            foreach ($cat['items'] as $item) {
                $mgPlus = [];
                foreach ($item['modifier_groups'] ?? [] as $g) {
                    $mgPlu = 'mg_'.$g['id'];
                    $mgPlus[] = $mgPlu;
                    $modifierGroups[$mgPlu] = [
                        'plu' => $mgPlu,
                        'name' => $g['name']['en'] ?? '',
                        'min' => (int) $g['min'],
                        'max' => (int) $g['max'],
                        'subProducts' => array_map(fn ($o) => [
                            'plu' => 'mo_'.$o['id'],
                            'name' => $o['name']['en'] ?? '',
                            'price' => (int) round(((float) ($o['price'] ?? 0)) * 100),
                        ], $g['options'] ?? []),
                    ];
                }

                $products[] = [
                    'plu' => (string) $item['id'],            // PLU = our menu id
                    'name' => $item['name']['en'] ?? '',
                    'description' => $item['description']['en'] ?? '',
                    'price' => (int) round((($item['branch_price'] ?? $item['price'])) * 100),
                    'category' => 'cat_'.$cat['id'],
                    'imageUrl' => $item['image_url'],
                    'available' => (bool) ($item['available'] ?? true) && empty($item['sold_out']),
                    'modifierGroups' => $mgPlus,
                ];
            }
        }

        return [
            'currency' => $tree['currency'],
            'categories' => $categories,
            'products' => $products,
            'modifierGroups' => array_values($modifierGroups),
        ];
    }
}
