<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Tenancy\TenantContext;

/**
 * D-P1-21-15: a wallet belongs to one explicit user and every query here is keyed by that user (or by the unique ref),
 * so the wallet runs outside the tenant scope (TenantContext::bypass): a sale credits the SELLER's wallet from the
 * buyer's request, a reseller funds a merchant — under the caller's tenant scope (and PostgreSQL RLS) the seller's
 * rows would be invisible (wrong balance snapshot, missed idempotent ref) and the insert refused. Who may move whose
 * money is the caller's authorisation, as before.
 */
class WalletService
{
    /**
     * P1-4 transition: every wallet movement is also mirrored into the unified
     * double-entry ledger (this user's `merchant` account balanced against the
     * `platform` clearing account). `wallet_transactions` stays authoritative for
     * balanceFor/ledgerFor during the transition, so balances are unchanged and
     * the admin ledger viewer keeps working; the mirror gives the platform tier a
     * ledger record so reconciliation and unified dashboards see every tier.
     * Both mirror legs allow_negative — the wallet op is the funds gate, the
     * ledger only records what it authorized (the mirror can never reject a
     * movement the wallet already approved).
     */
    protected static function mirrorToLedger(User $user, float $signedAmount, string $reason, string $ref, ?int $grantedBy, ?string $currency = null): void
    {
        if (abs($signedAmount) < 0.001) {
            return;
        }
        $currency = $currency ?: static::currency();
        $userAccount = LedgerService::accountFor('merchant', $user->id, 'merchant', $currency);
        $clearing = LedgerService::accountFor('platform', 0, 'platform', $currency);
        $ledgerRef = 'wtxn:'.($ref !== '' ? $ref : (string) Str::uuid());

        LedgerService::post([
            ['account' => $clearing, 'amount' => -$signedAmount, 'allow_negative' => true, 'ref' => $ledgerRef.':clr', 'reason' => $reason, 'granted_by' => $grantedBy],
            ['account' => $userAccount, 'amount' => $signedAmount, 'allow_negative' => true, 'ref' => $ledgerRef.':usr', 'reason' => $reason, 'granted_by' => $grantedBy],
        ], null, $reason);
    }

    /**
     * Bug batch 10 · 1: the wallet's own currency — the platform currency (never a storefront's display swap, see
     * platform_currency()). Balances are per currency: rows in another currency (written before the platform changed
     * currency, or stamped by a storefront request before this fix) are never added to it.
     */
    public static function currency(): string
    {
        return LedgerService::defaultCurrency();
    }

    /**
     * P1-05: amounts are `amount_minor` in each row's currency_code (a legacy row without one reads as the wallet
     * currency). Bug batch 10 · 1: the balance in ONE currency — the wallet's unless $currency is given; other
     * currencies are in balancesByCurrency(), never summed in.
     */
    public static function balanceFor(User $user, ?string $currency = null): float
    {
        $code = Currencies::normalize($currency) ?: static::currency();

        return static::balancesByCurrency($user)[$code] ?? 0.0;
    }

    /**
     * Every currency the user's wallet holds: code => major balance (the wallet currency always present).
     *
     * @return array<string, float>
     */
    public static function balancesByCurrency(User $user): array
    {
        return static::majorByCurrency(TenantContext::bypass(fn () => WalletTransaction::where('user_id', $user->id)
            ->groupBy('currency_code')->selectRaw('currency_code, SUM(amount_minor) as m')->toBase()->get()));
    }

    /**
     * Balances in currencies other than the wallet's, non-zero only (shown apart in the UI — nothing hidden).
     *
     * @return array<string, float>
     */
    public static function otherBalances(User $user): array
    {
        $wallet = static::currency();

        return array_filter(static::balancesByCurrency($user), fn ($v, $code) => $code !== $wallet && abs($v) > 0.0000001, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  iterable<object>  $rows  objects with `currency_code` and `m` (minor units)
     * @return array<string, float>
     */
    protected static function majorByCurrency(iterable $rows): array
    {
        $wallet = static::currency();
        $minor = [$wallet => 0];
        foreach ($rows as $row) {
            $code = Currencies::normalize($row->currency_code ?? null) ?: $wallet;
            $minor[$code] = ($minor[$code] ?? 0) + (int) $row->m;
        }
        $out = [];
        foreach ($minor as $code => $sum) {
            $out[$code] = Money::majorFromMinor($sum, $code);
        }

        return $out;
    }

    /**
     * Balance read that locks the user's ledger rows for the duration of the
     * enclosing DB transaction (SELECT ... FOR UPDATE on MySQL/InnoDB; a no-op
     * on SQLite, which serializes writes at the database level anyway). Callers
     * that then write a debit/credit MUST use this — a plain balanceFor() lets
     * two concurrent debits both pass the funds check and drive the balance
     * negative. Only valid inside a DB::transaction. Bug batch 10 · 1: the balance in $currency only.
     */
    protected static function lockedBalanceFor(User $user, string $currency): float
    {
        // P1-05: lock the rows and sum per currency here (PostgreSQL rejects FOR UPDATE with an aggregate anyway).
        return static::majorByCurrency(TenantContext::bypass(fn () => WalletTransaction::where('user_id', $user->id)->lockForUpdate()
            ->toBase()->get(['currency_code', 'amount_minor as m'])))[$currency] ?? 0.0;
    }

    public static function ledgerFor(User $user, int $limit = 20)
    {
        return TenantContext::bypass(fn () => WalletTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get());
    }

    /**
     * Credit: add balance (positive delta), in the wallet currency.
     * Idempotent via $ref: duplicate ref = no-op, returns existing balance.
     * Bug batch 10 · 1: $currency is the currency the money is in — anything but the wallet currency is refused
     * (no conversion); null = the wallet currency (platform-priced amounts).
     *
     * @throws \InvalidArgumentException a non-positive amount, or money in another currency than the wallet's
     */
    public static function credit(User $user, float $amount, string $reason = '', string $ref = '', ?int $grantedBy = null, ?string $currency = null): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }
        $wallet = static::currency();
        $code = Currencies::normalize($currency) ?: $wallet;
        if ($code !== $wallet) {
            throw new \InvalidArgumentException("Wallet credit refused: the amount is in {$code}, the wallet holds {$wallet} (no conversion).");
        }

        return TenantContext::bypass(fn () => DB::transaction(function () use ($user, $amount, $reason, $ref, $grantedBy, $wallet) {
            if ($ref && WalletTransaction::where('ref', $ref)->exists()) {
                return static::balanceFor($user);
            }

            $balance = static::lockedBalanceFor($user, $wallet) + $amount;
            WalletTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $balance,
                'type' => 'credit',
                'reason' => $reason,
                'ref' => $ref ?: null,
                'granted_by' => $grantedBy,
                'currency_code' => $wallet, // MC-35 / bug batch 10 · 1: the wallet (platform) currency, never a display swap
            ]);
            static::mirrorToLedger($user, $amount, $reason, $ref, $grantedBy, $wallet);

            return $balance;
        }));
    }

    /**
     * Bug batch 10 · 1: return money in its own currency (a rejected withdrawal, a refunded purchase) — which may not be
     * the wallet currency: it lands in that currency's balance (shown apart), never refused, never converted.
     * Idempotent via $ref like credit().
     */
    public static function creditBack(User $user, float $amount, string $currency, string $reason = '', string $ref = ''): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }
        $code = Currencies::normalize($currency) ?: static::currency();

        return TenantContext::bypass(fn () => DB::transaction(function () use ($user, $amount, $reason, $ref, $code) {
            if ($ref && WalletTransaction::where('ref', $ref)->exists()) {
                return static::balanceFor($user, $code);
            }

            $balance = static::lockedBalanceFor($user, $code) + $amount;
            WalletTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $balance,
                'type' => 'credit',
                'reason' => $reason,
                'ref' => $ref ?: null,
                'currency_code' => $code,
            ]);
            static::mirrorToLedger($user, $amount, $reason, $ref, null, $code);

            return $balance;
        }));
    }

    /**
     * Debit: deduct from the balance held in $currency (the wallet currency by default). Throws if that balance is
     * short — another currency's balance never covers it (bug batch 10 · 1).
     */
    public static function debit(User $user, float $amount, string $reason = '', string $ref = '', ?string $currency = null): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }
        $code = Currencies::normalize($currency) ?: static::currency();

        return TenantContext::bypass(fn () => DB::transaction(function () use ($user, $amount, $reason, $ref, $code) {
            if ($ref && WalletTransaction::where('ref', $ref)->exists()) {
                return static::balanceFor($user, $code);
            }

            $balance = static::lockedBalanceFor($user, $code);
            if ($balance < $amount) {
                throw new \RuntimeException("Insufficient wallet balance (have {$balance} {$code}, need {$amount}).");
            }

            $newBalance = $balance - $amount;
            WalletTransaction::create([
                'user_id' => $user->id,
                'amount' => -$amount,
                'balance_after' => $newBalance,
                'type' => 'debit',
                'reason' => $reason,
                'ref' => $ref ?: null,
                'currency_code' => $code, // bug batch 10 · 1: the currency the balance was drawn from
            ]);
            static::mirrorToLedger($user, -$amount, $reason, $ref, null, $code);

            return $newBalance;
        }));
    }
}
