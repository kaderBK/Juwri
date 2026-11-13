<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Attributes\Cache;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: the `menu` global — categories, each with its items. Built from the
 * grouped structure ThemeContext assembles out of `$total_menus` + the
 * category tree (§4).
 */
class MenuDrop extends Drop
{
    /** @param  array<int,CategoryDrop>  $categories */
    public function __construct(protected array $categories = []) {}

    public function categories(): array
    {
        return $this->categories;
    }

    /** Flat list of every item across all categories. */
    #[Cache]
    public function items(): array
    {
        $items = [];
        foreach ($this->categories as $category) {
            foreach ($category->items() as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function is_empty(): bool
    {
        return $this->categories === [];
    }
}
