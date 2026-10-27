<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\User;

/**
 * HPMS E12(c): a per-tenant health snapshot for the admin store/user page,
 * assembled entirely from data the platform already keeps — failed jobs,
 * webhook failure streaks, last backup, usage-vs-limit meters, and plan state.
 * Every query is guarded so a missing table never breaks the admin page.
 */
class TenantHealthService
{
    /** @return array<string,mixed> */
    public static function for(User $user): array
    {
        $storeIds = Store::where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'plan' => self::planName($user),
            'plan_active' => (int) $user->status === 1,
            'stores' => [
                'used' => count($storeIds),
                'limit' => self::limit($user),
            ],
            'webhook_failures' => self::webhookFailures($user),
            'last_backup_at' => self::lastBackup($storeIds),
            'failed_jobs' => self::failedJobs($storeIds),
        ];
    }

    private static function planName(User $user): string
    {
        try {
            return (string) (optional($user->plan())->name ?? '—');
        } catch (\Throwable $e) {
            return '—';
        }
    }

    private static function limit(User $user): int
    {
        try {
            return (int) effective_limit('restaurant', $user);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function webhookFailures(User $user): int
    {
        try {
            return (int) WebhookEndpoint::where('user_id', $user->id)->sum('consecutive_failures');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @param array<int,int> $storeIds */
    private static function lastBackup(array $storeIds): ?string
    {
        if ($storeIds === []) {
            return null;
        }
        try {
            $at = StoreBackup::whereIn('store_id', $storeIds)->max('created_at');

            return $at ? (string) $at : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Best-effort count of failed queue jobs referencing one of this tenant's
     * stores in the serialized payload. Fuzzy by nature (the queue payload is
     * generic) — a health signal, not an exact figure.
     *
     * @param  array<int,int>  $storeIds
     */
    private static function failedJobs(array $storeIds): int
    {
        if ($storeIds === [] || ! Schema::hasTable('failed_jobs')) {
            return 0;
        }
        try {
            $q = DB::table('failed_jobs');
            $q->where(function ($w) use ($storeIds) {
                foreach ($storeIds as $id) {
                    $w->orWhereLike('payload', '%"store_id";i:'.$id.';%')
                        ->orWhereLike('payload', '%\\"store_id\\":'.$id.'%');
                }
            });

            return (int) $q->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
