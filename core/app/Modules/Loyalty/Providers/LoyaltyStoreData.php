<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Providers;

use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;

/**
 * P1-21 (J29-8): the Loyalty module's store- and account-owned tables for the store-data registry — every table is business data of the store: follows it on transfer, never copied.
 */
final class LoyaltyStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('coupon_redemptions'),
            // D-P1-21-15: account_id = the issuing owner's account (platform/reseller coupons: NULL, not tenant data).
            StoreTable::account('coupons'),
            StoreTable::account('coupon_batches'),
            StoreTable::data('gift_cards'),
            StoreTable::data('loyalty_accounts'),
            StoreTable::data('loyalty_ledger_entries'),
            StoreTable::data('loyalty_programs'),
            StoreTable::data('price_schedules'),
        ];
    }
}
