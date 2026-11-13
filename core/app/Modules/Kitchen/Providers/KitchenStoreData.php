<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Kitchen module's store- and account-owned tables for the store-data registry — every table is business data of the store: follows it on transfer, never copied.
 */
final class KitchenStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('checklist_runs'),
            StoreTable::data('checklist_templates'),
            StoreTable::data('food_safety_checks'),
            StoreTable::data('food_safety_logs'),
            StoreTable::data('item_kitchen_stations'),
            StoreTable::data('kitchen_stations'),
            StoreTable::data('print_jobs'),
            StoreTable::data('printer_profiles'),
        ];
    }
}
