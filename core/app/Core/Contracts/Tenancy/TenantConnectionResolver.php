<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Tenancy;

use Juwri\Core\Services\Tenancy\SharedDatabaseConnectionResolver;

/**
 * P1-04 slice 7 (J29-3): which database connection holds an account's tenant rows. Community / shared Cloud =
 * one database for everyone ({@see SharedDatabaseConnectionResolver}); the
 * enterprise "silo" path (ee / Cloud) binds an implementation that returns a dedicated connection for accounts
 * that have one. Tenant models never hardcode a connection: BelongsToAccount asks this resolver.
 */
interface TenantConnectionResolver
{
    /** Connection name for the account's tenant rows, or null for the default connection. */
    public function connectionFor(?int $accountId): ?string;

    /** False when every account lives in the default database (lets models skip the lookup entirely). */
    public function isolatesAccounts(): bool;
}
