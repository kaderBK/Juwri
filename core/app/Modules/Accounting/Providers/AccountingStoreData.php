<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Accounting module's store- and account-owned tables for the store-data registry — the books follow the store; never copied.
 */
final class AccountingStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('accounting_accounts'),
            StoreTable::data('accounting_journal_lines'),
            StoreTable::data('accounting_journals'),
            StoreTable::data('accounting_period_locks'),
            StoreTable::data('expenses')->files(['receipt_path' => 'local']),
            StoreTable::data('external_invoices'),
        ];
    }
}
