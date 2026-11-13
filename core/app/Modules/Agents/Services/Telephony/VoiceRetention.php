<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * P6-09 V-18: retention purge for call transcripts and recorded audio — every call older than its line's effective
 * retention window has its transcript nulled or its audio file deleted, whichever expired first. The effective days
 * are the strictest of the line's own setting and the region's legal ceiling ({@see REGION_MAX_DAYS}), never below 1.
 * A call with no (or an unknown-region) line is handled with the strictest defaults. Idempotent: already-purged
 * calls are skipped on later runs.
 */
final class VoiceRetention
{
    /**
     * Legal retention ceiling per region policy (transcript / audio in days). The strictest applies to every call of
     * a line: the effective days are min(line setting, region cap). A line with no/unknown region falls back to 'ksa'
     * (the strictest of the four).
     *
     * @var array<string, array{transcript: int, audio: int}>
     */
    public const REGION_MAX_DAYS = [
        'ksa'   => ['transcript' => 30, 'audio' => 7],
        'uae'   => ['transcript' => 30, 'audio' => 7],
        'eu'    => ['transcript' => 30, 'audio' => 14],
        'other' => ['transcript' => 90, 'audio' => 30],
    ];

    /** The strictest defaults for a call whose line is missing (never longer than the 'ksa' region). */
    private const DEFAULT_DAYS = ['transcript' => 30, 'audio' => 7];

    /**
     * The effective retention for one line: the strictest of the store's settings and the region cap, never below 1.
     *
     * @return array{transcript: int, audio: int}
     */
    public function effectiveDays(PhoneLine $line): array
    {
        $region = in_array((string) $line->recording_region, array_keys(self::REGION_MAX_DAYS), true)
            ? (string) $line->recording_region
            : 'ksa';

        return [
            'transcript' => max(1, min((int) $line->transcript_retention_days, self::REGION_MAX_DAYS[$region]['transcript'])),
            'audio' => max(1, min((int) $line->audio_retention_days, self::REGION_MAX_DAYS[$region]['audio'])),
        ];
    }

    /**
     * Purge every call_log past its effective retention window, across all accounts (platform job).
     *
     * @return array{transcripts: int, audio: int} how many transcripts were nulled / audio files deleted
     */
    public function purge(?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone('UTC');
        $counts = ['transcripts' => 0, 'audio' => 0];

        TenantContext::bypass(function () use ($now, &$counts): void {
            $lines = PhoneLine::query()->get()->keyBy('id');

            // Skip rows with nothing left to purge (no un-purged audio path and no un-purged transcript).
            CallLog::query()->where(function ($q): void {
                $q->where(fn ($a) => $a->whereNull('audio_purged_at')->whereNotNull('audio_path')->where('audio_path', '!=', ''))
                    ->orWhere(fn ($t) => $t->whereNull('transcript_purged_at')->whereNotNull('transcript'));
            })->chunkById(500, function ($logs) use ($now, $lines, &$counts): void {
                foreach ($logs as $log) {
                    $line = $log->phone_line_id !== null ? $lines->get($log->phone_line_id) : null;
                    // Never judge a call by another account's line settings (tenant isolation): fall back to defaults.
                    if ($line !== null && (int) $line->account_id !== (int) $log->account_id) {
                        $line = null;
                    }
                    $days = $line !== null ? $this->effectiveDays($line) : self::DEFAULT_DAYS;
                    $started = $log->created_at;

                    if ($started !== null && $this->audioExpired($log, $days['audio'], $now, $started)) {
                        $path = (string) $log->audio_path;
                        if ($path !== '') {
                            Storage::disk((string) ($log->audio_disk ?: config('voice.recordings_disk', 'local')))->delete($path);
                        }
                        CallLog::query()->whereKey($log->id)->update([
                            'audio_path' => null,
                            'audio_disk' => null,
                            'audio_purged_at' => $now,
                        ]);
                        $counts['audio']++;
                    }

                    if ($started !== null && $this->transcriptExpired($log, $days['transcript'], $now, $started)) {
                        CallLog::query()->whereKey($log->id)->update([
                            'transcript' => null,
                            'transcript_purged_at' => $now,
                        ]);
                        $counts['transcripts']++;
                    }
                }
            });
        });

        return $counts;
    }

    /** The audio file may be purged: it exists, is past its days and was not already purged. */
    private function audioExpired(CallLog $log, int $days, Carbon $now, Carbon $started): bool
    {
        return $log->audio_purged_at === null
            && is_string($log->audio_path) && $log->audio_path !== ''
            && $started->lt($now->copy()->subDays($days));
    }

    /** The transcript may be purged: one exists, is past its days and was not already purged. */
    private function transcriptExpired(CallLog $log, int $days, Carbon $now, Carbon $started): bool
    {
        return $log->transcript_purged_at === null
            && $log->transcript !== null
            && $started->lt($now->copy()->subDays($days));
    }
}