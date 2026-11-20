<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Refunds;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayCurrencies;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Juwri\Modules\Payments\Services\Gateways\Settlement;

/**
 * Bug batch 6 — every gateway refund goes through here before (and after) the provider is called.
 *
 * Owner rules 2026-10-01: never refund a number in another currency; exact amounts. A refund is:
 *  (a) in the transaction's OWN stamped currency ({@see Settlement::currency()}). A caller that says which currency
 *      its number is in (an order refund passes the order's currency) must name that same currency — never converted;
 *  (b) never more than the captured amount (`transaction.amount_minor`, or the rounded-down charge recorded by
 *      {@see GatewayRounding} — Stripe/Razorpay KWD 12.345 charged 12.340) minus the refunds already made on it;
 *  (c) exact: the number has no more decimals than the currency's storage exponent, and the gateway's wire format
 *      (its decimals, Stripe's ×10 fils rule) carries it without rounding.
 * Anything else is refused with a clear message and a warning log, before any provider call.
 *
 * Refunds are tracked on the transaction in minor units (`details.gateway_refunds`, each entry in the transaction's
 * currency). The amount is reserved under a row lock BEFORE the provider is called, so two concurrent refunds can
 * never both pass the cap; a provider refusal releases the reservation (kept as `failed` for the audit trail). A
 * reservation whose outcome is unknown (the provider call threw — timeout, dropped connection — or the process died
 * during it) stays `pending` and keeps counting as refunded — the safe side — and is reconciled by hand; only an
 * explicit provider refusal (ok=false returned, no exception) releases it. Adapters let
 * {@see ConnectionException} through to here for that reason. A legacy `details.auto_refund` (WI-06 platform auto refund, written before this
 * ledger) counts too.
 *
 * P6-80 idempotency: every reservation's ledger entry id (a UUID, persisted on the entry) is handed to $send as the
 * provider idempotency key. For a gateway that honours one ($idempotent: Stripe `Idempotency-Key`, PayPal
 * `PayPal-Request-Id`, Mollie `Idempotency-Key`), a retry of the SAME amount after an unknown outcome reuses that
 * pending entry and its key — the provider answers with the first refund instead of making a second one, and the
 * entry settles. Elsewhere a retry is a new reservation (the unknown one still counts against the cap) and the
 * operator reconciles with the provider dashboard first.
 */
final class RefundGuard
{
    /**
     * money-last NIT 6: the container key holding the purpose of the refunds run inside {@see forPurpose()} (unbound =
     * no purpose given). A container instance, not a class static: it dies with the application (tests, Octane).
     */
    private const PURPOSE = 'juwri.refund_guard.purpose';

    /**
     * money-last NIT 6 (REVIEW, Opus: money): run $call with every reservation it makes tagged with $purpose (e.g. a
     * gift-card payout's ref). A retry after an unknown outcome reuses only a pending entry of the SAME purpose, so two
     * different payouts of an identical amount on one payment are two refunds, never one answered twice. Untagged
     * calls keep the old matching (an untagged retry reuses an untagged entry only).
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function forPurpose(string $purpose, callable $call): mixed
    {
        $previous = self::purpose();
        app()->instance(self::PURPOSE, $purpose);
        try {
            return $call();
        } finally {
            if ($previous === null) {
                app()->forgetInstance(self::PURPOSE);
            } else {
                app()->instance(self::PURPOSE, $previous);
            }
        }
    }

    /** The purpose set by the enclosing {@see forPurpose()}, or null. */
    private static function purpose(): ?string
    {
        $purpose = app()->bound(self::PURPOSE) ? app(self::PURPOSE) : null;

        return is_string($purpose) ? $purpose : null;
    }

    /**
     * Refund $amount of $transaction through $send, guarded as described above.
     *
     * @param  float  $amount  major units, in $currency (null = the transaction's own currency)
     * @param  int  $wireDecimals  how many decimals the gateway's refund amount carries for this currency
     * @param  bool  $tenFils  the gateway's minor units for 3-decimal currencies must be a multiple of 10 (Stripe rule)
     * @param  callable(Money, string): array{ok:bool, reference:?string, message:?string}  $send  the provider call;
     *                                                                                             2nd arg = idempotency key (the ledger entry id)
     * @param  bool  $idempotent  the provider honours the key: a retry after an unknown outcome reuses the pending entry
     * @return array{ok:bool, reference:?string, message:?string, refused?:true}
     */
    public static function run(Transaction $transaction, float $amount, ?string $currency, string $gateway, int $wireDecimals, callable $send, bool $tenFils = false, bool $idempotent = false): array
    {
        $retry = $idempotent ? self::unknownEntry($transaction, $amount, $currency, $gateway) : null;
        if ($retry !== null) {
            [$entryId, $money] = $retry;
            Log::info("[{$gateway}] retrying refund ledger entry {$entryId} on transaction {$transaction->id} with the same idempotency key");
        } else {
            $money = self::check($transaction, $amount, $currency, $gateway, $wireDecimals, $tenFils);
            if (is_string($money)) {
                return self::refused($transaction, $gateway, $money);
            }

            [$entryId, $refusal] = self::reserve($transaction, $money, $gateway);
            if ($entryId === null) {
                return self::refused($transaction, $gateway, (string) $refusal);
            }
        }

        try {
            $result = $send($money, $entryId);
        } catch (\Throwable $e) {
            self::markUnknown($transaction, $entryId);
            // The provider call did not answer (timeout, dropped connection, ...): the refund may have gone through.
            // Keep the reservation PENDING — it still counts against the cap, so a retry cannot refund twice — and
            // leave it for reconciliation against the provider dashboard.
            report($e);
            Log::error("[{$gateway}] refund outcome UNKNOWN on transaction {$transaction->id} (ledger entry {$entryId}, {$money}): "
                .$e->getMessage().' — kept as pending; reconcile with the provider before refunding again.');

            return ['ok' => false, 'reference' => null, 'message' => (string) ___('The payment provider did not confirm this refund. Check your payment provider dashboard before trying again.')];
        }

        // Only an explicit answer from the provider settles the entry: ok → counted, a refusal → released.
        $ok = ! empty($result['ok']);
        self::finish($transaction, $entryId, $ok, $ok ? ($result['reference'] ?? null) : null);
        if ($ok) {
            // Everything charged refunded → the booked rounding adjustment is reversed (GatewayRounding).
            GatewayRounding::afterRefund($transaction, self::confirmedMinor(self::details($transaction)));
            Log::info("[{$gateway}] refunded {$money} on transaction {$transaction->id} (reference ".($result['reference'] ?? '-').')');
        } else {
            Log::warning("[{$gateway}] provider refused the refund of {$money} on transaction {$transaction->id}: ".($result['message'] ?? '-'));
        }

        return ['ok' => $ok, 'reference' => $result['reference'] ?? null, 'message' => $result['message'] ?? null];
    }

    /**
     * Minor units already refunded (or reserved) on the transaction, in its own currency — null when its refund
     * history holds another currency (an unstamped legacy row whose store/platform currency moved): reconcile by hand.
     */
    public static function refundedMinor(Transaction $transaction): ?int
    {
        return self::ledgerTotal(self::details($transaction), Settlement::currency($transaction));
    }

    /** The validated refund amount, or the reason it is refused. */
    private static function check(Transaction $transaction, float $amount, ?string $currency, string $gateway, int $wireDecimals, bool $tenFils): Money|string
    {
        $own = Settlement::currency($transaction);
        if (! in_array($transaction->status, [Transaction::STATUS_SUCCESS, Transaction::STATUS_CONSUMED], true)) {
            return (string) ___('This payment is not settled, so there is nothing to refund.');
        }
        if ($currency !== null && strtoupper(trim($currency)) !== $own) {
            return (string) ___('This refund is in :given but the payment was made in :currency. Refunds are only made in the currency of the payment.', [
                'given' => strtoupper(trim($currency)), 'currency' => $own,
            ]);
        }
        if (isset(GatewayCurrencies::FIXED[$gateway]) && ! in_array($own, GatewayCurrencies::FIXED[$gateway], true)) {
            return (string) ___('This payment method cannot charge in :currency. Please choose another payment method.', ['currency' => $own]);
        }
        if (! is_finite($amount) || $amount <= 0) {
            return (string) ___('Nothing to refund.');
        }

        $exact = Money::decimal($amount);
        // Stripe/Razorpay round-down (GatewayRounding): the payment charged less than the total — a refund of the
        // total is a full refund of what was charged (KWD 12.345 → 12.340); the cap below never lets more through.
        $charged = GatewayRounding::recorded($transaction);
        if ($charged !== null && $exact->isEqualTo(Money::ofMinor((int) $charged['total_minor'], $own)->toBrick()->getAmount())) {
            $exact = Money::ofMinor((int) $charged['charged_minor'], $own)->toBrick()->getAmount();
        }
        if (! self::fitsScale($exact, Currencies::exponent($own)) || ! self::fitsScale($exact, $wireDecimals)
            || ($tenFils && $wireDecimals === 3 && ! self::fitsScale($exact, 2))) {
            return (string) ___('The refund amount :amount cannot be refunded exactly in :currency on this payment method. Enter an amount the payment method supports.', [
                'amount' => (string) $exact, 'currency' => $own,
            ]);
        }
        $money = Money::ofMajor((string) $exact, $own);

        return self::capRefusal($transaction, self::details($transaction), $money) ?? $money;
    }

    /**
     * Null when $money fits under the cap (captured minus refunded), else the refusal.
     *
     * @param  array<string, mixed>  $details
     */
    private static function capRefusal(Transaction $transaction, array $details, Money $money): ?string
    {
        $currency = Settlement::currency($transaction);
        $refunded = self::ledgerTotal($details, $currency);
        if ($refunded === null || $money->currency() !== $currency) {
            return (string) ___('This payment has refunds in another currency. Refund it in your payment provider dashboard.');
        }
        $remaining = self::capturedMinor($transaction) - $refunded;
        if ($money->minor() > $remaining) {
            return (string) ___('The refund is more than what is left to refund on this payment (:amount).', [
                'amount' => Money::ofMinor(max(0, $remaining), $currency)->major().' '.$currency,
            ]);
        }

        return null;
    }

    /**
     * Reserve the amount under a row lock (the cap is re-checked there: a concurrent refund may have moved it).
     *
     * @return array{0: ?string, 1: ?string} [the new ledger entry's id, null] or [null, the refusal]
     */
    private static function reserve(Transaction $transaction, Money $money, string $gateway): array
    {
        return DB::transaction(function () use ($transaction, $money, $gateway) {
            $row = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first() ?? $transaction;
            $details = self::details($row);
            $currency = Settlement::currency($row);
            $refusal = self::capRefusal($row, $details, $money);
            if ($refusal !== null) {
                return [null, $refusal];
            }

            $id = (string) Str::uuid();
            $details['gateway_refunds'][] = [
                'id' => $id, 'gateway' => $gateway, 'amount_minor' => $money->minor(), 'currency' => $currency,
                'status' => 'pending', 'reference' => null, 'at' => now()->toIso8601String(),
            ] + (self::purpose() !== null ? ['purpose' => self::purpose()] : []);
            $details['refunded_minor'] = self::ledgerTotal($details, $currency);
            $row->forceFill(['details' => $details])->save();
            $transaction->setRawAttributes($row->getAttributes(), true);

            return [$id, null];
        });
    }

    /**
     * P6-80: a pending entry of this gateway, for exactly this amount, whose provider call had no answer — the one a
     * retry must reuse (same key). Null when there is none (a normal new refund).
     *
     * @return array{0: string, 1: Money}|null
     */
    private static function unknownEntry(Transaction $transaction, float $amount, ?string $currency, string $gateway): ?array
    {
        $own = Settlement::currency($transaction);
        if (! is_finite($amount) || $amount <= 0 || ($currency !== null && strtoupper(trim($currency)) !== $own)) {
            return null;
        }
        $exact = Money::decimal($amount);
        if (! self::fitsScale($exact, Currencies::exponent($own))) {
            return null;
        }
        $minor = Money::ofMajor((string) $exact, $own)->minor();
        foreach ((array) (self::details($transaction)['gateway_refunds'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['status'] ?? null) === 'pending' && ! empty($entry['unknown'])
                && ($entry['gateway'] ?? null) === $gateway && (int) ($entry['amount_minor'] ?? -1) === $minor
                && strtoupper((string) ($entry['currency'] ?? '')) === $own && is_string($entry['id'] ?? null)
                && ($entry['purpose'] ?? null) === self::purpose()) {
                return [$entry['id'], Money::ofMinor($minor, $own)];
            }
        }

        return null;
    }

    /** The provider call for this entry had no answer: a retry of the same amount may reuse its key (P6-80). */
    private static function markUnknown(Transaction $transaction, string $entryId): void
    {
        try {
            DB::transaction(function () use ($transaction, $entryId) {
                $row = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first() ?? $transaction;
                $details = self::details($row);
                foreach ($details['gateway_refunds'] ?? [] as $i => $entry) {
                    if (($entry['id'] ?? null) === $entryId && ($entry['status'] ?? null) === 'pending') {
                        $details['gateway_refunds'][$i]['unknown'] = true;
                    }
                }
                $row->forceFill(['details' => $details])->save();
                $transaction->setRawAttributes($row->getAttributes(), true);
            });
        } catch (\Throwable $e) {
            report($e); // the entry stays pending (counted) either way
        }
    }

    private static function finish(Transaction $transaction, string $entryId, bool $ok, ?string $reference): void
    {
        DB::transaction(function () use ($transaction, $entryId, $ok, $reference) {
            $row = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first() ?? $transaction;
            $details = self::details($row);
            foreach ($details['gateway_refunds'] ?? [] as $i => $entry) {
                if (($entry['id'] ?? null) === $entryId) {
                    $details['gateway_refunds'][$i]['status'] = $ok ? 'ok' : 'failed';
                    $details['gateway_refunds'][$i]['reference'] = $reference;
                    unset($details['gateway_refunds'][$i]['unknown']);
                }
            }
            $details['refunded_minor'] = self::ledgerTotal($details, Settlement::currency($row));
            $row->forceFill(['details' => $details])->save();
            $transaction->setRawAttributes($row->getAttributes(), true);
        });
    }

    /**
     * Minor units refunded or reserved; null when an entry is in another currency (never netted across currencies).
     *
     * @param  array<string, mixed>  $details
     */
    private static function ledgerTotal(array $details, string $currency): ?int
    {
        $total = 0;
        foreach ((array) ($details['gateway_refunds'] ?? []) as $entry) {
            if (! is_array($entry) || ! in_array($entry['status'] ?? null, ['ok', 'pending'], true)) {
                continue;
            }
            if (strtoupper((string) ($entry['currency'] ?? '')) !== $currency) {
                return null;
            }
            $total += (int) ($entry['amount_minor'] ?? 0);
        }
        $legacy = $details['auto_refund'] ?? null;
        if (is_array($legacy) && empty($legacy['ledgered']) && isset($legacy['amount']) && is_numeric($legacy['amount'])) {
            $total += Money::minorFromMajor((float) $legacy['amount'], $currency);
        }

        return $total;
    }

    /** What the provider captured: the rounded-down charge when GatewayRounding applied, else the stored amount. */
    private static function capturedMinor(Transaction $transaction): int
    {
        return GatewayRounding::chargedMinor($transaction) ?? (int) ($transaction->getAttributes()['amount_minor'] ?? 0);
    }

    /**
     * Minor units of the refunds the provider confirmed (status ok) — not the pending ones.
     *
     * @param  array<string, mixed>  $details
     */
    private static function confirmedMinor(array $details): int
    {
        $total = 0;
        foreach ((array) ($details['gateway_refunds'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['status'] ?? null) === 'ok') {
                $total += (int) ($entry['amount_minor'] ?? 0);
            }
        }

        return $total;
    }

    /** @return array<string, mixed> */
    private static function details(Transaction $transaction): array
    {
        $details = json_decode((string) json_encode($transaction->details ?? []), true);

        return is_array($details) ? $details : [];
    }

    private static function fitsScale(BigDecimal $value, int $scale): bool
    {
        try {
            $value->toScale(max(0, $scale));

            return true;
        } catch (RoundingNecessaryException) {
            return false;
        }
    }

    /**
     * A refund this guard refused (nothing was sent): `refused` tells callers not to record it as a manual refund.
     *
     * @return array{ok:false, reference:null, message:string, refused:true}
     */
    private static function refused(Transaction $transaction, string $gateway, string $message): array
    {
        Log::warning("[{$gateway}] refund refused on transaction {$transaction->id}: {$message}");

        return ['ok' => false, 'reference' => null, 'message' => $message, 'refused' => true];
    }
}
