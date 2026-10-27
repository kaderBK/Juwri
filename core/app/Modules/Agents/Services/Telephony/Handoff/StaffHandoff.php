<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Handoff;

use App\Services\Directory\OpeningHoursService;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Agents\PiiRedactor;
use Juwri\Modules\Agents\Services\Telephony\TelephonyRegistry;
use Juwri\Modules\Agents\Services\Telephony\VoiceErrorBudget;
use Juwri\Modules\Agents\Services\Telephony\VoiceLocale;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * P6-09 V-13: the ONE handoff decision for a live AI phone call. Given the line, the
 * call log and a trigger, it either warm-transfers the caller to the staff number
 * (provider `<Dial>` + whispered summary) or records a callback request, flags the
 * conversation (`meta.handoff`) and pushes the store's staff. It is a pure function of
 * its inputs — no model calls — so it never spends AI budget deciding whether to
 * escalate.
 *
 * The tenant is always the line's store: a log that does not belong to this line is
 * ignored (store isolation), never read or mutated.
 */
final class StaffHandoff
{
    /** The only reasons a call may be escalated. */
    public const TRIGGERS = ['wants_human', 'failed_twice', 'complaint', 'tool_error', 'spend_cap', 'abusive', 'no_caller_id', 'no_pay_link']; // V-15: phone order without a pay link

    /** Whisper / notification summary cap. */
    public const MAX_SUMMARY = 300;

    /**
     * Decide and apply the handoff.
     *
     * @return array{mode: string, payload: array{0: mixed, 1: string}|null, summary: string}
     */
    public static function handle(PhoneLine $line, CallLog $log, string $reason): array
    {
        $reason = self::normalizeReason($reason);

        // Tenant guard: a foreign call log is never read or written.
        if (! self::owns($line, $log)) {
            return ['mode' => 'callback', 'payload' => null, 'summary' => ''];
        }

        $summary = self::summary($log);

        if (self::canTransfer($line)) {
            $provider = TelephonyRegistry::get((string) $line->provider);
            if ($provider !== null) {
                [$body, $contentType] = $provider->transfer($line, (string) $line->handoff_number, $summary);

                return ['mode' => 'transfer', 'payload' => [$body, $contentType], 'summary' => $summary];
            }
        }

        self::requestCallback($line, $log, $reason, $summary);

        return ['mode' => 'callback', 'payload' => null, 'summary' => $summary];
    }

    /** A warm transfer needs a staff number AND the store being inside its hours. */
    private static function canTransfer(PhoneLine $line): bool
    {
        return (bool) $line->handoff_number && self::insideActiveHours($line);
    }

    /**
     * `active_hours` is the same free-text hours string the rest of the app stores
     * (e.g. "09:00-23:00", "9 AM - 11 PM", "24/7"): reuse {@see OpeningHoursService}
     * rather than inventing a parser. Null/blank = always open; an unparseable value
     * is treated as closed (callback) so we never make a false transfer.
     */
    private static function insideActiveHours(PhoneLine $line): bool
    {
        $hours = trim((string) $line->active_hours);
        if ($hours === '') {
            return true;
        }

        $week = OpeningHoursService::fromTimingText($hours);
        $status = OpeningHoursService::isOpenAt($week, (string) config('app.timezone', 'UTC'));

        return $status['state'] === 'open';
    }

    /**
     * The call log must belong to this line's store (and, when set, this exact line)
     * before anything is read or written. A log without a line id is tolerated for
     * legacy rows but still must match the store.
     */
    private static function owns(PhoneLine $line, CallLog $log): bool
    {
        if ((int) $log->store_id !== (int) $line->store_id) {
            return false;
        }

        return $log->phone_line_id === null || (int) $log->phone_line_id === (int) $line->id;
    }

    /** Keep only the documented triggers; anything else falls back to the human ask. */
    private static function normalizeReason(string $reason): string
    {
        return in_array($reason, self::TRIGGERS, true) ? $reason : 'wants_human';
    }

    /**
     * "caller wants X, cart Y" built from the (already redacted) transcript, re-run
     * through {@see PiiRedactor} defensively and capped at {@see MAX_SUMMARY} chars.
     */
    private static function summary(CallLog $log): string
    {
        $wants = [];
        foreach ((array) ($log->transcript ?? []) as $entry) {
            if (is_array($entry) && ($entry['role'] ?? null) === 'caller') {
                $text = trim((string) ($entry['text'] ?? ''));
                if ($text !== '') {
                    $wants[] = $text;
                }
            }
        }

        $want = implode(' ', array_slice($wants, -3));
        $summary = $want !== '' ? 'Caller wants: '.$want : 'Caller requested a staff handoff.';

        return mb_substr(PiiRedactor::redact($summary), 0, self::MAX_SUMMARY);
    }

    /** Record the callback, flag the conversation and push the store's staff. */
    private static function requestCallback(PhoneLine $line, CallLog $log, string $reason, string $summary): void
    {
        $log->outcome = 'handoff';
        $log->save();

        self::flagConversation($line, $log, $reason);
        self::notifyStore($line, $summary);
    }

    /** meta.handoff=true (+ reason + callback_requested) on the call's conversation. */
    private static function flagConversation(PhoneLine $line, CallLog $log, string $reason): void
    {
        // The conversation is created by the agent turn itself (AgentChatService keyed
        // on sha1(callSid)); only flag it when it exists — never invent one here.
        $conversation = AgentConversation::where('store_id', $line->store_id)
            ->where('channel', 'voice')
            ->where('session_key', substr(sha1((string) $log->call_sid), 0, 40))
            ->first();

        if ($conversation === null) {
            return;
        }

        $conversation->forceFill([
            'meta' => array_merge((array) $conversation->meta, [
                'handoff' => true,
                'handoff_reason' => $reason,
                'handoff_at' => now()->toIso8601String(),
                'callback_requested' => true,
            ]),
        ])->save();
    }

    /**
     * Notify the store through the existing Messaging public surface (a staff push),
     * never a silent drop. A transport failure is logged, not swallowed.
     */
    private static function notifyStore(PhoneLine $line, string $summary): void
    {
        try {
            app(MessageQueue::class)->staffPush(
                (int) $line->store_id,
                (string) VoiceLocale::with($line, fn (): string => __('Caller needs staff')),
                $summary,
                self::dashboardUrl($line),
            );
        } catch (\Throwable $e) {
            Log::warning('[voice-handoff] staff notification failed: '.$e->getMessage());
            VoiceErrorBudget::handoffFailed((int) $line->store_id);
        }
    }

    private static function dashboardUrl(PhoneLine $line): string
    {
        try {
            return route('stores.phoneagent.index', ['store_id' => (int) $line->store_id], false);
        } catch (\Throwable $e) {
            return '/dashboard';
        }
    }
}
