<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Stores;

/** P6-108b: does a store already have menu items (bound by the Menu module). */
interface StoreCatalogProbe
{
    public function hasItems(int $storeId): bool;
}
