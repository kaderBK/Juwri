<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;

/**
 * P3-10 / D13: "no hours = no bookings". A store takes bookings only when it has opening hours, at least one active
 * staff member doing at least one active service, and its plan allows `booking`. Until then the dashboard shows the
 * setup checklist and the storefront says "Bookings open soon".
 */
final class BookingSetup
{
    /** @return array{hours: bool, services: bool, staff: bool, plan: bool} */
    public static function checklist(Store $store): array
    {
        $id = (int) $store->id;

        return [
            'hours' => BookingHour::query()->where('store_id', $id)->whereNull('staff_id')->where('kind', 'open')->exists(),
            'services' => BookableService::query()->where('store_id', $id)->where('active', true)->exists(),
            'staff' => BookingStaff::query()->where('store_id', $id)->where('active', true)
                ->whereIn('id', fn ($q) => $q->select('staff_id')->from('booking_staff_services')->where('store_id', $id))->exists(),
            'plan' => BookingPlan::storeAllows($store, BookingPlan::BOOKING),
        ];
    }

    public static function isOpen(Store $store): bool
    {
        return ! in_array(false, self::checklist($store), true);
    }

    public static function assertOpen(Store $store): void
    {
        if (! self::isOpen($store)) {
            throw BookingException::notOpen();
        }
    }
}
