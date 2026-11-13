<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Tenancy;

use Juwri\Core\Services\Tenancy\StoreDataRegistry;

/**
 * P1-21 (J29-8): a module's declaration to the store-data registry — every store- and account-owned table the
 * module creates ({@see StoreTable}), plus which store settings (stores.settings keys) are an owner's connection
 * (reset on transfer, never copied by a duplicate). Registered from the module's service provider with
 * {@see StoreDataRegistry::register()}; tests/Architecture/StoreDataRegistryTest fails
 * when a table with store_id/account_id is not declared by the module that creates it.
 */
abstract class StoreData
{
    /** @return list<StoreTable> */
    abstract public function tables(): array;

    /**
     * Forget this module's caches of one store (after a transfer commits: cached pages, tracking ids, data fetched
     * with the previous owner's connections must not be served to — or for — the new owner).
     */
    public function forgetStoreCaches(int $storeId): void {}

    /** Is this store setting (legacy option name) an owner's connection: a credential, account id or tracking id? */
    public function isOwnerSetting(string $name): bool
    {
        return false;
    }
}
