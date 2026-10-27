<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\PlanGate;

/**
 * P3-10: plan gate names of the booking engine (owner Q2: Community, Cloud plans may limit it) — `booking` (storefront
 * bookings), `booking_api` (headless booking API, read by P3-06b phase D) and `whatsapp_order_alerts` (WhatsApp
 * reminders).
 *
 * The rule itself lives in Core's {@see PlanGate} (a plan that holds no value for a key keeps the feature; a value
 * withholds it; kill switch and ban veto; Community grants every key; no owner or a resolver failure keeps the
 * feature). This class only names the keys the engine reads.
 */
final class BookingPlan
{
    public const BOOKING = 'booking';

    public const BOOKING_API = 'booking_api';

    public const WHATSAPP_ALERTS = 'whatsapp_order_alerts';

    public static function allows(?User $owner, string $key): bool
    {
        return PlanGate::allows($owner, $key);
    }

    public static function storeAllows(Store|int|null $store, string $key): bool
    {
        return PlanGate::storeAllows($store, $key);
    }
}
