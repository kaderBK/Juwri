<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantTables;

/**
 * P1-04 slice 5 (J29-2): the tenant global scope of every BelongsToAccount model. A no-op unless a
 * {@see TenantContext} is active (owner/staff/API requests); then:
 *  - account_id = the subject's account (tables where the row always has one), or that account OR NULL
 *    (tables that also hold platform/global rows, e.g. platform credentials, global opt-outs);
 *  - a staff subject additionally sees only its own store.
 * An owner without an account (no store yet) sees no store rows. Platform code opts out with
 * TenantContext::bypass() or withoutGlobalScope(AccountScope::class).
 *
 * @implements Scope<Model>
 */
final class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ctx = TenantContext::current();
        if (! $ctx->active()) {
            return;
        }
        $table = $model->getTable();
        $meta = TenantTables::get($table);
        if ($meta === null) {
            return;
        }
        $accountId = $ctx->accountId();
        $column = $model->qualifyColumn('account_id');

        if ($meta['account_required']) {
            $accountId === null ? $builder->whereRaw('1 = 0') : $builder->where($column, $accountId);
        } else {
            $builder->where(function (Builder $q) use ($column, $accountId) {
                $accountId === null ? $q->whereNull($column) : $q->where($column, $accountId)->orWhereNull($column);
            });
        }

        $stores = $ctx->storeIds();
        if ($stores !== null) {
            if ($table === 'stores') {
                $builder->whereIn($model->qualifyColumn('id'), $stores);
            } elseif ($meta['store']) {
                $storeColumn = $model->qualifyColumn('store_id');
                $meta['required']
                    ? $builder->whereIn($storeColumn, $stores)
                    : $builder->where(fn (Builder $q) => $q->whereIn($storeColumn, $stores)->orWhereNull($storeColumn));
            }
        }
    }
}
