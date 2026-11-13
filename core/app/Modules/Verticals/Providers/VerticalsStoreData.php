<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Verticals module's store-owned tables for the store-data registry. A duplicate copies the
 * car-parts fitments (YMM rows) onto the copied items (fitment CSV imports are business data: not copied); skin profiles are customer data and never copied.
 */
final class VerticalsStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('skin_profiles'),
            StoreTable::catalog('vehicle_fitments')->remap(['menu_id' => 'items']),
            StoreTable::data('fitment_imports')->files(['path' => 'local']), // P3-08b: uploads and their reports belong to the store; never copied (P4-07: the CSV file too)
        ];
    }
}
