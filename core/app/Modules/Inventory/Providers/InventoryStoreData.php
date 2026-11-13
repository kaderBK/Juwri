<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Inventory module's store- and account-owned tables for the store-data registry — every table is business data of the store: follows it on transfer, never copied.
 */
final class InventoryStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('branch_stock_levels'),
            StoreTable::data('ingredients'),
            StoreTable::data('purchase_order_items'),
            StoreTable::data('purchase_orders'),
            StoreTable::data('recipe_items'),
            StoreTable::data('stock_count_items'),
            StoreTable::data('stock_counts'),
            StoreTable::data('stock_movements'),
            StoreTable::data('supplier_invoices'),
            StoreTable::data('supplier_payments'), // D-P1-21-15: store_id/account_id copied from the supplier
            StoreTable::data('suppliers'),
            StoreTable::data('waste_logs'),
        ];
    }
}
