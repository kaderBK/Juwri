<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Jobs;

use App\Services\QueueGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Menu\Services\Ai\MenuTranslationService;

/**
 * Fills missing menu translations for a restaurant in the background (AI calls
 * are slow; never block the request). Publishes progress to the cache so the
 * dashboard can show a live bar (#6).
 */
class TranslateMenuJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $storeId, public ?int $userId = null)
    {
        $this->onQueue('heavy'); // Abuse Guard P5 (5.1): heavy AI work on the dedicated queue
    }

    public static function progressKey(int $storeId): string
    {
        return 'menu_tr_progress:'.$storeId;
    }

    public function handle(MenuTranslationService $service): void
    {
        // When dispatch_job_reliably() falls back to dispatchAfterResponse()
        // (no queue worker), this runs inside the web process where the web
        // max_execution_time — not $timeout — would kill it mid-run. CLI
        // (worker/phpunit) is excluded: it is already unlimited, and lowering
        // its limit would cap the whole process.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($this->timeout);
        }

        $key = self::progressKey($this->storeId);
        $this->put($key, ['status' => 'running', 'done' => 0, 'total' => 0]);

        try {
            $result = $service->translateRestaurant($this->storeId, $this->userId,
                function (int $done, int $total, string $lang) use ($key) {
                    $this->put($key, ['status' => 'running', 'done' => $done, 'total' => $total, 'lang' => $lang]);
                });

            $this->put($key, array_merge(['status' => 'done', 'total' => count($result['languages'] ?? [])], [
                'done' => count($result['languages'] ?? []),
                'result' => $result,
            ]));
            Log::info('[AI translate] menu translated', array_merge(['store' => $this->storeId], $result));
            QueueGuard::release($this->userId);
        } catch (\Throwable $e) {
            $this->put($key, ['status' => 'error', 'message' => 'Translation failed. Please try again.']);
            Log::error("[AI translate] job failed for restaurant {$this->storeId}: ".$e->getMessage());
            throw $e;
        }
    }

    private function put(string $key, array $data): void
    {
        Cache::put($key, $data + ['store_id' => $this->storeId], 3600);

        // Long-running job on a real worker: Queue::looping doesn't fire while
        // we run, so keep the worker heartbeats fresh from in here — otherwise
        // concurrent requests wrongly fall back to inline execution. Goes through
        // queue_worker_stamp_heartbeat() so a job inside a dedicated daemon also
        // refreshes the daemon key the fallback drains gate on.
        if ($this->job) {
            queue_worker_stamp_heartbeat();
        }
    }

    /** Abuse Guard P5 (5.1): surface a clean error state if the job dies/times out. */
    public function failed(\Throwable $e): void
    {
        QueueGuard::release($this->userId);
        $this->put(self::progressKey($this->storeId), ['status' => 'error', 'message' => 'Translation failed. Please try again.']);
        Log::error("[AI translate] job failed for restaurant {$this->storeId}: ".$e->getMessage());
    }
}
