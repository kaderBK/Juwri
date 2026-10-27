<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Customers module's store- and account-owned tables for the store-data registry — customers and their reviews follow the store; never copied.
 */
final class CustomersStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('customer_addresses'),
            StoreTable::data('customer_feedback'),
            StoreTable::data('customer_passkeys'),
            StoreTable::data('customer_tokens')->notExported(), // sign-in sessions
            StoreTable::data('customers')->secret(['password', 'pending_password']),
            StoreTable::data('menu_review_media'),
            StoreTable::data('menu_reviews'),
            StoreTable::data('nps_responses'),
            StoreTable::data('shopper_customers'), // P1-13 13d follow-up: shopper ↔ customer link (was Marketplace's)
            StoreTable::data('store_follows'),
            StoreTable::data('store_review_media'),
            StoreTable::data('store_reviews'),
        ];
    }
}
