<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Payments;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * P3-06b (kit/api README §8, API-29): per store, "Message when a customer cancels a pending payment" — off by
 * default (notification settings, `notify_customer_pending_payment_cancelled`). When on, a customer who gives up an
 * order that was still waiting for its online payment gets one transactional SMS saying nothing was charged. The
 * caller is the customer's own cancel of a pending payment (the slot hold release, `DELETE /holds/{hold_id}`, phase
 * C); the payment-window sweep does not message (the customer did not cancel).
 */
final class PendingPaymentCancelledNotice
{
    public function __construct(private readonly MessageQueue $messages) {}

    /** Sends the message when the store has it on and the order has a phone; returns whether one was queued. */
    public function send(Order $order): bool
    {
        $storeId = (int) $order->store_id;
        if (! StoreSettings::bool($storeId, 'notify_customer_pending_payment_cancelled')) {
            return false;
        }
        $phone = trim((string) ($order->phone_number ?? ''));
        if ($phone === '') {
            return false;
        }
        $store = Store::query()->find($storeId);
        $text = ___(':store: your order #:number was cancelled before payment. You were not charged.', [
            'store' => \App\Support\OutboundText::name((string) ($store->title ?? '')),
            'number' => (string) $order->id,
        ]);
        $this->messages->sms($phone, $text, $storeId, 'status', false);

        return true;
    }
}
