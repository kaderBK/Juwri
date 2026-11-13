<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Modules\Menu\Models\MenuExtra;
use Keepsuit\Liquid\Drop;

/** P2-2: an optional add-on (extra) for a menu item, price already resolved. */
class ExtraDrop extends Drop
{
    public function __construct(protected MenuExtra $extra) {}

    public function id(): int
    {
        return (int) $this->extra->id;
    }

    public function name(): ?string
    {
        return $this->extra->title;
    }

    public function price(): float
    {
        return (float) $this->extra->price;
    }
}
