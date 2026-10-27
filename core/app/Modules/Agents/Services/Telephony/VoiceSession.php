<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use App\Models\Order;
use App\Services\Agents\AgentChatService;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\AgentMessage;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Agents\PiiRedactor;
use Juwri\Modules\Agents\Services\Telephony\Handoff\StaffHandoff;

/**
 * T6-P2 voice ordering session. The provider's WebSocket bridge feeds each caller
 * utterance here; we drive the SAME agent tool-loop (menu-grounded, restaurant resolved
 * from the PhoneLine), record a redacted transcript, and return the agent's spoken
 * reply. Payment stays GATED — the agent hands off a pay link via SMS, never charges
 * on the call. The CallLog is idempotent per call SID.
 */
class VoiceSession
{
    /** Open (or resume) the call record for a call SID. */
    public static function open(PhoneLine $line, string $callSid, ?string $from): CallLog
    {
        return CallLog::firstOrCreate(
            ['call_sid' => $callSid],
            [
                'store_id' => $line->store_id,
                'phone_line_id' => $line->id,
                'provider' => $line->provider,
                'from_number' => $from,
                'outcome' => 'in_progress',
                'transcript' => [],
                // V-10: ask for recording consent only when the owner armed it; V-14: link-pay mode per call.
                'consent' => VoiceConsent::armed($line) ? 'asked' : null,
                'recording' => false,
                'pay_mode' => VoiceGuard::payMode($line, $from),
            ]
        );
    }

    /**
     * Process one caller utterance. Returns the agent's reply text (for TTS), or a
     * handoff sentinel when the line has ordering disabled / off. Appends both sides to
     * the transcript.
     */
    public static function turn(PhoneLine $line, string $callSid, ?string $from, string $utterance): string
    {
        $log = self::open($line, $callSid, $from);
        // P6-09 V-09: card digits are cut from the speech text before anything downstream sees it.
        $utterance = PciDigitFilter::filter($utterance)['text'];

        // V-02: a call SID belongs to the store whose line opened it. A replayed or
        // forged SID arriving on another store's line never touches that call.
        if ((int) $log->store_id !== (int) $line->store_id) {
            return (string) VoiceLocale::with($line, fn (): string => __('Let me put you through to a team member.'));
        }

        // V-14: a caller ID the store blocked gets no AI ordering (and no staff handoff).
        if (VoiceGuard::blocked((int) $line->store_id, $log->from_number)) {
            if ($log->outcome === 'in_progress') {
                $log->outcome = 'blocked';
                $log->save();
            }

            return (string) VoiceLocale::with($line, fn (): string => __('Sorry, this number cannot place phone orders. Please order online.'));
        }

        // V-11: store minute cap + concurrent calls (new call), call length + turns (every turn) → handoff.
        if ($log->outcome === 'limit') {
            return (string) VoiceLocale::with($line, fn (): string => __('Let me put you through to a team member.'));
        }
        $limit = $log->wasRecentlyCreated ? VoiceLimits::refuseNewCall($line, $callSid) : null;
        $limit ??= VoiceLimits::refuseTurn($log);
        if ($limit !== null) {
            $transcript = $log->transcript ?? [];
            $transcript[] = ['role' => 'system', 'text' => 'handoff:'.$limit];
            $log->transcript = $transcript;
            if ($log->outcome === 'in_progress') {
                $log->outcome = 'limit';
            }
            $log->save();

            return (string) VoiceLocale::with($line, fn (): string => __('Let me put you through to a team member.'));
        }

        // V-10: the first answer after the recording question decides consent; only a yes records.
        $consentReply = VoiceConsent::answer($log, $utterance);
        if ($consentReply !== null) {
            $transcript = $log->transcript ?? [];
            $transcript[] = ['role' => 'caller', 'text' => PiiRedactor::redact($utterance)];
            $transcript[] = ['role' => 'agent', 'text' => $consentReply];
            $log->transcript = $transcript;
            $log->save();

            return $consentReply;
        }

        if (! $line->orders_enabled) {
            // P6-09 V-13: ordering disabled is a dead end for the agent — hand off
            // (transfer when the line has a staff number and is inside hours, else a
            // callback + store notification). Runs after the V-10/V-14 gates.
            StaffHandoff::handle($line, $log, 'tool_error');

            return (string) VoiceLocale::with($line, fn (): string => __('Let me put you through to a team member.'));
        }

        $reply = AgentChatService::forVoice((int) $line->store_id, $callSid, (string) $from, $utterance)
            ?? (string) VoiceLocale::with($line, fn (): string => __('Sorry, ordering is not available right now.'));

        $transcript = $log->transcript ?? [];
        $transcript[] = ['role' => 'caller', 'text' => PiiRedactor::redact($utterance)];
        $transcript[] = ['role' => 'agent', 'text' => $reply];
        $log->transcript = $transcript;

        // V-02: link only an order created by THIS call's own conversation (bound by
        // session_key = sha1(callSid), store-scoped) — never "latest order in store".
        if (! $log->order_id) {
            $orderId = self::orderIdForCall((int) $line->store_id, $callSid);
            if ($orderId !== null) {
                $log->order_id = $orderId;
                $log->outcome = 'order';
            }
        }
        $log->save();

        return $reply;
    }

    /** Order id from a successful create_order in this call's conversation, verified to belong to the store. */
    public static function orderIdForCall(int $storeId, string $callSid): ?int
    {
        $conversation = AgentConversation::where('store_id', $storeId)
            ->where('channel', 'voice')
            ->where('session_key', substr(hash('sha1', $callSid), 0, 40))
            ->first();
        if (! $conversation) {
            return null;
        }

        $toolMsgs = AgentMessage::where('conversation_id', $conversation->id)
            ->where('role', 'tool')
            ->orderBy('id')
            ->get();
        foreach ($toolMsgs as $msg) {
            if ((string) ($msg->tool_calls['tool'] ?? '') !== 'create_order') {
                continue;
            }
            $out = (array) json_decode((string) $msg->content, true);
            $result = isset($out['result']) ? (array) $out['result'] : $out;
            if (empty($result['ok']) || empty($result['order_id'])) {
                continue;
            }
            $order = Order::where('id', (int) $result['order_id'])
                ->where('store_id', $storeId)
                ->first();
            if ($order) {
                return (int) $order->id;
            }
        }

        return null;
    }

    /** Finalize the call outcome (bridge sends this on hangup). */
    public static function finalize(string $callSid, string $outcome, ?int $durationSeconds = null): void
    {
        $log = CallLog::where('call_sid', $callSid)->first();
        if (! $log) {
            return;
        }
        if ($log->outcome === 'in_progress') {
            $log->outcome = $outcome;
        }
        if ($durationSeconds !== null) {
            $log->duration_seconds = max(0, $durationSeconds);
        }
        $log->save();

        // P6-09 V-12: the final duration writes the call's cost row (idempotent per call SID; never fails the hangup).
        if ($durationSeconds !== null) {
            try {
                VoiceCostLedger::recordCall($log);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
