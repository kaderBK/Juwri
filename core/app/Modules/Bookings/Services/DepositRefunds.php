<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Money\Money;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-14 (REVIEW, Opus: money) — the money movement of a booking deposit refund through the store's own gateway.
 *
 * Called by {@see DepositService::refund()} while it holds the booking's row lock and only after it checked the
 * deposit is `paid` (cancel before the deadline with deposit refunds on, D7/D8) or `received_late` (the owner's
 * late-payment refund, always allowed, API-28) — so a booking is refunded at most once. The amount is the booking's
 * deposit, never more than what the deposit payment took; the gateway layer caps it again against the payment's own
 * refund ledger under the payment's row lock (RefundGuard). Automatic (money moves) only when the store switched
 * automatic refunds on and its gateway can refund (or the armed E2E Stub); otherwise a tracked manual refund the owner
 * completes in the provider dashboard. A refusal before any provider call (over the cap, another currency, payment not
 * settled) throws, so the caller rolls back and the deposit stays as it was.
 */
final class DepositRefunds
{
    public function __construct(private readonly Gateways $gateways) {}

    /** Did the deposit's money go back through the gateway (a refund the provider confirmed on its payment)? */
    public static function wentBack(Booking $booking): bool
    {
        $transaction = $booking->deposit_transaction_id !== null
            ? Transaction::query()->where('store_id', $booking->store_id)->whereKey($booking->deposit_transaction_id)->first()
            : null;
        foreach ((array) data_get($transaction?->details, 'gateway_refunds', []) as $entry) {
            if (data_get($entry, 'status') === 'ok') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{automatic: bool, amount_minor: int, gateway: string|null, reference: string|null, message: string|null}
     */
    public function send(Booking $locked): array
    {
        $transaction = $locked->deposit_transaction_id !== null
            ? Transaction::query()->where('store_id', $locked->store_id)->whereKey($locked->deposit_transaction_id)->first()
            : null;
        $amountMinor = (int) $locked->deposit_minor;
        if ($transaction === null) {
            // no gateway payment on record (e.g. marked paid by hand): the owner refunds it outside the platform
            return ['automatic' => false, 'amount_minor' => $amountMinor, 'gateway' => null, 'reference' => null,
                'message' => ___('Recorded. Refund the diner through the original payment method to move the money.')];
        }

        $paidMinor = Money::minorFromMajor((float) $transaction->amount, (string) ($transaction->currency_code ?: $locked->currency));
        $amountMinor = max(0, min($amountMinor, $paidMinor));
        if ($amountMinor === 0) {
            throw new BookingException('refund_refused', ___('Nothing to refund.'));
        }

        $result = $this->gateways->refundStorePayment($transaction, Money::majorFromMinor($amountMinor, $locked->currency), $locked->currency);
        if (! empty($result['refused'])) {
            throw new BookingException('refund_refused', (string) ($result['message'] ?? ___('Nothing to refund.')));
        }

        return [
            'automatic' => (bool) ($result['automatic'] ?? false),
            'amount_minor' => $amountMinor,
            'gateway' => isset($result['gateway']) ? (string) $result['gateway'] : null,
            'reference' => isset($result['reference']) ? (string) $result['reference'] : null,
            'message' => isset($result['message']) ? (string) $result['message'] : null,
        ];
    }
}
