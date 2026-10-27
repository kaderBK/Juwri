<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Providers;

use Illuminate\Database\ConnectionInterface;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;

/**
 * P1-21 (J29-8): the Channels module's store-owned tables for the store-data registry — the previous owner's
 * aggregator and POS-bridge connections are paused on transfer (kept for history; their secrets live in
 * `credentials` and are deleted there); logs, item mappings and pushes follow the store. Never copied.
 */
final class ChannelsStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::connection('aggregator_connections', self::pause('aggregator_connections')),
            StoreTable::data('aggregator_sync_logs'),
            StoreTable::data('hungerstation_catalog_jobs'),
            StoreTable::data('hungerstation_event_logs'),
            StoreTable::connection('pos_bridge_connections', self::pause('pos_bridge_connections')),
            StoreTable::data('pos_item_mappings'),
            StoreTable::data('pos_order_pushes'),
        ];
    }

    /** @return \Closure(ConnectionInterface, StoreTransferContext): int */
    private static function pause(string $table): \Closure
    {
        return fn (ConnectionInterface $db, StoreTransferContext $ctx): int => $db->table($table)->where('store_id', $ctx->storeId)->update(['status' => 'paused']);
    }
}
