<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * The `vocab` global — the store's vertical UI nouns, already translated to the
 * current locale, so a Liquid theme can render "Products" / "Book" / "Catalog"
 * for a goods / services / stays store instead of the hardcoded food words. A
 * restaurant (food) store gets today's EXACT wording, so first-party food themes
 * stay byte-identical. Themes read {{ vocab.items }}, {{ vocab.add_to_cart }}, …
 * and branch layout on {{ vocab.group }}.
 *
 * The noun set is resolved server-side (VerticalVocab → config/verticals.vocab →
 * ___()) and handed in as a FLAT translated map, so — like every other Drop — no
 * service, model, config or untranslated key is reachable from the sandbox.
 */
class VocabDrop extends Drop
{
    /**
     * @param  array<string,string>  $words  translated vocab map (VerticalVocab::map)
     * @param  string  $group  food|goods|services|stays — for theme-level branching
     */
    public function __construct(protected array $words = [], protected string $group = 'food') {}

    /** food|goods|services|stays — lets a theme branch layout on the vertical group. */
    public function group(): string
    {
        return $this->group;
    }

    public function item(): string
    {
        return $this->word('item', 'Dish');
    }

    public function items(): string
    {
        return $this->word('items', 'Dishes');
    }

    public function catalog(): string
    {
        return $this->word('catalog', 'Menu');
    }

    public function category(): string
    {
        return $this->word('category', 'Category');
    }

    public function categories(): string
    {
        return $this->word('categories', 'Categories');
    }

    public function create_item(): string
    {
        return $this->word('create_item', 'Add dish');
    }

    public function add_to_cart(): string
    {
        return $this->word('add_to_cart', 'Add to order');
    }

    public function order(): string
    {
        return $this->word('order', 'Order');
    }

    public function orders(): string
    {
        return $this->word('orders', 'Orders');
    }

    public function board(): string
    {
        return $this->word('board', 'Kitchen');
    }

    protected function word(string $key, string $fallback): string
    {
        return $this->words[$key] ?? $fallback;
    }
}
