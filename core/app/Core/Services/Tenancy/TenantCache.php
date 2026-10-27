<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * P1-04 slice 7 (J29-4): per-account cache namespace for TENANT caches — values computed under the tenant scope
 * (or otherwise account-specific) that must never be served to another account. Keys become
 * `tenant:<account_id>:<key>`.
 *
 *   TenantCache::store()->remember('dash:counts', 30, fn () => …);   // current tenant context
 *   TenantCache::store($accountId)->forget('dash:counts');            // explicit account (jobs, platform code)
 *   TenantCache::key('dash:counts');                                  // the namespaced key for a raw Cache call
 *
 * Fails closed: without an explicit account and outside an active tenant context it throws instead of falling
 * back to a shared key. Store-keyed caches of PUBLIC data (storefront menu by store id, progress keys by store id)
 * stay as they are — the id already namespaces them and the storefront reads them without a tenant.
 */
final class TenantCache
{
    public static function prefix(?int $accountId = null): string
    {
        $accountId ??= self::currentAccount();

        return 'tenant:'.$accountId.':';
    }

    public static function key(string $key, ?int $accountId = null): string
    {
        return self::prefix($accountId).$key;
    }

    /**
     * For code that also runs outside tenant contexts: the account-namespaced key inside one, the key unchanged
     * otherwise (platform callers already key by owner/store).
     */
    public static function scopedKey(string $key): string
    {
        return TenantContext::current()->active() ? self::key($key) : $key;
    }

    public static function store(?int $accountId = null, ?string $store = null): TenantCacheRepository
    {
        return new TenantCacheRepository(Cache::store($store), self::prefix($accountId));
    }

    private static function currentAccount(): int
    {
        $ctx = TenantContext::current();
        if (! $ctx->active()) {
            throw new LogicException('TenantCache used outside a tenant context: pass the account id explicitly.');
        }

        // An owner without an account yet has no tenant rows; 0 is a namespace no account can have.
        return $ctx->accountId() ?? 0;
    }
}
