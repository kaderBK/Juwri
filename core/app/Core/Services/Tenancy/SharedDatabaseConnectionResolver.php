<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Juwri\Core\Contracts\Tenancy\TenantConnectionResolver;

/** P1-04 slice 7: the default — every account in the one shared database (pool model, J29). */
final class SharedDatabaseConnectionResolver implements TenantConnectionResolver
{
    public function connectionFor(?int $accountId): ?string
    {
        return null;
    }

    public function isolatesAccounts(): bool
    {
        return false;
    }
}
