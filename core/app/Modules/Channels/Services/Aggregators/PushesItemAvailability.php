<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use Juwri\Modules\Channels\Models\AggregatorConnection;

/**
 * Optional capability: a provider that can push a single item's 86 (out-of-stock)
 * state to the partner.
 *
 * Deliberately kept OFF the base {@see AggregatorProvider} contract — the same
 * reasoning that made pushStatus() a default no-op rather than a required verb. A
 * provider with no availability endpoint (Uber Eats / Keeta / Jahez today) is simply
 * "not capable"; the availability fan-out ({@see AggregatorAvailabilityPusher})
 * skips it instead of forcing it to carry a meaningless no-op. India (Zomato/Swiggy)
 * has its own richer availability service and does NOT go through this interface.
 */
interface PushesItemAvailability
{
    /**
     * Push ONE item's stock state to the partner. $available === false means 86 /
     * snooze the item; true means make it orderable again. Best-effort — may throw on
     * a partner error, which the fan-out catches and logs.
     */
    public function pushItemAvailability(AggregatorConnection $connection, int $menuId, bool $available): void;
}
