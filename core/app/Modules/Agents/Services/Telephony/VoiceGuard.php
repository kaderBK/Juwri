<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Models\VoiceCallerBlock;
use Juwri\Modules\Agents\Services\Telephony\Handoff\StaffHandoff;
use Juwri\Modules\Ordering\Data\TerminalOrderStatuses;

/**
 * P6-09 V-14 abuse rules for voice orders (server-side; the model cannot skip them):
 *  - a blocked caller ID (per store) gets no AI ordering;
 *  - one open unpaid voice order per caller ID per store;
 *  - a withheld caller ID (or a line set to link-pay only) → the order is link-pay only;
 *  - P6-09 V-15: every phone order is link-pay when `voice.require_pay_link`; no caller ID / no gateway → staff.
 */
final class VoiceGuard
{
    private const WITHHELD = ['anonymous', 'restricted', 'private', 'unknown', 'unavailable', 'withheld'];

    public static function digits(?string $number): string
    {
        return (string) preg_replace('/\D+/', '', (string) $number);
    }

    public static function withheld(?string $from): bool
    {
        $raw = mb_strtolower(trim((string) $from));

        // Twilio keypad pseudo-numbers: ANONYMOUS, RESTRICTED, UNAVAILABLE, BLOCKED.
        $pseudo = ['266696687', '7378742833', '8656696', '2562533'];

        return $raw === '' || in_array($raw, self::WITHHELD, true) || self::digits($raw) === ''
            || in_array(self::digits($raw), $pseudo, true);
    }

    public static function blocked(int $storeId, ?string $from): bool
    {
        if (self::withheld($from)) {
            return false;
        }

        return VoiceCallerBlock::where('store_id', $storeId)->where('number', self::digits($from))->exists();
    }

    public static function block(Store $store, string $number, ?string $reason = null): VoiceCallerBlock
    {
        return VoiceCallerBlock::firstOrCreate(
            ['store_id' => $store->id, 'number' => self::digits($number)],
            ['account_id' => $store->account_id, 'reason' => $reason]
        );
    }

    public static function payMode(PhoneLine $line, ?string $from): ?string
    {
        return $line->link_pay_only || self::withheld($from) ? 'link' : null;
    }

    /**
     * Checked before every voice create_order.
     *
     * @param  array<string, mixed>  $args
     * @return array{allow: bool, link_pay: bool, result: array<string, mixed>}
     */
    public static function beforeOrder(AgentConversation $conversation, Store $store, array $args): array
    {
        $callSid = (string) (((array) $conversation->meta)['call_sid'] ?? '');
        $log = $callSid !== '' ? CallLog::where('call_sid', $callSid)->where('store_id', $store->id)->first() : null;
        if (! $log) {
            // A voice conversation without its call record never orders.
            return ['allow' => false, 'link_pay' => false, 'result' => ['ok' => false, 'error' => 'Phone ordering is not available on this call. Offer a team member.']];
        }
        $linkPay = $log->pay_mode === 'link' || VoicePayLink::required();
        // P6-09 V-15: a pay-link order needs a number to text the link to, and a store gateway to take the payment.
        // Without either there is no phone order at all: the call goes to staff.
        if (VoicePayLink::required() && (self::withheld($log->from_number) || ! VoicePayLink::storeCanTake($store))) {
            $reason = self::withheld($log->from_number) ? 'no_caller_id' : 'no_pay_link';
            $line = PhoneLine::where('id', $log->phone_line_id)->where('store_id', $store->id)->first();
            if ($line !== null) {
                StaffHandoff::handle($line, $log, $reason);
            }

            return ['allow' => false, 'link_pay' => true, 'result' => [
                'ok' => false,
                'handoff' => $reason,
                'error' => 'A phone order needs a payment link texted to the caller, which is not possible on this call. A team member will take the order. Tell the caller.',
            ]];
        }

        $ids = CallLog::where('store_id', $store->id)
            ->where('from_number', $log->from_number)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $own = VoiceSession::orderIdForCall((int) $store->id, $callSid);
        if ($own !== null) {
            $ids[] = $own;
        }
        $terminal = TerminalOrderStatuses::values();
        $key = VoiceOrderConfirm::orderKey((string) $conversation->session_key, VoiceOrderConfirm::cartHash($args));

        $open = $ids === [] ? null : Order::where('store_id', $store->id)
            ->whereIn('id', array_unique($ids))
            ->where('is_paid', false)
            ->whereNotIn('status', $terminal)
            ->where(fn ($q) => $q->whereNull('client_uuid')->orWhere('client_uuid', '!=', $key))
            ->first();
        if ($open) {
            return ['allow' => false, 'link_pay' => $linkPay, 'result' => [
                'ok' => false,
                'open_unpaid_order' => true,
                'error' => 'This caller already has an open unpaid phone order. A new phone order is possible after it is paid or finished. Offer a team member.',
            ]];
        }

        return ['allow' => true, 'link_pay' => $linkPay, 'result' => []];
    }
}
