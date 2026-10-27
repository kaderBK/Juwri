<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use App\Models\Order;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;

/**
 * Owner decision 2026-10-01 ("don't refuse on Stripe"): Stripe and Razorpay only charge the 3-decimal currencies
 * (BHD, JOD, KWD, OMR, TND) in steps of 10 fils — "the least-significant (last) digit [must] be 0"
 * (stripe.com/docs/currencies, "Three-decimal currencies"; razorpay.com/docs … integration-steps). An amount between
 * steps is charged ROUNDED DOWN to the step (KWD 12.345 → 12.340): the customer never pays more than the total.
 *
 * The books stay exact: the order/plan total is unchanged (charged + adjustment = total) and the difference is booked
 * as a payment rounding adjustment —
 *  - on the transaction: `details.gateway_rounding` {gateway, currency, total_minor, charged_minor, adjustment_minor,
 *    step_minor, ledger_tx, reversed_tx} (minor units at the currency's ISO exponent — fils);
 *  - in the ledger ({@see LedgerService}), once, when the payment settles ({@see book()}): the merchant's (store's, or
 *    the platform's for plans/add-ons/top-ups) `payment_rounding` account is debited the adjustment and its
 *    `payment_rounding_settled` account credited — the part of the total settled by write-off instead of cash.
 *    Idempotent per transaction (leg refs `rounding:<id>:dr|cr`); a full refund reverses it ({@see afterRefund()}).
 *
 * Settling compares the provider's amount with the rounded-down charge, exactly (anything else is refused, as before);
 * refunds are capped at the charged amount ({@see chargedMinor()}), and a "full" refund of the total refunds what was
 * charged. Before this decision such amounts were refused (and before that, rounded half-up — 5 fils over).
 */
final class GatewayRounding
{
    /** Gateways that charge these currencies only in whole steps, and round the customer's amount DOWN to one. */
    public const ROUND_DOWN = [
        'stripe' => ['BHD', 'JOD', 'KWD', 'OMR', 'TND'],
        'razorpay' => ['BHD', 'JOD', 'KWD', 'OMR', 'TND'],
    ];

    /**
     * The rounding for $money on $gateway in $currency, or null when none applies (the amount is already a whole step,
     * another gateway/currency, or the amount is not in $currency).
     *
     * @return array{gateway: string, currency: string, total_minor: int, charged_minor: int, adjustment_minor: int, step_minor: int}|null
     */
    public static function quote(string $gateway, ?Money $money, ?string $currency = null): ?array
    {
        $gateway = strtolower(trim($gateway));
        $code = strtoupper(trim((string) ($currency ?? $money?->currency())));
        if ($money === null || $money->currency() !== $code || ! in_array($code, self::ROUND_DOWN[$gateway] ?? [], true)) {
            return null;
        }
        $exponent = Currencies::exponent($code);
        if ($money->exponent() !== $exponent || $money->minor() <= 0) {
            return null;
        }
        $step = 10 ** max(0, $exponent - GatewayMinorUnits::gatewayPrecision($gateway, $code));
        $adjustment = $money->minor() % $step;
        if ($step <= 1 || $adjustment === 0) {
            return null;
        }

        return [
            'gateway' => $gateway, 'currency' => $code, 'total_minor' => $money->minor(),
            'charged_minor' => $money->minor() - $adjustment, 'adjustment_minor' => $adjustment, 'step_minor' => $step,
        ];
    }

    /**
     * The integer amount to send to $gateway: the rounded-down charge when {@see quote()} applies, else the exact
     * GatewayMinorUnits::gatewayMinor() (null = the gateway cannot charge it: refuse). For the 3-decimal currencies
     * the gateway's minor unit is the fils, so the charge is the quote's charged_minor as is.
     */
    public static function chargeMinor(string $gateway, ?Money $money, ?string $currency = null): ?int
    {
        $quote = self::quote($gateway, $money, $currency);

        return $quote !== null ? $quote['charged_minor'] : GatewayMinorUnits::gatewayMinor($gateway, $money, $currency);
    }

    /**
     * Remember the rounding on the transaction before the customer is sent to pay (no-op when none applies).
     *
     * @return array<string, mixed>|null
     */
    public static function record(Transaction $transaction, string $gateway, ?string $currency = null): ?array
    {
        $currency ??= Settlement::currency($transaction);
        $quote = self::quote($gateway, $transaction->money('amount'), $currency);
        if ($quote === null) {
            return null;
        }
        $details = self::details($transaction);
        $existing = $details['gateway_rounding'] ?? null;
        if (is_array($existing) && ! empty($existing['ledger_tx'])) {
            return $existing; // already booked: never rewritten
        }
        $details['gateway_rounding'] = $quote + ['ledger_tx' => null, 'reversed_tx' => null];
        $transaction->forceFill(['details' => $details])->save();

        return $details['gateway_rounding'];
    }

    /**
     * The rounding recorded on the transaction, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function recorded(Transaction $transaction): ?array
    {
        $rounding = self::details($transaction)['gateway_rounding'] ?? null;

        return is_array($rounding) && isset($rounding['charged_minor'], $rounding['adjustment_minor']) ? $rounding : null;
    }

    /** What was (or will be) actually charged, in minor units of the transaction currency — null when no rounding. */
    public static function chargedMinor(Transaction $transaction): ?int
    {
        $rounding = self::recorded($transaction);

        return $rounding === null ? null : (int) $rounding['charged_minor'];
    }

    /**
     * Book the adjustment once the payment settled through $gateway (called from Settlement::markPaid/claimPaid and
     * after a Stripe renewal invoice). Records the quote first when the checkout predates the record. Idempotent.
     */
    public static function book(Transaction $transaction, string $gateway): void
    {
        if (! isset(self::ROUND_DOWN[strtolower($gateway)])) {
            return;
        }
        $rounding = self::recorded($transaction) ?? self::record($transaction, $gateway);
        if ($rounding === null || ! empty($rounding['ledger_tx']) || ($rounding['gateway'] ?? null) !== strtolower($gateway)) {
            return;
        }

        try {
            [$type, $id] = self::owner($transaction);
            $currency = (string) $rounding['currency'];
            $adjustment = Money::ofMinor((int) $rounding['adjustment_minor'], $currency)->major();
            $reason = 'payment rounding: '.$gateway.' charged '.Money::ofMinor((int) $rounding['charged_minor'], $currency)->major()
                .' of '.Money::ofMinor((int) $rounding['total_minor'], $currency)->major().' '.$currency.' (transaction '.$transaction->id.')';
            $meta = ['transaction_id' => $transaction->id, 'gateway' => $gateway, 'order_id' => Settlement::isOrder($transaction) ? $transaction->product_id : null];
            $ledgerTx = LedgerService::post([
                ['account' => LedgerService::accountFor($type, $id, 'payment_rounding', $currency), 'amount' => '-'.$adjustment,
                    'ref' => 'rounding:'.$transaction->id.':dr', 'allow_negative' => true, 'meta' => $meta],
                ['account' => LedgerService::accountFor($type, $id, 'payment_rounding_settled', $currency), 'amount' => $adjustment,
                    'ref' => 'rounding:'.$transaction->id.':cr', 'meta' => $meta],
            ], null, $reason);
            self::update($transaction, ['ledger_tx' => $ledgerTx]);
        } catch (\Throwable $e) {
            report($e);
            Log::error("[{$gateway}] payment rounding of transaction {$transaction->id} not booked: ".$e->getMessage());
        }
    }

    /**
     * After a successful refund: once everything charged has been refunded, reverse the booked adjustment (the
     * customer owes nothing any more, so nothing was written off). Partial refunds keep it.
     */
    public static function afterRefund(Transaction $transaction, int $refundedMinor): void
    {
        $rounding = self::recorded($transaction);
        if ($rounding === null || empty($rounding['ledger_tx']) || ! empty($rounding['reversed_tx']) || $refundedMinor < (int) $rounding['charged_minor']) {
            return;
        }
        try {
            $reversal = LedgerService::reverse((string) $rounding['ledger_tx'], 'payment rounding reversed: full refund of transaction '.$transaction->id);
            self::update($transaction, ['reversed_tx' => $reversal]);
        } catch (\Throwable $e) {
            report($e);
            Log::error("payment rounding reversal of transaction {$transaction->id} failed: ".$e->getMessage());
        }
    }

    /** The payer-facing notice before paying with $gateway, or null when no rounding applies. */
    public static function notice(string $gateway, ?Money $money, ?string $currency = null): ?string
    {
        $quote = self::quote($gateway, $money, $currency);
        if ($quote === null) {
            return null;
        }
        $code = $quote['currency'];

        return (string) ___('Card payments in :currency are charged in steps of :step. You will be charged :charged (:difference less than the total).', [
            'currency' => $code,
            'step' => self::format($quote['step_minor'], $code, false),
            'charged' => self::format($quote['charged_minor'], $code),
            'difference' => self::format($quote['adjustment_minor'], $code),
        ]);
    }

    /** The notice when the amount is not known yet (wallet top-up, add-on billing toggle); null when it cannot apply. */
    public static function stepNotice(string $gateway, ?string $currency): ?string
    {
        $code = strtoupper(trim((string) $currency));
        if (! in_array($code, self::ROUND_DOWN[strtolower(trim($gateway))] ?? [], true)) {
            return null;
        }
        $step = 10 ** max(0, Currencies::exponent($code) - GatewayMinorUnits::gatewayPrecision($gateway, $code));

        return (string) ___('Card payments in :currency are charged in steps of :step. A total between steps is charged rounded down to the step, never more than the total.', [
            'currency' => $code, 'step' => self::format($step, $code, false),
        ]);
    }

    /**
     * Receipt lines for a settled transaction with a rounding: [label => value] — the amount charged and the rounding.
     *
     * @return array<string, string>
     */
    public static function receiptLines(?Transaction $transaction): array
    {
        $rounding = $transaction === null ? null : self::recorded($transaction);
        if ($rounding === null) {
            return [];
        }
        $code = (string) $rounding['currency'];

        return [
            (string) ___('Amount charged') => self::format((int) $rounding['charged_minor'], $code),
            (string) ___('Rounded down for card payment') => '−'.self::format((int) $rounding['adjustment_minor'], $code),
        ];
    }

    /** The order's paid gateway transaction carrying a rounding, if any (owner order view, customer order page). */
    public static function orderTransaction(?Order $order): ?Transaction
    {
        if ($order === null || ! $order->is_paid) {
            return null;
        }

        return Transaction::query()
            ->where('transaction_method', 'order')
            ->where('product_id', $order->id)
            ->whereIn('transaction_gatway', array_keys(self::ROUND_DOWN))
            ->whereIn('status', [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED])
            ->latest('id')
            ->get()
            ->first(fn (Transaction $t) => self::recorded($t) !== null);
    }

    private static function format(int $minor, string $code, bool $withCode = true): string
    {
        $major = Money::ofMinor($minor, $code)->major();

        return $withCode ? $major.' '.$code : $major;
    }

    /** @return array{0: string, 1: int} */
    private static function owner(Transaction $transaction): array
    {
        $owner = self::recorded($transaction)['owner'] ?? null; // reseller billing records its owner (StripeBilling)
        if (is_array($owner) && isset($owner[0], $owner[1])) {
            return [(string) $owner[0], (int) $owner[1]];
        }

        // P1-23: a store payment (gift card, top-up, deposit) is charged on the store's own gateway, like an order.
        return Settlement::usesStoreGateway($transaction) ? ['restaurant', (int) $transaction->store_id] : ['platform', 0];
    }

    /** @param array<string, mixed> $changes */
    private static function update(Transaction $transaction, array $changes): void
    {
        DB::transaction(function () use ($transaction, $changes) {
            $row = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first() ?? $transaction;
            $details = self::details($row);
            $details['gateway_rounding'] = array_merge((array) ($details['gateway_rounding'] ?? []), $changes);
            $row->forceFill(['details' => $details])->save();
            $transaction->setRawAttributes($row->getAttributes(), true);
        });
    }

    /** @return array<string, mixed> */
    private static function details(Transaction $transaction): array
    {
        $details = json_decode((string) json_encode($transaction->details ?? []), true);

        return is_array($details) ? $details : [];
    }
}
