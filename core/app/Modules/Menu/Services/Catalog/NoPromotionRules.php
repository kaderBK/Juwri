<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Catalog;

use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Models\Menu;

/** P1-23: the Community default of {@see PromotionRules} — no item is excluded from promotions. */
final class NoPromotionRules implements PromotionRules
{
    public function blocksPromotions(Menu $item): bool
    {
        return false;
    }

    public function storeBlocksPromotions(int $storeId): bool
    {
        return false;
    }

    public function reason(): string
    {
        return '';
    }
}
