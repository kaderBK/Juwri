<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Jahez;

use Juwri\Modules\Menu\Services\Catalog\CatalogExport;

/**
 * N5 — Jahez menu payload from the canonical CatalogExport (I0). Bilingual EN/AR names,
 * branch-specific pricing/visibility, modifiers, and sold-out → auto-disabled on Jahez.
 * ASSUMED shape (bulk category + product upload) — isolated so certification fixes it here.
 */
class JahezMenuPusher
{
    public function __construct(private ?CatalogExport $catalog = null)
    {
        $this->catalog = $catalog ?: new CatalogExport;
    }

    public function build(int $storeId, ?int $branchId = null, ?string $branchCode = null): array
    {
        $tree = $this->catalog->forRestaurant($storeId, $branchId, true);

        $categories = [];
        $products = [];
        foreach ($tree['categories'] as $cat) {
            $categories[] = [
                'code' => 'cat_'.$cat['id'],
                'name' => $cat['name']['en'] ?? '',
                'name_ar' => $cat['name']['ar'] ?? ($cat['name']['en'] ?? ''),
            ];

            foreach ($cat['items'] as $item) {
                $products[] = [
                    'code' => (string) $item['id'],          // OpenItemCode = our menu id
                    'category' => 'cat_'.$cat['id'],
                    'name' => $item['name']['en'] ?? '',
                    'name_ar' => $item['name']['ar'] ?? ($item['name']['en'] ?? ''),
                    'price' => $item['branch_price'] ?? $item['price'],
                    'available' => (bool) ($item['available'] ?? true) && empty($item['sold_out']),
                    'modifiers' => array_map(fn ($g) => [
                        'code' => $g['id'],
                        'name' => $g['name']['en'] ?? '',
                        'name_ar' => $g['name']['ar'] ?? ($g['name']['en'] ?? ''),
                        'required' => $g['required'],
                        'min' => $g['min'],
                        'max' => $g['max'],
                        'options' => array_map(fn ($o) => [
                            'code' => $o['id'], 'name' => $o['name']['en'] ?? '',
                            'name_ar' => $o['name']['ar'] ?? ($o['name']['en'] ?? ''), 'price' => $o['price'] ?? 0,
                        ], $g['options'] ?? []),
                    ], $item['modifier_groups'] ?? []),
                ];
            }
        }

        return [
            'branch' => $branchCode ?: ('branch_'.$storeId),
            'categories' => $categories,
            'products' => $products,
            'currency' => $tree['currency'],
        ];
    }
}
