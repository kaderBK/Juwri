<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\AggregatorSyncLog;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;

/**
 * The non-India half of the 86-toggle → aggregator bridge.
 *
 * {@see \App\Models\Concerns\SyncsAggregatorAvailability} already fans an item's
 * is_sold_out flip out to Zomato/Swiggy via {@see IndiaAggregatorSync}. But every
 * other connected channel heard nothing — a Deliverect store kept taking orders on
 * an item the kitchen had 86'd, the single most common cause of partner-side
 * cancellation penalties (exactly the gap {@see AggregatorStatusPusher} closed for
 * order status).
 *
 * This is the missing symmetric half. It rides the SAME Menu hook, in the SAME
 * guarded, best-effort shape:
 *   - India providers are skipped here (IndiaAggregatorSync owns them);
 *   - a provider that cannot 86 an item (no {@see PushesItemAvailability}) is a
 *     one-lookup no-op — Uber Eats / Keeta / Jahez have no availability endpoint yet,
 *     so nothing is invented for them;
 *   - only ACTIVE connections push, and each channel's failure is isolated so a
 *     partner outage can never fail a menu save.
 */
class AggregatorAvailabilityPusher
{
    /**
     * Fan a menu 86-flip out to every non-India aggregator the store is connected to
     * whose provider supports item availability.
     */
    public static function onItemAvailabilityChanged(int $storeId, int $menuId, bool $available): void
    {
        if ($storeId <= 0) {
            return;
        }

        $connections = AggregatorConnection::where('store_id', $storeId)
            ->where('status', 'active')
            ->get();

        foreach ($connections as $connection) {
            $providerKey = (string) $connection->provider;

            // India (Zomato/Swiggy) is handled by IndiaAggregatorSync on the same hook.
            if (IndiaAggregatorSync::isIndiaProvider($providerKey)) {
                continue;
            }

            $provider = AggregatorRegistry::get($providerKey);
            if (! $provider instanceof PushesItemAvailability) {
                continue; // provider has no availability endpoint — nothing to push
            }

            try {
                $provider->pushItemAvailability($connection, $menuId, $available);
            } catch (\Throwable $e) {
                AggregatorSyncLog::record(
                    $storeId,
                    $providerKey,
                    'failed',
                    'availability #'.$menuId.': '.$e->getMessage()
                );
            }
        }
    }
}
