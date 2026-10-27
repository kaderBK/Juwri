<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/** P2-2: the `stats` global — social-proof numbers only. */
class StatsDrop extends Drop
{
    public function __construct(protected int $scansToday = 0) {}

    public function scans_today(): int
    {
        return $this->scansToday;
    }
}
