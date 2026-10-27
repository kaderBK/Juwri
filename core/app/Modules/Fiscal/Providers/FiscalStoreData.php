<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Providers;

use Illuminate\Database\ConnectionInterface;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;

/**
 * P1-21 (J29-8): the Fiscal module's store-owned tables for the store-data registry — the previous owner's fiscal
 * certificates (issued to their tax registration) are switched off on transfer; devices and documents follow the
 * store (fiscal history). Never copied.
 */
final class FiscalStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::connection('fiscal_certificates', fn (ConnectionInterface $db, StoreTransferContext $ctx): int => $db->table('fiscal_certificates')->where('store_id', $ctx->storeId)->update(['active' => false]))
                ->notExported(), // certificates carry private keys
            StoreTable::data('fiscal_devices'),
            StoreTable::data('fiscal_documents'),
        ];
    }
}
