<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * Lane C: the booking customer's transactional SMS outside the reminder (utility messages, feature `status`, never
 * marketing; only when the booking has a phone).
 *
 *  - refundPending: the deposit refund of a cancelled booking was refused by the gateway layer and waits for the
 *    owner's retry — the customer is told it is being processed (sent once, when it is first recorded).
 *  - pendingPaymentCancelled (kit/api README §8, API-29): the customer released the hold of a booking still waiting
 *    for its deposit, which cancelled it — only when the store turned on "Message when a customer cancels a pending
 *    payment" (`notify_customer_pending_payment_cancelled`, off by default); the flow's deposit-not-paid message.
 */
final class BookingCustomerNotices
{
    public function __construct(private readonly MessageQueue $messages) {}

    public function refundPending(Booking $booking): bool
    {
        $phone = trim((string) ($booking->phone ?? ''));
        if ($phone === '') {
            return false;
        }
        $this->messages->sms($phone, ___(':store: your booking :number is cancelled. Your :amount deposit refund is being processed.', [
            'store' => $this->storeName($booking),
            'number' => (string) $booking->number,
            'amount' => BookingMoney::format((int) $booking->deposit_minor, (string) $booking->currency, (int) $booking->store_id),
        ]), (int) $booking->store_id, 'status', false);

        return true;
    }

    public function pendingPaymentCancelled(Booking $booking): bool
    {
        if (! StoreSettings::bool((int) $booking->store_id, 'notify_customer_pending_payment_cancelled')) {
            return false;
        }
        $phone = trim((string) ($booking->phone ?? ''));
        if ($phone === '') {
            return false;
        }
        $this->messages->sms($phone, ___(':store: your booking :number was cancelled before the deposit was paid. You were not charged.', [
            'store' => $this->storeName($booking),
            'number' => (string) $booking->number,
        ]), (int) $booking->store_id, 'status', false);

        return true;
    }

    private function storeName(Booking $booking): string
    {
        return \App\Support\OutboundText::name((string) (Store::query()->whereKey($booking->store_id)->value('title') ?? ''));
    }
}
