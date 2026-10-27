<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Modules\Menu\Models\MenuVariant;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: a single priced variant row (e.g. a size) of a menu item. `options` on
 * the model is a combination MAP ({groupId: choiceIdx}), not a label — the
 * human label is resolved against the item's variant dimensions by ItemDrop
 * and passed in here.
 */
class VariantDrop extends Drop
{
    public function __construct(protected MenuVariant $variant, protected ?string $label = null) {}

    public function id(): int
    {
        return (int) $this->variant->id;
    }

    /** Human label for the combination (e.g. "Large"). */
    public function name(): string
    {
        return $this->label ?? 'Option';
    }

    public function price(): float
    {
        return (float) $this->variant->price;
    }
}
