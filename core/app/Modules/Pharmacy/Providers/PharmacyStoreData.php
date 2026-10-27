<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-23: the Pharmacy module's store-owned tables for the store-data registry — the licence uploads are business data
 * of the store: they follow it on transfer and are never copied to another store.
 */
final class PharmacyStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            // P4-07: the scanned licence (private disk) leaves with the store's data and is erased with it
            StoreTable::data('pharmacy_licences')->files(['document_path' => (string) config('pharmacy.licence_disk', 'local')]),
        ];
    }
}
