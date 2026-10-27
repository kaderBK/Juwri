<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Juwri\Modules\Payments\Data\OrderPayLink;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Handoff\StaffHandoff;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Ordering\Contracts\HostedPayments;

/**
 * P6-09 V-15 (Opus: payments) — pay-by-link for phone orders.
 *
 *  - The phone order is created `unpaid` + payment_mode `hosted` (AgentToolsService::createOrder, server-set from the
 *    voice channel): KdsService, the KDS boards and printing ignore it; nothing is announced.
 *  - {@see send()} opens the order's ONE pending `order` Transaction (the existing hosted pay page, reused) and texts
 *    its link to the caller ID of THIS call. Paid → PaymentController::paySuccess moves it to the kitchen and announces
 *    it once; unpaid when the window ends → cancelled by orders:expire-hosted-payments (stock + tenders returned).
 *  - No caller ID (withheld) or no online gateway → no order at all; the call goes to staff (VoiceGuard).
 */
final class VoicePayLink
{
    public static function required(): bool
    {
        return (bool) config('voice.require_pay_link', true);
    }

    /** The order's payment window in minutes, or null when phone orders are not pay-link orders. */
    public static function windowMinutes(): ?int
    {
        return self::required() ? max(5, (int) config('voice.pay_link_minutes', 30)) : null;
    }

    /** Can the store take an online payment at all (any gateway switched on)? */
    public static function storeCanTake(Store $store): bool
    {
        try {
            return app(HostedPayments::class)->storeCanTake($store, 1.0);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** {@see send()} for the call behind a voice conversation (`meta.call_sid`, same store). */
    public static function sendForConversation(AgentConversation $conversation, Store $store, int $orderId): bool
    {
        $callSid = (string) (((array) $conversation->meta)['call_sid'] ?? '');
        $log = $callSid !== '' ? CallLog::where('call_sid', $callSid)->where('store_id', $store->id)->first() : null;
        if ($log === null || $orderId <= 0) {
            return false;
        }
        $result = self::send($store, $log, $orderId);
        if (! $result['sent']) {
            $line = PhoneLine::where('id', $log->phone_line_id)->where('store_id', $store->id)->first();
            if ($line !== null) {
                StaffHandoff::handle($line, $log, 'no_pay_link');
            }
        }

        return $result['sent'];
    }

    /**
     * Open (or reuse) the order's pay link and text it to the caller. The order must be this store's unpaid hosted
     * phone order linked to this call; anything else sends nothing.
     *
     * @return array{sent: bool, reason?: string}
     */
    public static function send(Store $store, CallLog $log, int $orderId): array
    {
        if ((int) $log->store_id !== (int) $store->id || VoiceGuard::withheld($log->from_number)) {
            return ['sent' => false, 'reason' => 'no_caller_id'];
        }
        $order = Order::where('store_id', $store->id)->whereKey($orderId)->first();
        if ($order === null || $order->source !== 'phone' || $order->payment_mode !== 'hosted'
            || $order->status !== 'unpaid' || (int) $order->is_paid === 1) {
            return ['sent' => false, 'reason' => 'not_payable'];
        }

        $hosted = app(HostedPayments::class);
        $transaction = $hosted->open($store, $order, null, null);
        // V-15: temporary signed link (expiry = the order's payment hold) — the bare id route is guessable.
        $url = OrderPayLink::url($transaction); // signed, expires with the order's payment hold
        $text = (string) VoiceLocale::withLocale(VoiceLocale::forCallLog($log), fn (): string => __('Pay for your :store order #:order here: :url', [
            'store' => $store->title,
            'order' => $order->getKey(),
            'url' => $url,
        ]));

        $sent = app(Sms::class)->send((string) $log->from_number, $text, (int) $store->id, 'phone_pay_link');

        $transcript = (array) ($log->transcript ?? []);
        $transcript[] = ['role' => 'system', 'text' => $sent ? 'pay_link_sent' : 'pay_link_failed'];
        $log->transcript = $transcript;
        $log->save();

        return $sent ? ['sent' => true] : ['sent' => false, 'reason' => 'sms_failed'];
    }
}
