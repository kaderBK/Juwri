<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * P6-33: phone-agent error budget. Per store and per clock hour (UTC) it derives
 *  - failed-call rate from {@see CallLog} (outcome `failed` / all calls opened that hour),
 *  - provider callback failures (webhook signature rejects + 5xx in the status callback),
 *  - staff-handoff failures (the staff push threw),
 *  - answer latency p95 (ms spent answering the inbound webhook).
 * The webhook-side counters live in the cache in hour buckets (operational counters: lossy is fine, nothing
 * secret is stored — store id + counts + latencies only). {@see check()} judges the previous full hour; a store
 * over budget gets one platform alert ({@see AlertDispatcher}, same seam as the cost-anomaly check) and one
 * staff/owner push per kind per hour — re-runs of the same hour are no-ops.
 */
final class VoiceErrorBudget
{
    public const KINDS = ['failed_calls', 'handoff_failures', 'signature_rejects', 'callback_errors', 'answer_latency'];

    private const TTL_SECONDS = 3 * 3600;

    private const MAX_LATENCY_SAMPLES = 500;

    public static function signatureReject(int $storeId): void
    {
        self::bump($storeId, 'signature_rejects');
    }

    public static function callbackError(int $storeId): void
    {
        self::bump($storeId, 'callback_errors');
    }

    public static function handoffFailed(int $storeId): void
    {
        self::bump($storeId, 'handoff_failures');
    }

    public static function answerLatency(int $storeId, int $ms): void
    {
        try {
            $hour = self::hourKey(Carbon::now());
            self::touchStore($storeId, $hour);
            $key = "voice_budget:lat:{$storeId}:{$hour}";
            $samples = (array) Cache::get($key, []);
            if (count($samples) < self::MAX_LATENCY_SAMPLES) {
                $samples[] = max(0, $ms);
                Cache::put($key, $samples, self::TTL_SECONDS);
            }
        } catch (\Throwable $e) {
            // metrics never break a live call
        }
    }

    /**
     * Per-store metrics for the hour starting at $hourStart.
     *
     * @return array<int, array{calls:int,failed:int,failed_rate:float,handoff_failures:int,signature_rejects:int,callback_errors:int,latency_p95_ms:?int}>
     */
    public function metrics(Carbon $hourStart): array
    {
        $start = $hourStart->copy()->setTimezone('UTC')->startOfHour();
        $end = $start->copy()->addHour();
        $hour = self::hourKey($start);

        /** @var array<int, array{calls:int, failed:int}> $calls */
        $calls = TenantContext::bypass(function () use ($start, $end): array {
            $map = [];
            $rows = CallLog::query()
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->get(['store_id', 'outcome']);
            foreach ($rows as $row) {
                $sid = (int) $row->store_id;
                $map[$sid] ??= ['calls' => 0, 'failed' => 0];
                $map[$sid]['calls']++;
                if ($row->outcome === 'failed') {
                    $map[$sid]['failed']++;
                }
            }

            return $map;
        });

        $storeIds = array_unique(array_merge(array_keys($calls), array_map('intval', (array) Cache::get("voice_budget:stores:{$hour}", []))));
        sort($storeIds);

        $out = [];
        foreach ($storeIds as $sid) {
            $total = $calls[$sid]['calls'] ?? 0;
            $failed = $calls[$sid]['failed'] ?? 0;
            $out[$sid] = [
                'calls' => $total,
                'failed' => $failed,
                'failed_rate' => $total > 0 ? $failed / $total : 0.0,
                'handoff_failures' => (int) Cache::get("voice_budget:handoff_failures:{$sid}:{$hour}", 0),
                'signature_rejects' => (int) Cache::get("voice_budget:signature_rejects:{$sid}:{$hour}", 0),
                'callback_errors' => (int) Cache::get("voice_budget:callback_errors:{$sid}:{$hour}", 0),
                'latency_p95_ms' => self::p95((array) Cache::get("voice_budget:lat:{$sid}:{$hour}", [])),
            ];
        }

        return $out;
    }

    /**
     * Judge the previous full hour and alert every store over budget (once per store/kind/hour).
     *
     * @return list<array{store_id:int,kind:string,detail:string}>
     */
    public function check(?Carbon $now = null): array
    {
        $hourStart = ($now ?? Carbon::now())->copy()->setTimezone('UTC')->startOfHour()->subHour();
        $hour = self::hourKey($hourStart);
        $cfg = (array) config('agents.error_budget', []);
        $maxRate = (float) ($cfg['failed_call_rate'] ?? 0.05);
        $minCalls = (int) ($cfg['min_calls'] ?? 20);
        $maxHandoff = (int) ($cfg['handoff_failures'] ?? 0);
        $maxRejects = (int) ($cfg['signature_rejects'] ?? 10);
        $maxCallback = (int) ($cfg['callback_errors'] ?? 5);
        $maxP95 = (int) ($cfg['latency_p95_ms'] ?? 3000);

        $breaches = [];
        foreach ($this->metrics($hourStart) as $sid => $m) {
            $found = [];
            if ($m['calls'] >= $minCalls && $m['failed_rate'] > $maxRate) {
                $found['failed_calls'] = "{$m['failed']}/{$m['calls']} calls failed (".round($m['failed_rate'] * 100, 1).'%)';
            }
            if ($m['handoff_failures'] > $maxHandoff) {
                $found['handoff_failures'] = "{$m['handoff_failures']} staff handoff failure(s)";
            }
            if ($m['signature_rejects'] > $maxRejects) {
                $found['signature_rejects'] = "{$m['signature_rejects']} webhook signature rejects";
            }
            if ($m['callback_errors'] > $maxCallback) {
                $found['callback_errors'] = "{$m['callback_errors']} provider callback errors";
            }
            if ($m['latency_p95_ms'] !== null && $m['latency_p95_ms'] > $maxP95) {
                $found['answer_latency'] = "answer latency p95 {$m['latency_p95_ms']} ms";
            }

            foreach ($found as $kind => $detail) {
                if (! Cache::add("voice_budget:alerted:{$sid}:{$kind}:{$hour}", 1, 2 * 86400)) {
                    continue; // already alerted this store/kind/hour
                }
                AlertDispatcher::send('voice_error_budget', "Phone agent store {$sid} over error budget ({$kind}) hour {$hour}Z: {$detail}");
                $this->notifyStore($sid, $kind);
                $breaches[] = ['store_id' => $sid, 'kind' => $kind, 'detail' => $detail];
            }
        }

        return $breaches;
    }

    private function notifyStore(int $storeId, string $kind): void
    {
        $body = match ($kind) {
            'failed_calls' => __('Many phone agent calls failed in the last hour.'),
            'handoff_failures' => __('A phone agent call could not be handed to staff in the last hour.'),
            'signature_rejects' => __('The phone provider sent many rejected requests in the last hour.'),
            'callback_errors' => __('Phone provider updates failed in the last hour.'),
            default => __('The phone agent answered slowly in the last hour.'),
        };
        try {
            app(MessageQueue::class)->staffPush($storeId, (string) __('Phone agent needs attention'), (string) $body, '/dashboard');
        } catch (\Throwable $e) {
            Log::warning('[voice-budget] owner notification failed for store '.$storeId);
        }
    }

    private static function bump(int $storeId, string $kind): void
    {
        try {
            $hour = self::hourKey(Carbon::now());
            self::touchStore($storeId, $hour);
            $key = "voice_budget:{$kind}:{$storeId}:{$hour}";
            Cache::add($key, 0, self::TTL_SECONDS);
            Cache::increment($key);
        } catch (\Throwable $e) {
            // metrics never break a live call
        }
    }

    private static function touchStore(int $storeId, string $hour): void
    {
        $key = "voice_budget:stores:{$hour}";
        $ids = (array) Cache::get($key, []);
        if (! in_array($storeId, $ids, true)) {
            $ids[] = $storeId;
            Cache::put($key, $ids, self::TTL_SECONDS);
        }
    }

    private static function hourKey(Carbon $at): string
    {
        return $at->copy()->setTimezone('UTC')->format('YmdH');
    }

    /** @param array<int|string, mixed> $samples */
    private static function p95(array $samples): ?int
    {
        if ($samples === []) {
            return null;
        }
        $vals = array_map('intval', array_values($samples));
        sort($vals);
        $idx = (int) ceil(0.95 * count($vals)) - 1;

        return $vals[max(0, $idx)];
    }
}
