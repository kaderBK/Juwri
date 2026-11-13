<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Core\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;

/**
 * The unified double-entry ledger (Coupons & Wallets P1). Every money movement
 * is a `post()` of >=2 legs whose signed amounts sum to zero. Balances are the
 * authoritative SUM(entries.amount); balance_after is only a snapshot. Reads
 * that precede a write take a row lock (lockForUpdate) so concurrent debits
 * cannot both pass the funds check. Corrections are reversals, never edits.
 *
 * A leg is: [
 *   'account'        => LedgerAccount,
 *   'amount'         => float,   // signed: +credit / -debit
 *   'reason'         => ?string,
 *   'ref'            => ?string, // idempotency key, unique per account
 *   'granted_by'     => ?int,
 *   'meta'           => ?array,
 *   'allow_negative' => ?bool,   // true for source/clearing accounts that may go negative
 * ]
 */
class LedgerService
{
    public static function defaultCurrency(): string
    {
        return platform_currency(); // bug batch 10 · 1: never a storefront's display swap
    }

    /** Get-or-create the single account for (owner, kind, currency). */
    public static function accountFor(string $ownerType, int $ownerId, string $kind, ?string $currency = null): LedgerAccount
    {
        $currency = strtoupper($currency ?: static::defaultCurrency());

        return LedgerAccount::firstOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'kind' => $kind, 'currency' => $currency],
            ['status' => 'active']
        );
    }

    /**
     * Authoritative balance (unlocked read; write paths use lockedBalanceMinor). P1-05: an account's
     * entries share its currency, so the integer SUM(amount_minor) is exact.
     */
    public static function balanceFor(LedgerAccount $account): float
    {
        return Money::majorFromMinor((int) LedgerEntry::where('ledger_account_id', $account->id)->sum('amount_minor'), $account->currency);
    }

    /**
     * A leg amount as the ledger posts it, in integer minor units of the account currency (bug batch 4 · 1: at the
     * currency's ISO exponent — KWD/OMR fils, USD cents, JPY whole yen — HALF_UP; before, every leg was cut to 2
     * decimals, the A7 limitation). Rows posted at 2 decimals stay valid: they are whole minor units too.
     */
    protected static function legMinor(float|int|string $amount, ?string $currency): int
    {
        return Money::minorFromMajor($amount, $currency);
    }

    /** The decimals a leg is posted at in $currency: the currency's own ISO exponent (see legMinor). */
    public static function postingDecimals(?string $currency): int
    {
        return Currencies::exponent($currency);
    }

    /** Locked balance in minor units — MUST be used by any write path. Only valid inside a DB::transaction. */
    protected static function lockedBalanceMinor(LedgerAccount $account): int
    {
        return (int) Sql::lockedSum(LedgerEntry::where('ledger_account_id', $account->id), 'amount_minor');
    }

    /**
     * Atomically post a balanced set of legs. Returns the transaction id.
     * - Enforces single-currency and zero-sum, exactly, in integer minor units.
     * - Idempotent: if any leg's (account, ref) already exists, the whole move is
     *   treated as already-applied and its transaction id is returned unchanged.
     * - Each leg's post-balance is checked under lock; a non-source account may
     *   not go negative (pass allow_negative on source/clearing legs).
     */
    public static function post(array $legs, ?string $transactionId = null, string $reason = ''): string
    {
        if (count($legs) < 2) {
            throw new \InvalidArgumentException('A ledger post needs at least two legs.');
        }

        $currencies = array_unique(array_map(fn ($l) => $l['account']->currency, $legs));
        if (count($currencies) > 1) {
            throw new \InvalidArgumentException('All legs of a move must share one currency.');
        }

        $sum = 0;
        foreach ($legs as $leg) {
            $sum += static::legMinor($leg['amount'], $leg['account']->currency);
        }
        if ($sum !== 0) {
            throw new \InvalidArgumentException('Ledger legs must sum to zero (got '.Money::majorFromMinor($sum, $legs[array_key_first($legs)]['account']->currency).').');
        }

        $txId = $transactionId ?: (string) Str::uuid();

        return DB::transaction(function () use ($legs, $txId, $reason) {
            // Idempotency: any already-posted leg ref means the move landed
            // before — return the ORIGINAL transaction id, apply nothing.
            foreach ($legs as $leg) {
                $ref = $leg['ref'] ?? null;
                if (! $ref) {
                    continue;
                }
                $existing = LedgerEntry::where('ledger_account_id', $leg['account']->id)->where('ref', $ref)->first();
                if ($existing) {
                    return $existing->transaction_id;
                }
            }

            foreach ($legs as $leg) {
                /** @var LedgerAccount $account */
                $account = $leg['account'];
                $amountMinor = static::legMinor($leg['amount'], $account->currency);
                $balanceMinor = static::lockedBalanceMinor($account) + $amountMinor;

                if (($leg['allow_negative'] ?? false) !== true && $balanceMinor < 0) {
                    $would = Money::majorFromMinor($balanceMinor, $account->currency);
                    throw new \RuntimeException("Insufficient balance on account {$account->id} (would be {$would}).");
                }

                LedgerEntry::create([
                    'ledger_account_id' => $account->id,
                    'amount' => Money::majorFromMinor($amountMinor, $account->currency),
                    'balance_after' => Money::majorFromMinor($balanceMinor, $account->currency),
                    'transaction_id' => $txId,
                    'type'           => $amountMinor >= 0 ? 'credit' : 'debit',
                    'reason'         => $leg['reason'] ?? $reason,
                    'ref'            => $leg['ref'] ?? null,
                    'granted_by'     => $leg['granted_by'] ?? null,
                    'meta'           => $leg['meta'] ?? null,
                ]);
            }

            return $txId;
        });
    }

    /**
     * Move `$amount` from one account to another as a single balanced,
     * idempotent post. The cross-tier primitive (reseller→merchant funding,
     * refund→customer credit, commission accrual, …). `$ref` is the caller's
     * idempotency key; the two legs derive `$ref:from` / `$ref:to`. Set
     * `$allowFromNegative` only when the source is a platform/clearing account
     * that is allowed to run negative.
     */
    public static function transfer(
        LedgerAccount $from,
        LedgerAccount $to,
        float $amount,
        string $ref,
        string $reason = '',
        bool $allowFromNegative = false
    ): string {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be positive.');
        }

        return static::post([
            ['account' => $from, 'amount' => -$amount, 'ref' => $ref.':from', 'reason' => $reason, 'allow_negative' => $allowFromNegative],
            ['account' => $to, 'amount' => $amount, 'ref' => $ref.':to', 'reason' => $reason],
        ], null, $reason);
    }

    /**
     * Reverse every leg of a prior move with balancing entries (never a delete).
     * Links each original to its reversal via `reversed_by`. Idempotent-safe:
     * already-reversed legs are skipped; reversing a fully-reversed move throws.
     */
    public static function reverse(string $transactionId, string $reason = ''): string
    {
        return DB::transaction(function () use ($transactionId, $reason) {
            $entries = LedgerEntry::with('account')
                ->where('transaction_id', $transactionId)
                ->whereNull('reversed_by')
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                throw new \RuntimeException("No reversible entries for transaction {$transactionId}.");
            }

            $revTx = (string) Str::uuid();
            foreach ($entries as $e) {
                $account = $e->account;
                // The exact inverse, in the entry's own minor units (never re-read through a rounded major amount).
                $amountMinor = -1 * (int) $e->amount_minor;
                $balanceMinor = static::lockedBalanceMinor($account) + $amountMinor;

                $rev = LedgerEntry::create([
                    'ledger_account_id' => $e->ledger_account_id,
                    'amount' => Money::majorFromMinor($amountMinor, $account->currency),
                    'balance_after' => Money::majorFromMinor($balanceMinor, $account->currency),
                    'transaction_id' => $revTx,
                    'type'           => $amountMinor >= 0 ? 'credit' : 'debit',
                    'reason'         => $reason ?: 'reversal of '.$transactionId,
                    'ref'            => 'reverse:'.$transactionId.':'.$e->id,
                    'meta'           => ['reverses_entry' => $e->id, 'reverses_tx' => $transactionId],
                ]);

                // Link the original to its reversal (a non-financial pointer, the
                // one annotation the append-only rule permits).
                $e->update(['reversed_by' => $rev->id]);
            }

            return $revTx;
        });
    }
}
