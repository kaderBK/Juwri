<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Jobs;

use App\Http\Controllers\Admin\UserController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\User;

/**
 * P6-119e (J37): the admin "delete all filtered users" action as a queued job. It used to answer first and keep
 * deleting after fastcgi_finish_request(), which LiteSpeed (litespeed_finish_request) does not offer, so the delete
 * ran inside the web request and could hit the host's timeout.
 *
 * The id list is decided by the controller AFTER its admin auth check; this job only deletes those ids. It is
 * idempotent: ids that no longer exist are skipped, so a retry or a second dispatch resumes where the first stopped.
 * Progress lives in the cache (key {@see self::cacheKey()}) and is polled by the admin screen.
 */
class DeleteUsersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    private const TTL_SECONDS = 86400;

    /** @param  list<int>  $ids */
    public function __construct(public string $token, public array $ids, public ?int $adminId = null)
    {
        $this->onQueue('heavy');
    }

    public static function cacheKey(string $token): string
    {
        return 'users_bulk_delete:'.$token;
    }

    /**
     * Same selection => same token, so a double click or retry never queues the work twice.
     *
     * @param  list<int>  $ids
     */
    public static function tokenFor(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return substr(hash('sha256', implode(',', $ids)), 0, 32);
    }

    /** @return array{state: string, total: int, done: int, failed: int, message: string}|null */
    public static function status(string $token): ?array
    {
        $status = Cache::get(self::cacheKey($token));

        return is_array($status) ? $status : null;
    }

    /** Record "queued" unless the same selection is already queued/running. False = already in flight. */
    public static function markQueued(string $token, int $total): bool
    {
        $current = self::status($token);
        if ($current !== null && in_array($current['state'], ['queued', 'running'], true)) {
            return false;
        }
        self::put($token, 'queued', $total, 0, 0, 'Waiting for the queue');

        return true;
    }

    public function handle(UserController $deleter): void
    {
        $remaining = User::query()->whereIn('id', $this->ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $total = count($this->ids);
        $done = $total - count($remaining);
        self::put($this->token, 'running', $total, $done, 0, 'Deleting users');

        $failed = 0;
        $skipped = 0;
        foreach ($remaining as $id) {
            try {
                $skipped += $deleter->deleteUsersInBackground([$id], $this->adminId); // P6-245: money held / gateway still billing
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Bulk user delete failed for one user: '.$e->getMessage(), ['user_id' => $id, 'exception' => $e]);
            }
            $done++;
            if ($done % 5 === 0) {
                self::put($this->token, 'running', $total, $done, $failed, 'Deleting users');
            }
        }

        $message = $failed === 0 ? 'Deleted' : 'Some users could not be deleted; run it again to retry them';
        if ($skipped > 0) {
            $message .= " ({$skipped} kept: they still have money or a live paid subscription)";
        }
        self::put($this->token, $failed === 0 ? 'done' : 'failed', $total, $done, $failed, $message);
    }

    public function failed(\Throwable $e): void
    {
        $current = self::status($this->token);
        self::put($this->token, 'failed', count($this->ids), (int) ($current['done'] ?? 0), (int) ($current['failed'] ?? 0), 'The delete stopped; run it again to continue');
    }

    private static function put(string $token, string $state, int $total, int $done, int $failed, string $message): void
    {
        Cache::put(self::cacheKey($token), compact('state', 'total', 'done', 'failed', 'message'), self::TTL_SECONDS);
    }
}
