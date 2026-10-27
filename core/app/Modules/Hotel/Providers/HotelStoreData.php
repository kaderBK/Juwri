<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Hotel module's store- and account-owned tables for the store-data registry — bookings, stays and the room catalogue follow the store; not copied (P1-21 part 1 keeps the duplicate set of today).
 */
final class HotelStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('hotel_booking_nights'),
            StoreTable::data('hotel_bookings'),
            StoreTable::data('hotel_event_items'),
            StoreTable::data('hotel_events'),
            StoreTable::data('hotel_extra_services'),
            StoreTable::data('hotel_stays'),
            StoreTable::data('housekeeping_tasks'),
            StoreTable::data('rate_plans'),
            StoreTable::data('room_rates'),
            StoreTable::data('room_types'),
            StoreTable::data('service_requests'),
        ];
    }
}
