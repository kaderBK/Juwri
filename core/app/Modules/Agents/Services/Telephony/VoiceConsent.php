<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * P6-09 V-10: AI disclosure on every call; audio recording only when the owner chose a region policy, turned
 * recording on, AND the caller said yes. Every region uses the explicit spoken opt-in (the KSA/UAE rule is the
 * strictest, so it is the default everywhere). Silence, "no" or anything else = not recorded.
 */
final class VoiceConsent
{
    public const REGIONS = ['ksa', 'uae', 'eu', 'other'];

    /** Recording can be asked for on this line (owner opt-in + a region policy). */
    public static function armed(PhoneLine $line): bool
    {
        return (bool) $line->recording_enabled && in_array((string) $line->recording_region, self::REGIONS, true);
    }

    /** The provider's welcome line: greeting + AI disclosure (+ the recording question when armed). */
    public static function greeting(PhoneLine $line): string
    {
        return (string) VoiceLocale::with($line, function () use ($line): string {
            // The owner's custom greeting is spoken verbatim; only the built-in default translates.
            $hello = $line->greeting ?: __('Hi! How can I help with your order today?');
            $notice = self::armed($line)
                ? __('This call is handled by an AI assistant and may be recorded. Say yes if you agree to recording.')
                : __('This call is handled by an AI assistant.');

            return $notice.' '.$hello;
        });
    }

    /**
     * Resolve the caller's answer when the question is pending. Returns the reply to speak when the utterance
     * was only a yes/no answer, or null when the call should go on to the agent with this utterance.
     */
    public static function answer(CallLog $log, string $utterance): ?string
    {
        if ($log->consent !== 'asked') {
            return null;
        }
        $yes = VoiceOrderConfirm::isYes($utterance);
        $log->consent = $yes ? 'yes' : 'no';
        $log->recording = $yes;

        $words = preg_split('/[^\p{L}\p{N}\']+/u', trim($utterance), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > 3) {
            return null;
        }

        return (string) VoiceLocale::withLocale(VoiceLocale::forCallLog($log), fn (): string => $yes
            ? __('Thank you. What would you like to order?')
            : __('No problem, this call will not be recorded. What would you like to order?'));
    }

    /** Audio may be kept for this call: caller said yes and the line is still armed. */
    public static function mayRecord(string $callSid): bool
    {
        $log = CallLog::where('call_sid', $callSid)->first();
        if (! $log || ! $log->recording || $log->consent !== 'yes') {
            return false;
        }
        $line = PhoneLine::find($log->phone_line_id);

        return $line !== null && self::armed($line);
    }
}
