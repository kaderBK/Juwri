<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Modules\Menu\Models\MenuVariantOption;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: a selectable option group (e.g. "Choose a sauce") with its selection
 * rules. Rule fields (required/min/max) may have been overridden in-memory
 * from the global-modifier pivot before the Drop was built — see StoreController.
 */
class OptionGroupDrop extends Drop
{
    public function __construct(protected MenuVariantOption $group) {}

    public function id(): int
    {
        return (int) $this->group->id;
    }

    public function name(): ?string
    {
        return $this->group->title;
    }

    public function required(): bool
    {
        return (bool) $this->group->required;
    }

    public function min_select(): int
    {
        return (int) ($this->group->min_select ?? 0);
    }

    public function max_select(): int
    {
        return (int) ($this->group->max_select ?? 0);
    }

    /**
     * Choice list with per-choice ADDITIVE prices:
     * [{i, label, price}] — label from `options`, price from `option_prices`.
     */
    public function choices(): array
    {
        $labels = (array) ($this->group->options ?? []);
        $prices = (array) ($this->group->option_prices ?? []);

        $out = [];
        $i = 0;
        foreach ($labels as $label) {
            $out[] = [
                'i' => $i,
                'label' => (string) $label,
                'price' => (float) ($prices[$i] ?? 0),
            ];
            $i++;
        }

        return $out;
    }
}
