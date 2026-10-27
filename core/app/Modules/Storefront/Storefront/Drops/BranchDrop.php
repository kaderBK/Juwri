<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Core\Models\Branch;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: a branch. Open/accepting state is pre-resolved to booleans here so a
 * theme never re-implements the opening-hours logic (§4).
 */
class BranchDrop extends Drop
{
    public function __construct(protected Branch $branch) {}

    public function id(): int
    {
        return (int) $this->branch->id;
    }

    public function name(): ?string
    {
        return $this->branch->name;
    }

    /** Within opening hours right now. */
    public function is_open(): bool
    {
        return $this->branch->isOpenNow();
    }

    /** Open AND actively accepting orders. */
    public function accepts_orders(): bool
    {
        return $this->branch->canAcceptOrders();
    }

    /** 'open' | 'closed' | 'paused' | 'inactive'. */
    public function status(): string
    {
        return $this->branch->statusLabel();
    }
}
