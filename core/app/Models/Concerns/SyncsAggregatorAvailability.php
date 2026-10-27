<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models\Concerns;

use Juwri\Core\Support\Hooks;
use Juwri\Modules\Menu\Models\Concerns\FiresMenuWebhook;

/**
 * PARITY-RUN T7 — the 86-toggle → marketplace bridge.
 *
 * An item going out of stock has to reach the aggregators, or the store keeps
 * taking Zomato/Swiggy orders for food it cannot cook — the single most common
 * cause of partner-side cancellation penalties. The toggle is written from at
 * least five places today (dashboard menu form, inventory screen, MCP tool, POS,
 * automatic stock deduction), so this rides the model's `updated` event rather
 * than being sprinkled across those controllers — the same reasoning that put
 * `menu.updated` in {@see FiresMenuWebhook}.
 *
 * Three deliberate guards:
 *  - it only runs when `is_sold_out` ACTUALLY changed, so a name edit costs nothing;
 *  - the fan-out is `try/catch`ed here and again per connection inside the sync
 *    service, so no partner can fail a menu save;
 *  - stores with no Zomato/Swiggy connection pay exactly one indexed query.
 */
trait SyncsAggregatorAvailability
{
    /** When true, availability writes do NOT reach the aggregators. */
    protected static bool $suppressAggregatorAvailabilitySync = false;

    public static function bootSyncsAggregatorAvailability(): void
    {
        static::updated(function ($model) {
            if (static::$suppressAggregatorAvailabilitySync) {
                return;
            }
            if (! $model->wasChanged('is_sold_out')) {
                return;
            }

            $storeId = (int) ($model->store_id ?? 0);
            if ($storeId <= 0) {
                return;
            }

            $available = ! $model->is_sold_out;

            // P1-13: the marketplaces are the Channels module (Menu may not import it). It fills this slot with the
            // India sync, then the non-India pusher (Deliverect today), each best-effort — a partner outage or a
            // channel with no 86 endpoint never fails a menu save.
            Hooks::run('menu.item-availability-changed', $storeId, (int) $model->getKey(), $available);
        });
    }

    /** Run $callback without fanning availability out (bulk imports, seeders). */
    public static function withoutAggregatorAvailabilitySync(callable $callback)
    {
        $previous = static::$suppressAggregatorAvailabilitySync;
        static::$suppressAggregatorAvailabilitySync = true;

        try {
            return $callback();
        } finally {
            static::$suppressAggregatorAvailabilitySync = $previous;
        }
    }
}
