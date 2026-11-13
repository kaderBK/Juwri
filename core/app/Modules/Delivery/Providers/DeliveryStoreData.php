<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Delivery module's store-owned tables for the store-data registry. Deliveries follow the store;
 * the store's drivers follow it with their owner re-pointed to the new owner (owners manage drivers by owner_id).
 * Never copied.
 */
final class DeliveryStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('courier_deliveries'),
            StoreTable::data('delivery_orders'),
            StoreTable::data('drivers')->repoint(['owner_id'])->secret(['token']),
            StoreTable::data('marketplace_dispatch_offers'), // P1-13 13d follow-up: fleet offers (was Marketplace's)
            StoreTable::data('shipments'),
        ];
    }
}
