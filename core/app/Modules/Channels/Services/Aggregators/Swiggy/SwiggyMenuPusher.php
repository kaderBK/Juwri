<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Swiggy;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Catalog\CatalogExport;

/**
 * PARITY-RUN T7 — builds the Swiggy menu payload from the canonical CatalogExport
 * (I0). Swiggy models a menu as category → item → variant/addon groups, so the
 * canonical modifier groups map straight across.
 *
 * ── PRICE CONTRACT (W2/T5 seam) ──
 * CatalogExport publishes LIST prices, deliberately NOT the resolved happy-hour
 * price from `MenuScheduleService`: a partner catalogue is long-lived and
 * moderated, so pushing a 17:00–19:00 price into it FREEZES that price on Swiggy
 * until the next successful push. Scheduled pricing stays first-party. See the
 * identical note in ZomatoMenuPusher and the ledger row in rev/parity/STATUS.md.
 *
 * Everything else is ASSUMED until onboarding (BD-ACTIONS #12).
 */
class SwiggyMenuPusher
{
    public function __construct(private ?CatalogExport $catalog = null)
    {
        $this->catalog = $catalog ?: new CatalogExport;
    }

    public function build(int $storeId, ?int $branchId = null, ?string $outletId = null): array
    {
        $tree = $this->catalog->forRestaurant($storeId, $branchId, true);

        // Veg / non-veg marking is mandatory on Indian marketplaces and is not part
        // of the canonical export — looked up alongside rather than by widening a
        // contract five other drivers depend on.
        $veg = Menu::where('store_id', $storeId)->pluck('is_vegan', 'id');

        $categories = [];
        $items = [];

        foreach ($tree['categories'] as $cat) {
            $categories[] = [
                'id' => 'scat_'.$cat['id'],
                'name' => $cat['name']['en'] ?? reset($cat['name']) ?: '',
                'order' => $cat['sort'] ?? 0,
            ];

            foreach ($cat['items'] as $item) {
                $items[] = [
                    'id' => SwiggyPayloadMapper::ITEM_CODE_PREFIX.$item['id'],
                    'category_id' => 'scat_'.$cat['id'],
                    'name' => $item['name']['en'] ?? reset($item['name']) ?: '',
                    'description' => $item['description']['en'] ?? '',
                    // LIST price on purpose — see the price contract above.
                    'price' => (float) ($item['branch_price'] ?? $item['price']),
                    'enabled' => (bool) ($item['available'] ?? true) && empty($item['sold_out']),
                    'is_veg' => ! empty($veg[$item['id']]),
                    'image' => $item['image_url'],
                    'allergens' => $item['allergens'] ?? [],
                    'addon_groups' => array_map(fn ($g) => [
                        'id' => (string) $g['id'],
                        'name' => $g['name']['en'] ?? reset($g['name']) ?: '',
                        'min_selection' => $g['min'],
                        'max_selection' => $g['max'],
                        'mandatory' => $g['required'],
                        'addons' => array_map(fn ($o) => [
                            'id' => (string) $o['id'],
                            'name' => $o['name']['en'] ?? reset($o['name']) ?: '',
                            'price' => (float) ($o['price'] ?? 0),
                        ], $g['options'] ?? []),
                    ], $item['modifier_groups'] ?? []),
                ];
            }
        }

        return [
            'restaurant_id' => $outletId ?: ('swiggy_'.$storeId),
            'currency' => $tree['currency'],
            'categories' => $categories,
            'items' => $items,
            'pushed_at' => $tree['generated_at'],
            'price_basis' => 'list',
        ];
    }
}
