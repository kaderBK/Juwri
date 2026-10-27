<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P1-04 slice 7 (J29-4): per-tenant rate-limit keys. The owner-api limiter runs BEFORE the token is authenticated,
 * so the account behind a raw bearer token is looked up here (one indexed query, cached 5 minutes per known token
 * hash; misses are not cached; the raw token is never stored). Unknown / revoked tokens have no account — the per-token limit still applies and
 * ApiTokenAuth rejects them.
 */
final class TenantRateLimits
{
    public static function ownerTokenAccount(string $raw): ?int
    {
        $hash = hash('sha256', $raw);
        $account = Cache::get('oapi-acct:'.$hash);
        if ($account === null) {
            $account = TenantContext::bypass(fn () => DB::table('api_tokens')
                ->join('users', 'users.id', '=', 'api_tokens.user_id')
                ->where('api_tokens.token', $hash)
                ->value('users.account_id'));
            if ($account !== null) {
                Cache::put('oapi-acct:'.$hash, (int) $account, 300); // hits only: random tokens never fill the cache
            }
        }

        return (int) $account > 0 ? (int) $account : null;
    }

    /**
     * P3-06b: the storefront token behind a raw `X-Storefront-Token` (or the query fallback) for the `storefront` /
     * `storefront-write` limiters. Laravel's middleware priority sorts ThrottleRequests BEFORE StorefrontTokenAuth,
     * so the limiters cannot read the token that middleware binds (they used to — every request was limited per IP,
     * and under a long-lived worker a previous request's token leaked into the key). One indexed query, hits cached
     * 5 minutes per token hash; misses (random tokens) are not cached. Inactive tokens resolve too: they keep their
     * own budget and StorefrontTokenAuth refuses them.
     *
     * @return array{id: int, browser: bool, account: int}|null
     */
    public static function storefrontToken(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $hash = hash('sha256', $raw);
        $hit = Cache::get('sft-tok:'.$hash);
        if (is_array($hit)) {
            return $hit;
        }
        $row = TenantContext::bypass(fn () => DB::table('storefront_tokens')->where('token', $hash)->first(['id', 'browser', 'account_id']));
        if ($row === null) {
            return null;
        }
        $out = ['id' => (int) $row->id, 'browser' => (bool) $row->browser, 'account' => (int) $row->account_id];
        Cache::put('sft-tok:'.$hash, $out, 300);

        return $out;
    }

    /** The raw storefront token of a request, read the way StorefrontTokenAuth reads it. */
    public static function rawStorefrontToken(\Illuminate\Http\Request $request): ?string
    {
        $raw = $request->header('X-Storefront-Token');
        if (($raw === null || $raw === '') && config('auth.token_query_fallback')) {
            $raw = $request->query('storefront_token');
        }

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
