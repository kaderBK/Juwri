<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Providers;

use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Menu module's store-owned tables for the store-data registry. Everything follows the store on
 * transfer. A duplicate copies the catalogue — brands, the category tree, items (images by reference: the same
 * file names; remove_file() only unlinks a file nothing points at), the variant matrix, modifier groups, modifiers,
 * their item attachments and combos — with every reference remapped onto the copies and fresh public ids. Item
 * references that cannot be copied are cleared: the kitchen station (another store's row) and the HungerStation
 * catalogue link (an integration); the rating summary restarts with the clone's own reviews.
 *
 * P3-08: size guides are copied with the catalogue (items keep their guide); a variant cell's option-group keys are
 * remapped onto the copied groups; wishlists and back-in-stock requests follow the store and are never copied.
 */
final class MenuStoreData extends StoreData
{
    public function tables(): array
    {
        $publicId = ['public_id' => fn (): string => (string) Str::uuid7()];

        return [
            StoreTable::data('allergies'),
            StoreTable::data('branch_menu'),
            StoreTable::catalog('brands'),
            StoreTable::catalog('categories')->remap(['parent' => '?categories'])->keep(['source_global_id'])->fresh($publicId)
                ->media(['image' => ['public:storage/menu/']]), // P4-07: library images (export + erasure)
            StoreTable::catalog('combo_slot_options')->remap([
                'combo_slot_id' => 'combo_slots', 'combo_id' => 'combos', 'menu_id' => 'items', 'variant_id' => '?menu_variants',
            ]),
            StoreTable::catalog('combo_slots')->remap(['combo_id' => 'combos']),
            StoreTable::catalog('combos')->clear(['branch_id'])->media(['image' => ['public:storage/menu/']]),
            StoreTable::data('image_menus')->media(['image' => ['public:storage/menu/']]),
            StoreTable::catalog('item_modifier_group')->remap([
                'menu_id' => 'items', 'modifier_group_id' => 'modifier_groups', 'modifier_id' => 'modifiers',
            ]),
            StoreTable::catalog('items')
                ->remap(['category_id' => '?categories', 'brand_id' => '?brands', 'size_guide_id' => '?size_guides'])
                ->clear(['station_id', 'hs_sku', 'hs_product_id', 'hs_synced_at', 'hs_sync_status', 'rating_avg'])
                ->set(['rating_count' => 0])
                ->fresh($publicId)
                ->media(['image' => ['public:storage/menu/'], 'gallery_images[]' => ['public:storage/menu/']]),
            StoreTable::data('menu_item_events'),
            // P3-08 bug fix: a matrix cell's {option group id: index} keys follow the copied groups (before: the copy's
            // cells still named the source store's groups, so the duplicate's matrix matched none of its own groups).
            StoreTable::catalog('menu_variants')->remap(['menu_id' => 'items'])->remapKeys(['options' => 'modifier_groups'])->fresh($publicId)
                ->media(['image' => ['public:storage/menu/']]),
            StoreTable::catalog('modifier_groups')->remap(['menu_id' => 'items'])->fresh($publicId),
            StoreTable::catalog('modifiers')->remap(['modifier_group_id' => 'modifier_groups', 'menu_id' => 'items'])
                ->keep(['source_global_id'])->fresh($publicId)->media(['image' => ['public:storage/menu/']]),
            // P3-08: size guides are catalogue content (copied); wishlists and back-in-stock requests are the shoppers'
            // (they follow the store, never copied).
            StoreTable::catalog('size_guides')->fresh($publicId),
            StoreTable::data('stock_alerts'),
            StoreTable::data('wishlist_items'),
        ];
    }
}
