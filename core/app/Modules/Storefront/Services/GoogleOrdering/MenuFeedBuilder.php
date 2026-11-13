<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\ViewModel\CatalogPresenter;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;

/**
 * P5-20 — Google Ordering Redirect Menu feed.
 *
 * One menu per eligible merchant: its storefront sections (categories) and items, read through
 * the SAME path the storefront uses (Menu's StorefrontCatalog + CatalogPresenter + MoneyFormat),
 * so the feed and the storefront never disagree on price or availability. Every item is
 * store-scoped: the query names the store, and only that store's rows are emitted.
 *
 * Per item: name, description, price (integer minor + ISO currency), image url, dietary tags
 * (only when the data exists) and availability.
 */
final class MenuFeedBuilder
{
    public function __construct(private readonly GoogleOrderingMerchantSource $source) {}

    public function filename(): string
    {
        return 'menu_feed.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $catalog = app(StorefrontCatalog::class);
        $menus = [];

        foreach ($this->source->merchants() as $m) {
            $store = $m['store'];
            $branch = $m['branch'];
            $money = new MoneyFormat($store);
            $food = $store->isFoodVertical();

            // null order type = the store's full menu (no per-flow availability filter).
            $items = $catalog->items($store, $branch, null);
            $presenter = new CatalogPresenter($catalog, $store, $money, $food, false);
            $built = $presenter->catalog($items);
            $byId = $items->keyBy(static fn (Menu $item): string => (string) $item->public_id);

            $sections = [];
            foreach ($built['categories'] as $category) {
                $rows = [];
                foreach ($category['item_ids'] as $publicId) {
                    $card = $built['items'][$publicId] ?? null;
                    $item = $byId->get($publicId);
                    if (! is_array($card) || ! $item instanceof Menu) {
                        continue;
                    }
                    $rows[] = $this->row($card, $item);
                }
                $sections[] = ['name' => (string) $category['name'], 'item' => $rows];
            }

            $menus[] = ['merchant_id' => $m['merchant_id'], 'section' => $sections];
        }

        return [
            'metadata' => GoogleOrderingFeedBuilder::metadata(),
            'menu'     => $menus,
        ];
    }

    /**
     * @param  array<string, mixed>  $card  an ItemCard from CatalogPresenter
     * @return array<string, mixed>
     */
    private function row(array $card, Menu $item): array
    {
        $row = [
            'id'        => (string) $card['id'],
            'name'      => (string) $card['name'],
            'available' => ! (bool) ($card['sold_out'] ?? false),
        ];

        $description = trim(strip_tags((string) $item->description));
        if ($description !== '') {
            $row['description'] = $description;
        }

        $price = $card['price'] ?? $card['from_price'] ?? null;
        if (is_array($price)) {
            $row['price'] = $price;
        }

        if (isset($card['image']['src'])) {
            $row['image'] = (string) $card['image']['src'];
        }

        $tags = $this->dietaryTags($item);
        if ($tags !== []) {
            $row['dietary_tags'] = $tags;
        }

        return $row;
    }

    /**
     * The item's diet facts, from its own `dietary_tags` plus its vegan / halal / gluten-free flags.
     *
     * @return list<string>
     */
    private function dietaryTags(Menu $item): array
    {
        $tags = [];
        foreach ((array) ($item->dietary_tags ?? []) as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[] = trim($tag);
            }
        }
        if ((bool) $item->is_vegan) {
            $tags[] = 'vegan';
        }
        if ((bool) $item->is_halal) {
            $tags[] = 'halal';
        }
        if ((bool) $item->is_gluten_free) {
            $tags[] = 'gluten_free';
        }

        return array_values(array_unique($tags));
    }
}
