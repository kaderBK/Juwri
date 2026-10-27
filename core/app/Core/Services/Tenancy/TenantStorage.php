<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use Juwri\Core\Models\Account;
use LogicException;

/**
 * P1-04 slice 7 (J29-4, J27-A1): per-account storage paths for NEW tenant uploads —
 * `accounts/<account_id>/<dir>` — so an account's files can be exported, deleted (GDPR/PDPL) or moved to another
 * region as one prefix (P1-21). Existing files are NOT moved: rows keep the full relative path they were saved
 * with, so old and new paths both keep working.
 *
 * Writers that store the returned full path use it (staff documents, expense receipts, proof-of-delivery photos).
 * The legacy `image_upload()` / StorageService flow stores only a FILENAME and rebuilds the directory at read time
 * from a fixed per-feature path in ~100 places, so it stays flat until P1-08 moves those modules (logged).
 *
 * {@see self::disk()} maps an account's region to its storage disk (config tenancy.storage.region_disks); with no
 * mapping the caller's disk is used (single-region installs).
 */
final class TenantStorage
{
    public static function dir(string $dir, ?int $accountId = null): string
    {
        $accountId ??= self::currentAccount();

        return 'accounts/'.$accountId.'/'.trim($dir, '/');
    }

    public static function disk(string $default, ?int $accountId = null): string
    {
        $map = (array) config('tenancy.storage.region_disks', []);
        if ($map === []) {
            return $default;
        }
        $accountId ??= self::currentAccount();
        $region = TenantContext::bypass(fn () => Account::whereKey($accountId)->value('region'));

        return (string) ($map[$region] ?? $default);
    }

    private static function currentAccount(): int
    {
        $ctx = TenantContext::current();
        if (! $ctx->active() || $ctx->accountId() === null) {
            throw new LogicException('TenantStorage needs a tenant context with an account, or an explicit account id.');
        }

        return $ctx->accountId();
    }
}
