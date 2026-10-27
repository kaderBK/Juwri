<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Jobs;

use Juwri\Modules\Ai\Services\TranslatorService;
use Juwri\Core\Services\LangPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Machine-translates the platform-chrome strings of ONE locale in the background
 * (AI calls are slow — never block the request). Reuses the platform AI stack via
 * TranslatorService::translateBatch (owner decision #5, NO new provider SDKs) and
 * routes every candidate through LangPipelineService::applyMachine so the QA gates
 * decide machine-write vs review-queue. Progress is published to the cache for a
 * live bar (TranslateMenuJob idiom).
 *
 * Target keys = every en key whose locale status is `absent` or `placeholder`.
 * `review` (a pending human candidate), `machine`, and `human` are left alone.
 */
class LangChromeTranslateJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Keys per applyMachine() call — bounds progress granularity + memory. */
    private const CHUNK = 50;

    public function __construct(public string $code)
    {
        $this->onQueue('heavy'); // heavy AI work on the dedicated queue (TranslateMenuJob precedent)
    }

    public static function progressKey(string $code): string
    {
        return LangPipelineService::progressKey($code);
    }

    public function handle(TranslatorService $translator): void
    {
        // When dispatch_job_reliably() falls back to afterResponse() (no worker),
        // this runs in the web process where max_execution_time — not $timeout —
        // would kill it. CLI (worker/phpunit) is already unlimited.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($this->timeout);
        }

        $key = self::progressKey($this->code);
        $svc = app()->bound(LangPipelineService::class)
            ? app(LangPipelineService::class)
            : LangPipelineService::forApp();

        // Guard: the source locale IS the source of truth — never machine-translate it.
        $source = (string) config('settings.lang', config('app.default_language', 'en'));
        if ($this->code === $source) {
            $this->put($key, ['status' => 'error', 'done' => 0, 'total' => 0, 'written' => 0, 'review' => 0,
                'message' => 'Refusing to translate the source locale.']);

            return;
        }

        try {
            // Collect translatable keys: en keys whose locale status is absent/placeholder.
            $targets = [];
            foreach ($svc->keys($this->code, null, null, PHP_INT_MAX) as $row) {
                if (in_array($row['status'], ['absent', 'placeholder'], true) && $row['en'] !== '') {
                    $targets[$row['key']] = $row['en'];
                }
            }

            $total = count($targets);
            $done = 0;
            $written = 0;
            $review = 0;
            $this->put($key, ['status' => 'running', 'done' => 0, 'total' => $total, 'written' => 0, 'review' => 0, 'message' => null]);

            foreach (array_chunk($targets, self::CHUNK, true) as $chunk) {
                $records = [];
                foreach ($chunk as $k => $en) {
                    $records[$k] = ['t' => $en];
                }

                $out = $translator->translateBatch($records, $this->code, ['sourceLang' => 'en']);

                $translations = [];
                foreach ($chunk as $k => $en) {
                    $rec = $out[$k] ?? [];
                    $translations[$k] = [
                        'value' => (string) ($rec['t'] ?? $en),
                        'lowconf' => (bool) ($rec['_lowconf'] ?? false),
                    ];
                }

                $res = $svc->applyMachine($this->code, $translations);
                $written += $res['written'];
                $review += $res['queued_review'];
                $done += count($chunk);

                $this->put($key, ['status' => 'running', 'done' => $done, 'total' => $total,
                    'written' => $written, 'review' => $review, 'message' => null]);
            }

            $this->put($key, ['status' => 'done', 'done' => $done, 'total' => $total,
                'written' => $written, 'review' => $review, 'message' => null]);
            Log::info("[lang pipeline] translated {$this->code}", ['written' => $written, 'review' => $review, 'total' => $total]);
        } catch (\Throwable $e) {
            $this->put($key, ['status' => 'error', 'done' => 0, 'total' => 0, 'written' => 0, 'review' => 0,
                'message' => 'Translation failed. Please try again.']);
            Log::error("[lang pipeline] job failed for {$this->code}: ".$e->getMessage());
            throw $e;
        }
    }

    /** @param  array<string,mixed>  $data */
    private function put(string $key, array $data): void
    {
        Cache::put($key, $data + ['code' => $this->code], 3600);

        // Keep the worker heartbeats fresh during a long run so concurrent requests
        // don't wrongly fall back to inline execution (TranslateMenuJob idiom).
        // queue_worker_stamp_heartbeat() also refreshes the daemon key when this
        // runs inside a dedicated daemon.
        if ($this->job) {
            queue_worker_stamp_heartbeat();
        }
    }

    /** Surface a clean error state if the job dies/times out. */
    public function failed(\Throwable $e): void
    {
        $this->put(self::progressKey($this->code), ['status' => 'error', 'done' => 0, 'total' => 0,
            'written' => 0, 'review' => 0, 'message' => 'Translation failed. Please try again.']);
        Log::error("[lang pipeline] job failed for {$this->code}: ".$e->getMessage());
    }
}
