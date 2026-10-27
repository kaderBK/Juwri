<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Reservations module's store- and account-owned tables for the store-data registry — every table is business data of the store: follows it on transfer, never copied.
 */
final class ReservationsStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('reservations'),
            StoreTable::data('waitlist_entries'),
        ];
    }
}
