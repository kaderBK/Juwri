<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Account;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\BuildAccountExportJob;
use Juwri\Core\Models\User;

/**
 * P4-07: the owner's account export files. One folder per account on the private disk
 * (storage/app/account-exports/<account id>/<token>.zip); every lookup is built from the SIGNED-IN owner's own account
 * id, so a token of another account can never be reached (the path does not exist under this account). Kept for
 * {@see KEEP_DAYS}; a new export replaces the older ones; the account purge deletes the folder.
 */
final class AccountExports
{
    public const KEEP_DAYS = 7;

    public const STALE_PART_SECONDS = 2100;

    /** The account the user may export: only its owner (not a team member, not a staff session). */
    public function accountOf(User $user): ?int
    {
        $id = (int) ($user->account_id ?? 0);
        if ($id === 0) {
            return null;
        }
        $owner = DB::table('accounts')->where('id', $id)->value('owner_user_id');

        return (int) $owner === (int) $user->id ? $id : null;
    }

    public function dir(int $accountId): string
    {
        $base = config('filesystems.account_exports_path');

        return (is_string($base) && $base !== '' ? rtrim($base, '/') : storage_path('app/account-exports')).'/'.$accountId;
    }

    public function path(int $accountId, string $token): string
    {
        return $this->dir($accountId).'/'.$token.'.zip';
    }

    public function start(int $accountId): string
    {
        $this->clear($accountId);
        $token = Str::lower(Str::random(40));
        BuildAccountExportJob::dispatch($accountId, $token);

        return $token;
    }

    /** @return array{token: string, status: string, size: int|null, created_at: int}|null the latest export */
    public function latest(int $accountId): ?array
    {
        $latest = null;
        foreach (glob($this->dir($accountId).'/*') ?: [] as $file) {
            if (filemtime($file) < time() - self::KEEP_DAYS * 86400) {
                @unlink($file);

                continue;
            }
            if (preg_match('#/([a-z0-9]{40})\.zip(\.part|\.failed)?$#', $file, $m) !== 1) {
                continue;
            }
            $status = match ($m[2] ?? '') {
                '.part' => filemtime($file) < time() - self::STALE_PART_SECONDS ? 'failed' : 'processing', '.failed' => 'failed', default => 'ready'
            };
            if ($latest === null || filemtime($file) >= $latest['created_at']) {
                $latest = ['token' => $m[1], 'status' => $status, 'size' => $status === 'ready' ? (int) filesize($file) : null, 'created_at' => (int) filemtime($file)];
            }
        }

        return $latest;
    }

    /** The ready archive of THIS account with this token, or null. */
    public function file(int $accountId, string $token): ?string
    {
        if (preg_match('/^[a-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $path = $this->path($accountId, $token);

        return is_file($path) ? $path : null;
    }

    public function clear(int $accountId): void
    {
        foreach (glob($this->dir($accountId).'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir($accountId));
    }
}
