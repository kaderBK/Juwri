<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the AppStore module's store- and account-owned tables for the store-data registry — account-level purchases and payouts: never touched by a store operation.
 */
final class AppStoreStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::account('app_installations'),
            StoreTable::child('app_usage_records', 'app_installation_id', 'app_installations'),
            StoreTable::platform('apps', 'partner apps; the developer is a partner, not a store'),
            StoreTable::account('marketplace_reviews'),
            StoreTable::owned('seller_stripe_accounts'),
            StoreTable::account('seller_withdrawals'),
            StoreTable::account('theme_purchases'),
        ];
    }
}
