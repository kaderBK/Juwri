<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Biolink module's store-owned tables for the store-data registry. A duplicate copies the bio
 * blocks onto the clone's MAIN page (branches are not copied) with the click counter reset; clicks stay behind.
 */
final class BiolinkStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::catalog('bio_blocks')->set(['branch_id' => null, 'clicks_cached' => 0]),
            StoreTable::data('bio_clicks'),
        ];
    }
}
