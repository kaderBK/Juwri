<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Zomato;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Catalog\CatalogExport;

/**
 * PARITY-RUN T7 — builds the Zomato menu payload from the canonical CatalogExport
 * (I0), so menu structure is mapped ONCE for every driver rather than per partner.
 *
 * ── PRICE CONTRACT (W2/T5 seam — read before changing a line of this file) ──
 * CatalogExport publishes LIST prices (`price` / `branch_price`), deliberately NOT
 * the resolved happy-hour price from `MenuScheduleService`. A partner catalogue is
 * a long-lived, human-moderated artefact: pushing a 17:00–19:00 half-price into it
 * FREEZES that price on Zomato until the next successful push, and a failed or
 * throttled push leaves the store selling at the discount all day. Scheduled
 * pricing therefore stays a first-party (storefront/POS) capability, and this
 * pusher intentionally reads `branch_price ?? price`. The deferral is logged in
 * rev/parity/STATUS.md ("Aggregator menus keep list prices"). If that owner
 * decision is ever reversed it needs a scheduled RE-push at every window boundary,
 * not a different line here.
 *
 * Everything else is ASSUMED until certification (BD-ACTIONS #11) and confined to
 * this file + ZomatoPayloadMapper.
 */
class ZomatoMenuPusher
{
    public function __construct(private ?CatalogExport $catalog = null)
    {
        $this->catalog = $catalog ?: new CatalogExport;
    }

    public function build(int $storeId, ?int $branchId = null, ?string $outletId = null): array
    {
        $tree = $this->catalog->forRestaurant($storeId, $branchId, true);

        // Veg / non-veg marking is mandatory on Indian marketplaces and is NOT part
        // of the canonical export, so it is looked up alongside rather than by
        // widening a contract five other drivers depend on.
        $veg = Menu::where('store_id', $storeId)->pluck('is_vegan', 'id');

        $categories = [];
        $items = [];

        foreach ($tree['categories'] as $cat) {
            $categories[] = [
                'category_id' => 'zcat_'.$cat['id'],
                'name' => $cat['name']['en'] ?? reset($cat['name']) ?: '',
                'sort_order' => $cat['sort'] ?? 0,
            ];

            foreach ($cat['items'] as $item) {
                $items[] = [
                    'item_id' => ZomatoPayloadMapper::ITEM_CODE_PREFIX.$item['id'],
                    'category_id' => 'zcat_'.$cat['id'],
                    'name' => $item['name']['en'] ?? reset($item['name']) ?: '',
                    'description' => $item['description']['en'] ?? '',
                    // LIST price on purpose — see the price contract above.
                    'price' => (float) ($item['branch_price'] ?? $item['price']),
                    'in_stock' => (bool) ($item['available'] ?? true) && empty($item['sold_out']),
                    'food_type' => ! empty($veg[$item['id']]) ? 'veg' : 'non_veg',
                    'image_url' => $item['image_url'],
                    'allergens' => $item['allergens'] ?? [],
                    'variant_groups' => array_map(fn ($g) => [
                        'group_id' => (string) $g['id'],
                        'name' => $g['name']['en'] ?? reset($g['name']) ?: '',
                        'min' => $g['min'],
                        'max' => $g['max'],
                        'required' => $g['required'],
                        'options' => array_map(fn ($o) => [
                            'option_id' => (string) $o['id'],
                            'name' => $o['name']['en'] ?? reset($o['name']) ?: '',
                            'price' => (float) ($o['price'] ?? 0),
                        ], $g['options'] ?? []),
                    ], $item['modifier_groups'] ?? []),
                ];
            }
        }

        return [
            'res_id' => $outletId ?: ('zomato_'.$storeId),
            'currency' => $tree['currency'],
            'categories' => $categories,
            'items' => $items,
            'pushed_at' => $tree['generated_at'],
            // Explicit so a reviewer of a captured payload can see WHICH price
            // basis went over the wire without reading this class.
            'price_basis' => 'list',
        ];
    }
}
