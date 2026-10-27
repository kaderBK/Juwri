<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Services\Tenancy\TenantConnections;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Services\Tenancy\TenantTables;

/**
 * P1-04 slice 5 (J29-1, J25-6): every model on a tenant table ({@see TenantTables})
 * uses this trait — the architecture test TenantModelsTest fails otherwise. On create it fills `store_id` of child
 * rows from their parent and `account_id` from the store (or the owning user's account); the tenant query scope
 * lives here too.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);
        static::creating(function ($model): void {
            TenantFill::fill($model);
        });
    }

    /**
     * P1-04 slice 7 (J29-3): an explicit model connection wins; otherwise the tenant connection resolver decides
     * (null = default connection — always the case with the shared-database resolver).
     */
    public function getConnectionName()
    {
        return $this->connection ?? TenantConnections::current();
    }

    /**
     * Rows of one account.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($this->qualifyColumn('account_id'), $accountId);
    }
}
