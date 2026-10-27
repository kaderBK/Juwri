<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Biolink\Services\Ai\BioBlockTranslationService;

/**
 * BIOLINK-TEMPLATES-RUN (Lane B · T2-2): bulk-translate a store's bio blocks in
 * the background. Mirrors TranslateMenuJob — AI calls are slow, so never block
 * the request; progress is published to a Cache key the builder polls.
 *
 * tries=1 (a failed run is retried by the owner, never silently re-billed) and
 * onQueue('heavy') (the dedicated queue for slow AI work, Abuse Guard P5).
 */
class TranslateBioBlocksJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $storeId, public ?int $userId = null)
    {
        $this->onQueue('heavy');
    }

    public static function progressKey(int $storeId): string
    {
        return 'bio_tr_progress:'.$storeId;
    }

    public function handle(BioBlockTranslationService $service): void
    {
        // When dispatch falls back to inline execution (no queue worker), this
        // runs in the web process where max_execution_time — not $timeout — would
        // kill it mid-run. CLI (worker/phpunit) is already unlimited, so exclude
        // it (lowering its limit would cap the whole process). Mirrors
        // TranslateMenuJob.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($this->timeout);
        }

        $key = self::progressKey($this->storeId);
        $this->put($key, ['status' => 'running', 'done' => 0, 'total' => 0]);

        try {
            $result = $service->translateStore($this->storeId, $this->userId,
                function (int $done, int $total, string $locale) use ($key) {
                    $this->put($key, ['status' => 'running', 'done' => $done, 'total' => $total, 'locale' => $locale]);
                });

            $count = count($result['locales']);
            $this->put($key, ['status' => 'done', 'done' => $count, 'total' => $count, 'result' => $result]);
            Log::info('[AI translate] bio blocks translated', array_merge(['store' => $this->storeId], $result));
        } catch (\Throwable $e) {
            $this->put($key, ['status' => 'error', 'message' => 'Translation failed. Please try again.']);
            Log::error("[AI translate] bio job failed for post {$this->storeId}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function put(string $key, array $data): void
    {
        Cache::put($key, $data + ['store_id' => $this->storeId], 3600);

        // Long-running job on a real worker: keep the worker heartbeats fresh so
        // concurrent requests do not wrongly fall back to inline execution.
        // queue_worker_stamp_heartbeat() also refreshes the daemon key when this
        // runs inside a dedicated daemon.
        if ($this->job) {
            queue_worker_stamp_heartbeat();
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->put(self::progressKey($this->storeId), ['status' => 'error', 'message' => 'Translation failed. Please try again.']);
        Log::error("[AI translate] bio job failed for post {$this->storeId}: ".$e->getMessage());
    }
}
