<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use App\Models\MenuCategory;
use Keepsuit\Liquid\Attributes\Cache;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: a menu category and the items grouped under it. Items are passed in
 * (already global-modifier-merged + branch-adjusted by StoreController), not
 * re-queried, so the Drop performs no DB work during render.
 */
class CategoryDrop extends Drop
{
    /** @param  array<int,\Juwri\Modules\Menu\Models\Menu>  $items */
    public function __construct(protected MenuCategory $category, protected array $items = []) {}

    public function id(): int
    {
        return (int) $this->category->id;
    }

    /** Translated name (MenuCategory::getNameAttribute). */
    public function name(): ?string
    {
        return $this->category->name;
    }

    public function image_url(): ?string
    {
        return $this->category->image_url;
    }

    #[Cache]
    public function items(): array
    {
        return array_map(fn ($m) => new ItemDrop($m), $this->items);
    }
}
