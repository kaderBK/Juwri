<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Providers;

use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Pos module's store- and account-owned tables for the store-data registry — shifts, terminals and screens follow the store; never copied.
 */
final class PosStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('pos_shifts'),
            StoreTable::data('pos_terminals'),
            StoreTable::data('pos_card_reversal_exceptions'),
            StoreTable::data('pos_offline_card_payments'),
            StoreTable::data('pos_offline_devices'),
            StoreTable::data('pos_offline_records'),
            StoreTable::data('pos_offline_snapshots'),
            StoreTable::data('pos_offline_zatca_invoices'),
            StoreTable::data('pos_offline_zatca_units'),
            StoreTable::data('signage_screens')->secret(['token' => fn (): string => Str::random(40)]),
        ];
    }

    /** The print bridge token pairs the OWNER's print bridge with the store. */
    public function isOwnerSetting(string $name): bool
    {
        return $name === 'print_bridge_token';
    }
}
