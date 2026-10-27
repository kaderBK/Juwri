<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services;

use Juwri\Core\Contracts\Stores\StoreCatalogProbe;
use Juwri\Modules\Menu\Models\Menu;

final class StoreMenuProbe implements StoreCatalogProbe
{
    public function hasItems(int $storeId): bool
    {
        return Menu::where('store_id', $storeId)->exists();
    }
}
