<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Providers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Tables module's store-owned tables for the store-data registry. A duplicate copies the
 * tables/rooms, each with a FRESH unique QR key (a scan must never resolve to the source store); the branch, the
 * assigned server and the room type are the source store's rows, so the copy has none (store-wide, unassigned).
 * Checks, sessions, merges and waiter calls follow the store and are never copied.
 */
final class TablesStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('qr_designs')->media(['logo_file' => ['public:storage/menu/']]), // P4-07: the logo overlay is a library file
            StoreTable::catalog('store_tables')
                ->clear(['branch_id', 'server_staff_id', 'room_type_id'])
                ->fresh(['key' => self::freshKey(...)]),
            StoreTable::data('table_checks'),
            StoreTable::data('table_merge_groups'),
            StoreTable::data('table_merge_members'),
            StoreTable::data('table_sessions'),
            StoreTable::data('waiter_calls'),
        ];
    }

    /** A store_tables QR key not already in use (unique column). */
    public static function freshKey(ConnectionInterface $db): string
    {
        do {
            $key = Str::random(13);
        } while ($db->table('store_tables')->where('key', $key)->exists());

        return $key;
    }
}
