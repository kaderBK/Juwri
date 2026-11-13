<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * T5 reservation deposits. Anti-no-show charge taken at booking (fixed or per-guest),
 * ledger-backed. On payment the reservation is confirmed; on cancel it refunds per the
 * owner's policy; on no-show the deposit is kept (booked as merchant revenue).
 */
class ReservationDepositService
{
    public static function enabled(int $storeId): bool
    {
        /* B0-P2: superadmin global kill-switch wins over the per-restaurant toggle.
           FIX-2 F2-5 (gap audit): once `reservation_deposits` is plan_gated the
           kill-switch is no longer the whole story — the STORE OWNER's
           entitlement decides too. Subject is the owner, not the requester: a
           deposit is quoted to an anonymous diner mid-booking. */
        if (! FeatureAccess::storeAllows($storeId, 'reservation_deposits')) {
            return false;
        }

        return ! empty(store_options($storeId, 'reservation_deposit_enabled'));
    }

    /** Does a party of this size require a deposit for this restaurant? */
    public static function required(int $storeId, int $partySize): bool
    {
        if (! self::enabled($storeId)) {
            return false;
        }
        $min = (int) store_options($storeId, 'reservation_deposit_min_party', 1);

        return $partySize >= max(1, $min);
    }

    /** Deposit amount for a party: per-guest × party, or a flat per-reservation amount. */
    public static function amountFor(int $storeId, int $partySize): float
    {
        $base = (float) store_options($storeId, 'reservation_deposit_amount', 0);
        $per = (string) (store_options($storeId, 'reservation_deposit_per') ?: 'reservation');
        $amount = $per === 'guest' ? $base * max(1, $partySize) : $base;

        return Money::round(max(0, $amount), Currencies::exponent(self::currencyFor($storeId))); // bug batch 4 · 1
    }

    public static function currencyFor(int $storeId): string
    {
        return (string) (store_options($storeId, 'currency_code') ?: app(Ledger::class)->defaultCurrency());
    }

    /**
     * Start a deposit charge for a reservation: create the pending Transaction and link
     * it. Caller dispatches the gateway; markPaid() runs on confirmation.
     */
    public static function initiate(Reservation $reservation, string $gatewayFolder): Transaction
    {
        $rid = (int) $reservation->store_id;

        /* FIX-2 F2-5: never open a charge for a store that is not entitled —
           this is the money door, so it re-asserts rather than trusting the
           caller to have consulted enabled()/required() first. Deliberately NOT
           applied to markPaid/refund/keep: those continue a deposit that was
           already taken, and stranding a diner's money behind a lapsed
           subscription would be the worse failure. */
        if (! self::enabled($rid)) {
            abort(403, ___('This feature is not included in your current plan.'));
        }
        $amount = self::amountFor($rid, (int) $reservation->party_size);
        $ownerId = (int) Store::where('id', $rid)->value('user_id');

        $transaction = Transaction::create([
            'product_name' => __('Reservation deposit'),
            'product_id' => 0,
            'user_id' => $ownerId,
            'base_amount' => $amount,
            'amount' => $amount,
            'currency_code' => self::currencyFor($rid),
            'transaction_method' => 'reservation_deposit',
            'store_id' => $rid, // P1-23: charged on the store's own gateway
            'transaction_gatway' => $gatewayFolder,
            'transaction_description' => __('Reservation deposit').' #'.$reservation->id,
            'frequency' => 'LIFETIME',
            'status' => null,
        ]);

        $reservation->deposit_amount = $amount;
        $reservation->deposit_status = 'pending';
        $reservation->deposit_transaction_id = $transaction->id;
        $reservation->currency = self::currencyFor($rid);
        $reservation->save();

        return $transaction;
    }

    /** Confirmed deposit payment → hold the funds and confirm the reservation. Idempotent. */
    public static function markPaid(Reservation $reservation, Transaction $transaction): void
    {
        DB::transaction(function () use ($reservation, $transaction) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->deposit_status === 'paid') {
                return; // idempotent
            }
            $ownerId = (int) Store::where('id', $reservation->store_id)->value('user_id');
            $currency = $reservation->currency ?: self::currencyFor((int) $reservation->store_id);
            $merchant = app(Ledger::class)->accountFor('merchant', $ownerId, 'merchant', $currency);
            $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $currency);
            app(Ledger::class)->post([
                ['account' => $clearing, 'amount' => -(float) $reservation->deposit_amount, 'allow_negative' => true, 'ref' => 'resdep:pay:'.$transaction->id.':clr'],
                ['account' => $merchant, 'amount' => (float) $reservation->deposit_amount, 'ref' => 'resdep:pay:'.$transaction->id.':mer'],
            ], null, 'reservation_deposit.pay');

            $reservation->deposit_status = 'paid';
            if (in_array($reservation->status, ['pending', null], true)) {
                $reservation->status = 'confirmed';
            }
            $reservation->save();
        });
    }

    /**
     * P6-336 (Opus: money): refund a paid deposit per policy (none/full/partial %) through the store's own gateway —
     * the same path booking deposits use (Gateways::refundStorePayment → adapter → RefundGuard, which caps against the
     * payment's own refund ledger under its row lock and hands the provider an idempotency key).
     *
     * Rules: the deposit state is re-read under the reservation's row lock, so a double cancel (owner + Google) refunds
     * at most once; the amount is capped at the deposit and at what the payment took; ledger legs are posted only when
     * the provider confirmed the refund (`refunded`). When the gateway cannot refund automatically the deposit becomes
     * `refund_due` (the owner completes it in the provider dashboard, then confirmManualRefund() books it); nothing is
     * posted. A refusal before any provider call leaves the deposit `paid`. Returns the amount the provider refunded.
     */
    public static function refund(Reservation $reservation): float
    {
        $storeId = (int) $reservation->store_id;
        $policy = (string) (store_options($storeId, 'reservation_deposit_refund') ?: 'full');

        return DB::transaction(function () use ($reservation, $storeId, $policy) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->deposit_status !== 'paid') {
                return 0.0;
            }
            $currency = $locked->currency ?: self::currencyFor($storeId);
            $refund = self::policyAmount($locked, $policy, $currency);
            $transaction = $locked->deposit_transaction_id
                ? Transaction::query()->where('store_id', $storeId)->whereKey($locked->deposit_transaction_id)->first()
                : null;
            if ($transaction !== null) {
                $paidMinor = Money::minorFromMajor((float) $transaction->amount, (string) ($transaction->currency_code ?: $currency));
                $refund = min($refund, Money::majorFromMinor($paidMinor, $currency));
            }
            if ($refund <= 0) {
                $locked->deposit_status = 'kept';
                $locked->save();

                return 0.0;
            }

            $result = $transaction !== null
                ? app(Gateways::class)->refundStorePayment($transaction, $refund, $currency)
                : ['automatic' => false, 'gateway' => null, 'reference' => null, 'message' => null];

            if (! empty($result['refused'])) {
                log_activity('reservation.deposit_refund_refused', null, ['reservation_id' => $locked->id, 'amount' => $refund, 'message' => $result['message'] ?? null], $storeId);

                return 0.0;
            }
            if (empty($result['automatic'])) {
                $locked->deposit_status = 'refund_due';
                $locked->save();
                log_activity('reservation.deposit_refund_due', null, ['reservation_id' => $locked->id, 'amount' => $refund, 'gateway' => $result['gateway'] ?? null], $storeId);

                return 0.0;
            }

            self::postRefund($locked, $refund, $currency);
            $locked->deposit_status = 'refunded';
            $locked->save();
            log_activity('reservation.deposit_refunded', null, ['reservation_id' => $locked->id, 'amount' => $refund, 'gateway' => $result['gateway'] ?? null, 'reference' => $result['reference'] ?? null], $storeId);

            return $refund;
        });
    }

    /**
     * P6-336: the owner refunded a `refund_due` deposit in the provider dashboard — book it (ledger legs, idempotent
     * by ref) and mark it refunded. Re-checked under the row lock; returns the amount booked (0 if not refund_due).
     */
    public static function confirmManualRefund(Reservation $reservation): float
    {
        $storeId = (int) $reservation->store_id;
        $policy = (string) (store_options($storeId, 'reservation_deposit_refund') ?: 'full');

        return DB::transaction(function () use ($reservation, $storeId, $policy) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->deposit_status !== 'refund_due') {
                return 0.0;
            }
            $currency = $locked->currency ?: self::currencyFor($storeId);
            $refund = self::policyAmount($locked, $policy, $currency);
            if ($refund > 0) {
                self::postRefund($locked, $refund, $currency);
            }
            $locked->deposit_status = 'refunded';
            $locked->save();
            log_activity('reservation.deposit_refunded', null, ['reservation_id' => $locked->id, 'amount' => $refund, 'manual' => true], $storeId);

            return $refund;
        });
    }

    /** Policy amount, capped at the deposit, rounded to the currency exponent. */
    private static function policyAmount(Reservation $locked, string $policy, string $currency): float
    {
        $amount = (float) $locked->deposit_amount;
        $refund = match ($policy) {
            'none' => 0.0,
            'partial' => $amount * max(0.0, min(100.0, (float) store_options((int) $locked->store_id, 'reservation_deposit_refund_pct', 50))) / 100,
            default => $amount, // full
        };

        return Money::round(max(0.0, min($refund, $amount)), Currencies::exponent($currency));
    }

    private static function postRefund(Reservation $locked, float $refund, string $currency): void
    {
        $ownerId = (int) Store::where('id', $locked->store_id)->value('user_id');
        $merchant = app(Ledger::class)->accountFor('merchant', $ownerId, 'merchant', $currency);
        $clearing = app(Ledger::class)->accountFor('platform', 0, 'platform', $currency);
        app(Ledger::class)->post([
            ['account' => $merchant, 'amount' => -$refund, 'ref' => 'resdep:refund:'.$locked->id.':mer'],
            ['account' => $clearing, 'amount' => $refund, 'allow_negative' => true, 'ref' => 'resdep:refund:'.$locked->id.':clr'],
        ], null, 'reservation_deposit.refund');
    }

    /** Keep a paid deposit (no-show / non-refundable) — it stays as merchant revenue. */
    public static function keep(Reservation $reservation): void
    {
        if ($reservation->deposit_status !== 'paid') {
            return;
        }
        $reservation->deposit_status = 'kept';
        $reservation->save();
    }
}
