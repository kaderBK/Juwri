<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * P6-09 V-11: per-store monthly minute cap + per-call limits (length, turns, concurrent calls per line).
 * Each check returns the reason a call must be handed off, or null when it may continue.
 */
final class VoiceLimits
{
    /** Checked when a call opens: the store's monthly minutes and the line's open calls. */
    public static function refuseNewCall(PhoneLine $line, string $callSid): ?string
    {
        if (self::storeSecondsUsed((int) $line->store_id) >= self::storeCapMinutes($line) * 60) {
            return 'minute_cap';
        }

        $open = CallLog::where('phone_line_id', $line->id)
            ->where('outcome', 'in_progress')
            ->where('call_sid', '!=', $callSid)
            ->where('created_at', '>', now()->subMinutes(self::maxCallMinutes()))
            ->count();

        return $open >= max(1, (int) config('voice.max_concurrent', 3)) ? 'concurrent' : null;
    }

    /** Checked before every turn of an open call: call length and number of caller turns. */
    public static function refuseTurn(CallLog $log): ?string
    {
        if ($log->created_at && $log->created_at->lte(now()->subMinutes(self::maxCallMinutes()))) {
            return 'max_length';
        }
        $turns = count(array_filter((array) $log->transcript, fn ($t) => ($t['role'] ?? '') === 'caller'));

        return $turns >= max(1, (int) config('voice.max_turns', 20)) ? 'max_turns' : null;
    }

    /** Store cap in minutes: the lowest owner hard cap on the store's lines, else the plan default. */
    public static function storeCapMinutes(PhoneLine $line): int
    {
        $owner = PhoneLine::where('store_id', $line->store_id)->whereNotNull('monthly_minute_cap')->min('monthly_minute_cap');

        return $owner !== null ? max(0, (int) $owner) : max(0, (int) config('voice.store_monthly_minutes', 600));
    }

    /** Seconds used this calendar month: billed duration, or elapsed time (capped) for calls with no duration yet. */
    public static function storeSecondsUsed(int $storeId): int
    {
        $max = self::maxCallMinutes() * 60;
        $seconds = 0;
        CallLog::where('store_id', $storeId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->get(['duration_seconds', 'created_at'])
            ->each(function (CallLog $c) use (&$seconds, $max) {
                $seconds += $c->duration_seconds !== null
                    ? (int) $c->duration_seconds
                    : min($max, (int) max(0, $c->created_at?->diffInSeconds(now()) ?? 0));
            });

        return $seconds;
    }

    private static function maxCallMinutes(): int
    {
        return max(1, (int) config('voice.max_call_minutes', 8));
    }
}
