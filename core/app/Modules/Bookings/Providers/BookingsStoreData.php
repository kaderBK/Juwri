<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P3-10: the Bookings module's store-owned tables — all business data of the store: they follow it on transfer and
 * are never copied (a copied store starts without staff calendars, holds or bookings).
 */
final class BookingsStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('booking_staff'),
            StoreTable::data('booking_services'),
            StoreTable::data('booking_staff_services'),
            StoreTable::data('booking_hours'),
            StoreTable::data('booking_closed_days'),
            StoreTable::data('bookings'),
            StoreTable::data('booking_lines'),
            StoreTable::data('booking_holds'),
            StoreTable::data('booking_slot_cells'),
            StoreTable::data('booking_pending_refunds'),
        ];
    }
}
