<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Modules\Menu\Models\Allergy;
use Keepsuit\Liquid\Drop;

/** P2-2: an allergen shown in the dietary filter. */
class AllergyDrop extends Drop
{
    public function __construct(protected Allergy $allergy) {}

    public function id(): int
    {
        return (int) $this->allergy->id;
    }

    public function title(): ?string
    {
        return $this->allergy->title;
    }

    /** Raw stored filename — theme builds the URL via the `media_url` filter. */
    public function image(): ?string
    {
        return $this->allergy->image;
    }
}
