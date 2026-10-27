<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingDepositRefund;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-10: booking deposits (D8) through the store's own gateway — the reservation-deposit pattern
 * (ReservationDepositService): a pending store-payment Transaction (`booking_deposit`) handed to the gateway, settled
 * through StorePayments (the hosted payment page returns there), booked in the ledger (clearing → merchant).
 *
 *  - paid inside the 15-minute window → the booking is confirmed and its time stays blocked;
 *  - paid after the window (the booking was already cancelled and its time freed) → `received_late` (API-21): never
 *    revived and never refunded automatically; the owner refunds it (always allowed, API-28) or accepts it, which
 *    re-reserves the time if it is still free, else the booking waits as `pending_approval` (API-27);
 *  - refund (D7 switch on, before the deadline) and keep (after the deadline, no-show, refunds off).
 *
 * Refunds go back through the store's gateway when the store switched automatic refunds on (DepositRefunds, P3-14);
 * otherwise they are recorded (ledger + state) and the owner completes the money movement in the provider dashboard.
 */
final class DepositService
{
    public const METHOD = 'booking_deposit';

    /** Open the deposit charge for a pending booking (the caller hands it to the chosen gateway). */
    public function start(Booking $booking, string $gatewayFolder): Transaction
    {
        return DB::transaction(function () use ($booking, $gatewayFolder) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending_payment' || $locked->deposit_status !== 'pending' || $locked->payment_expires_at === null || $locked->payment_expires_at->isPast()) {
                throw new BookingException('payment_window_closed', ___('The time to pay this deposit has ended. Please book again.'));
            }
            if (! BookingPlan::storeAllows((int) $locked->store_id, BookingPlan::BOOKING)) {
                throw BookingException::notOpen();
            }
            $ownerId = (int) Store::query()->whereKey($locked->store_id)->value('user_id');
            $amount = Money::majorFromMinor($locked->deposit_minor, $locked->currency);

            $transaction = Transaction::query()->create([
                'product_name' => ___('Booking deposit'),
                'product_id' => $locked->id,
                'user_id' => $ownerId,
                'base_amount' => $amount,
                'amount' => $amount,
                'currency_code' => $locked->currency,
                'transaction_method' => self::METHOD,
                'store_id' => (int) $locked->store_id,
                'transaction_gatway' => $gatewayFolder,
                'transaction_description' => ___('Booking deposit').' '.$locked->number,
                'frequency' => 'LIFETIME',
                'status' => null,
                // the gateway sends the customer back to the booking page (Settlement::storeReturnUrl)
                'details' => array_filter([
                    'return_url' => ManageLink::url($locked, Store::query()->findOrFail($locked->store_id)),
                    // P3-06b phase D (lane C): a headless client's return_url (origin checked by the storefront API)
                    'client_return_url' => Api\ApiReturnUrls::for($locked),
                ]),
            ]);
            $locked->forceFill(['deposit_transaction_id' => $transaction->id])->save();

            return $transaction;
        });
    }

    /** The gateway confirmed the deposit (StorePayments settle; idempotent per booking). */
    public function markPaid(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $booking = Booking::query()->where('deposit_transaction_id', $transaction->id)->lockForUpdate()->first();
            if (! $booking instanceof Booking || in_array($booking->deposit_status, ['paid', 'refunded', 'kept', 'received_late'], true)) {
                return;
            }
            $this->post($booking, 'pay:'.$transaction->id, Money::majorFromMinor($booking->deposit_minor, $booking->currency), true);

            $late = $booking->status !== 'pending_payment'
                || ($booking->payment_expires_at !== null && $booking->payment_expires_at->isPast());
            if ($late) {
                if ($booking->status === 'pending_payment') {
                    $booking->forceFill(['status' => 'cancelled', 'cancelled_at' => now('UTC'), 'cancelled_by' => 'system'])->save();
                    DB::table('booking_slot_cells')->where('booking_id', $booking->id)->delete();
                }
                $booking->forceFill(['deposit_status' => 'received_late'])->save();

                return;
            }

            $booking->forceFill(['deposit_status' => 'paid', 'status' => 'confirmed', 'payment_expires_at' => null])->save();
            DB::table('booking_slot_cells')->where('booking_id', $booking->id)->update(['expires_at' => null]);
        });
    }

    /** Refund a paid (or late) deposit: ledger reversal + state. Late payments are always refundable (API-28). */
    public function refund(Booking $booking, string $why = 'cancel'): bool
    {
        return DB::transaction(function () use ($booking, $why) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->deposit_status, ['paid', 'received_late'], true)) {
                return false;
            }
            // P3-14 (lane C): the money goes back through the store's gateway (or is tracked for the owner), capped by
            // what was paid; a refusal throws and rolls this back, so the deposit stays as it was.
            $sent = app(DepositRefunds::class)->send($locked);
            $this->post($locked, 'refund:'.$locked->id, Money::majorFromMinor($sent['amount_minor'], $locked->currency), false);
            $locked->forceFill(['deposit_status' => 'refunded'])->save();
            // lane C: a refund that was waiting after a refusal (any way it now went through) is done
            BookingDepositRefund::query()->where('store_id', $locked->store_id)->where('booking_id', $locked->id)
                ->where('status', 'pending')->update(['status' => 'done', 'resolved_at' => now(), 'updated_at' => now()]);
            log_activity('booking.deposit_refunded', null, ['booking_id' => $locked->id, 'why' => $why] + $sent, (int) $locked->store_id);

            return true;
        });
    }

    /**
     * Lane C bug fix: the refund of a cancelled booking's paid deposit was refused (DepositRefunds threw
     * `refund_refused`, so nothing moved and the deposit is still `paid`). Record it as a pending refund — once per
     * booking; a later refusal only counts the attempt — for the owner's dashboard and "Retry refund".
     *
     * @return bool true when this call created the pending refund (the customer is told once)
     */
    public function recordPendingRefund(Booking $booking, string $reason): bool
    {
        return DB::transaction(function () use ($booking, $reason) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->deposit_status !== 'paid' || $locked->status !== 'cancelled') {
                return false;
            }
            $row = BookingDepositRefund::query()->where('store_id', $locked->store_id)->where('booking_id', $locked->id)->first();
            $reason = mb_substr($reason, 0, 255);
            if ($row instanceof BookingDepositRefund) {
                $row->forceFill([
                    'status' => 'pending', 'attempts' => $row->attempts + 1, 'last_error' => $reason,
                    'last_attempt_at' => now(), 'resolved_at' => null,
                ])->save();

                return false;
            }
            BookingDepositRefund::query()->create([
                'store_id' => (int) $locked->store_id,
                'booking_id' => $locked->id,
                'amount_minor' => (int) $locked->deposit_minor,
                'currency' => (string) $locked->currency,
                'status' => 'pending',
                'attempts' => 1,
                'last_error' => $reason,
                'last_attempt_at' => now(),
            ]);
            log_activity('booking.deposit_refund_pending', null, ['booking_id' => $locked->id, 'reason' => $reason], (int) $locked->store_id);

            return true;
        });
    }

    /**
     * The owner's "Retry refund" of a pending deposit refund: the same refund as a cancel (DepositService::refund —
     * the booking row lock, the deposit state check, DepositRefunds' cap and the payment's RefundGuard), so a double
     * click or two owners refund at most once. Answers `refunded`, `pending` (refused again: the attempt is counted)
     * or `nothing` (no pending refund: already refunded, or not this store's).
     *
     * @return array{outcome: string, message: string|null}
     */
    public function retryRefund(Booking $booking): array
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $pending = BookingDepositRefund::query()->where('store_id', $locked->store_id)->where('booking_id', $locked->id)
                ->where('status', 'pending')->lockForUpdate()->first();
            if (! $pending instanceof BookingDepositRefund || $locked->deposit_status !== 'paid') {
                return ['outcome' => 'nothing', 'message' => null];
            }
            try {
                $this->refund($locked, 'retry');
            } catch (BookingException $e) {
                if ($e->errorCode !== 'refund_refused') {
                    throw $e;
                }
                $pending->forceFill([
                    'attempts' => $pending->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 255), 'last_attempt_at' => now(),
                ])->save();

                return ['outcome' => 'pending', 'message' => $e->getMessage()];
            }

            return ['outcome' => 'refunded', 'message' => null];
        });
    }

    /** Keep a paid deposit (after the deadline, a no-show, or deposit refunds off: D7/D8). */
    public function keep(Booking $booking): void
    {
        Booking::query()->whereKey($booking->id)->where('deposit_status', 'paid')->update(['deposit_status' => 'kept', 'updated_at' => now('UTC')]);
    }

    /**
     * Owner accepts a late deposit (API-27): the original time is re-reserved when it is still free (confirmed),
     * otherwise the booking waits as pending_approval for the owner to agree a new time or refund.
     */
    public function acceptLate(Booking $booking, HoldService $holds, SlotEngine $engine): string
    {
        return DB::transaction(function () use ($booking, $holds, $engine) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->deposit_status !== 'received_late') {
                throw BookingException::notActive();
            }
            $store = Store::query()->findOrFail($locked->store_id);
            $status = 'pending_approval';
            if ($locked->starts_at->isFuture()) {
                try {
                    DB::transaction(function () use ($holds, $engine, $store, $locked) {
                        $holds->purgeExpired((int) $locked->staff_id);
                        $holds->insertCells($store, (int) $locked->staff_id, $engine->cells($locked->block_starts_at, $locked->block_ends_at), ['booking_id' => $locked->id]);
                    });
                    $status = 'confirmed';
                } catch (UniqueConstraintViolationException) {
                    $status = 'pending_approval';
                }
            }
            $locked->forceFill(['deposit_status' => 'paid', 'status' => $status, 'cancelled_at' => null, 'cancelled_by' => null])->save();

            return $status;
        });
    }

    /** Ledger legs: a payment moves clearing → merchant, a refund the other way. */
    private function post(Booking $booking, string $ref, float $amount, bool $pay): void
    {
        if ($amount <= 0) {
            return;
        }
        $ledger = app(Ledger::class);
        $ownerId = (int) Store::query()->whereKey($booking->store_id)->value('user_id');
        $merchant = $ledger->accountFor('merchant', $ownerId, 'merchant', $booking->currency);
        $clearing = $ledger->accountFor('platform', 0, 'platform', $booking->currency);
        $sign = $pay ? 1 : -1;
        $ledger->post([
            ['account' => $clearing, 'amount' => -$sign * $amount, 'allow_negative' => true, 'ref' => 'bkdep:'.$ref.':clr'],
            ['account' => $merchant, 'amount' => $sign * $amount, 'ref' => 'bkdep:'.$ref.':mer'],
        ], null, $pay ? 'booking_deposit.pay' : 'booking_deposit.refund');
    }
}
