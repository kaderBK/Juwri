<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Policies;

use Juwri\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Services\Tenancy\TenantContext;

/**
 * P1-04 slice 5: account-level authorization for any tenant model (Gate ability `tenant.access`, also
 * used by the `tenant.owns` middleware). Under an active tenant context the model must belong to the
 * subject's account (and, for a staff subject, to its store). Outside a context (public, admin, console)
 * it defers to the other checks (returns true). Rows with no account (platform/global rows) are
 * readable by every tenant, as in the global scope.
 */
final class TenantPolicy
{
    public static function allows(Model $model): bool
    {
        if (! in_array(BelongsToAccount::class, class_uses_recursive($model), true)) {
            return true;
        }
        $ctx = TenantContext::current();
        if (! $ctx->active()) {
            return true;
        }
        $account = $model->getAttribute('account_id');
        if ($account !== null && (int) $account !== $ctx->accountId()) {
            return false;
        }
        $stores = $ctx->storeIds();
        if ($stores !== null) {
            $store = $model->getTable() === 'stores' ? $model->getKey() : $model->getAttribute('store_id');
            if ($store !== null && ! in_array((int) $store, $stores, true)) {
                return false;
            }
        }

        return true;
    }

    public function access(?User $user, Model $model): bool
    {
        return self::allows($model);
    }
}
