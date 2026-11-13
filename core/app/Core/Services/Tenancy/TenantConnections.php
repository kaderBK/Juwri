<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Juwri\Core\Contracts\Tenancy\TenantConnectionResolver;

/**
 * P1-04 slice 7: the resolver tenant models consult (set by CoreServiceProvider from the container binding). Null
 * while the shared-database resolver is bound, so the common case costs nothing per query.
 */
final class TenantConnections
{
    private static ?TenantConnectionResolver $resolver = null;

    /** The application instance the resolver was set for (a new app — next test — starts without one). */
    private static ?int $memoApp = null;

    public static function use(?TenantConnectionResolver $resolver): void
    {
        self::$memoApp = \Juwri\Core\Support\RequestEpoch::id();
        self::$resolver = $resolver !== null && $resolver->isolatesAccounts() ? $resolver : null;
    }

    /** Connection for the current tenant's rows, or null (the model's own / the default connection). */
    public static function current(): ?string
    {
        if (self::$resolver === null || self::$memoApp !== \Juwri\Core\Support\RequestEpoch::id()) {
            return null;
        }
        $ctx = TenantContext::current();

        return $ctx->active() ? self::$resolver->connectionFor($ctx->accountId()) : null;
    }
}
