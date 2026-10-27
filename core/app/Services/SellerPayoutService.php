<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\PartnerEarning;
use App\Models\SellerWithdrawal;
use App\Support\Money\TenderAmount;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\Wallet;

/**
 * P4-6 (Option A — wallet rails): revenue share for marketplace sellers.
 * Every theme sale and app charge credits the seller's wallet with the
 * configured share (default 70/30 seller/platform, Shopify-style); the
 * platform's cut stays implicit. Credits are idempotent per sale ref, so
 * retried flows can never double-pay. Platform-owned assets (null seller)
 * skip the credit entirely.
 *
 * Option B (Stripe Connect Express, cross-border payouts) plugs in behind
 * this same creditSale() seam when seller geography demands it.
 */
class SellerPayoutService
{
    /** The seller's fraction of every sale (0..1). */
    public static function sellerShare(): float
    {
        $share = (float) config('platform.marketplace.seller_share', 0.70);

        return max(0.0, min(1.0, $share));
    }

    /**
     * Credit a seller for one sale. No-op for platform-owned assets or a
     * non-positive gross. Idempotent by ref.
     *
     * @return float the credited amount
     */
    public static function creditSale(?int $sellerId, float $gross, string $ref, string $reason, ?int $buyerId = null, ?string $sourceType = null, ?string $currency = null): float
    {
        if (! $sellerId || $gross <= 0) {
            return 0.0;
        }

        /* P4-7 (feature-platform): self-purchase wash guard — a developer buying
           their own listing earns ZERO share (flagged for the abuse trail). */
        if ($buyerId !== null && $buyerId === $sellerId) {
            try {
                log_activity('marketplace.self_purchase', User::find($sellerId), ['ref' => $ref, 'gross' => $gross], null);
            } catch (\Throwable $e) {
            }

            return 0.0;
        }

        $seller = User::find($sellerId);
        if (! $seller) {
            report(new \RuntimeException("SellerPayout: seller {$sellerId} not found for {$ref}"));

            return 0.0;
        }

        // Round DOWN to the cent — the platform absorbs sub-cent dust, the
        // seller is never overpaid past the share.
        // Bug batch 3 · G: exact product in the wallet currency's minor units (29 × 1% = 0.29, not 0.28; whole yen).
        $amount = TenderAmount::floorShare($gross, static::sellerShare(), app(Ledger::class)->defaultCurrency());
        if ($amount <= 0) {
            return 0.0;
        }

        try {
            // Bug batch 10 · 1: a sale in another currency than the wallet's is refused (caught below, reported).
            app(Wallet::class)->credit($seller, $amount, $reason, 'payout:'.$ref, null, $currency);

            /* P4-7 (feature-platform): write-alongside earnings ledger row —
               idempotent by source_ref; 14-day holdback before withdrawable.
               Reads switch from WalletTransaction LIKE-matching in P5. */
            try {
                PartnerEarning::firstOrCreate(['source_ref' => $ref], [
                    'partner_id' => $seller->id,
                    /* F4-3 (feature-platform gap fix): the caller may state the
                       bucket explicitly — the reason-string sniff below cannot
                       tell a trial CONVERSION (the partner's first real sale)
                       apart from an ordinary renewal, and the bucket drives
                       partner payout reporting. */
                    'source_type' => $sourceType ?: (str_starts_with($reason, 'app usage') ? 'app_usage'
                        : (str_contains($reason, 'renewal') ? 'app_renewal'
                        : (str_starts_with($reason, 'theme') ? 'theme_sale' : 'app_sale'))),
                    'gross' => $gross,
                    'share' => static::sellerShare(),
                    'net' => $amount,
                    'currency' => app(Wallet::class)->currency(), // bug batch 10 · 1: the wallet's, not a display swap
                    'status' => 'pending',
                    'available_at' => now()->addDays(14),
                ]);
            } catch (\Throwable $e) {
                report($e); // ledger row failure never breaks the payout
            }
        } catch (\Throwable $e) {
            // A payout failure must never break the buyer's purchase.
            report($e);

            return 0.0;
        }

        return $amount;
    }

    // --- P9-5a: cash-out (manual admin payout) --------------------------------

    /**
     * Withdrawable balance in one currency (the wallet's by default).
     * P6-231: the wallet balance minus sale credits still inside their 14-day holdback (`available_at` in the future,
     * not reversed) — a sale refunded or charged back in week 1 can still be clawed back. Pending withdrawals are
     * already debited from the wallet.
     */
    public static function availableBalance(User $seller, ?string $currency = null): float
    {
        $code = strtoupper((string) ($currency ?: app(Wallet::class)->currency()));
        $balance = Money::ofMajor((string) app(Wallet::class)->balanceFor($seller, $code), $code);
        $held = Money::ofMinor(self::heldMinor($seller, $code), $code);

        return max(0.0, $balance->minus($held)->toFloat());
    }

    /** P6-231: minor units of $code still in the holdback for this seller. */
    private static function heldMinor(User $seller, string $code): int
    {
        return (int) PartnerEarning::query()
            ->where('partner_id', $seller->id)
            ->where('currency', $code)
            ->where('status', '!=', 'reversed')
            ->whereNotNull('available_at')
            ->where('available_at', '>', now())
            ->sum('net_minor');
    }

    /**
     * P6-231: a refunded / charged-back sale takes back its seller credit while it is still in the holdback.
     * Idempotent per sale ref (the earning row is locked and flipped to `reversed` once). A matured earning is not
     * touched (returns false): the seller may already have been paid, so that one is an admin decision.
     */
    public static function reverseSale(string $ref, string $reason = 'sale reversed'): bool
    {
        return DB::transaction(function () use ($ref, $reason) {
            $earning = PartnerEarning::where('source_ref', $ref)->lockForUpdate()->first();
            if (! $earning || in_array($earning->status, ['reversed', 'clawback_due', 'paid'], true)) {
                return false; // P6-243: a paid-out earning is the admin's call (reverseOrFlag flags it)
            }
            if ($earning->available_at === null || $earning->available_at->lte(now())) {
                return false;
            }

            $seller = User::find($earning->partner_id);
            if ($seller && (float) $earning->net > 0) {
                // Unmatured money cannot have been withdrawn (availableBalance holds it), so the balance covers it.
                app(Wallet::class)->debit($seller, (float) $earning->net, $reason, 'payout:reverse:'.$ref, $earning->minorMoneyCurrency());
            }
            $earning->update(['status' => 'reversed']);

            return true;
        });
    }

    /**
     * P6-243: the ONE seller-side step of a marketplace refund or chargeback (app install, app usage, theme licence).
     * Under the earning's row lock:
     *   - already `reversed` / `clawback_due` -> nothing again (replay, double click, refund after chargeback);
     *   - still in the holdback -> {@see reverseSale()} takes the share back;
     *   - matured but never paid out and still covered by the seller's wallet -> debited back (`payout-clawback:` ref);
     *   - otherwise (paid out / spent) -> nothing is debited: the row is marked `clawback_due` and the admin is alerted,
     *     never silently kept and never driving the seller negative.
     *
     * @param  array<string, mixed>  $context  logged with the outcome (transaction id, actor, …)
     * @return string 'none' (no earning: platform-owned or self-purchase), 'reversed' or 'clawback_due'
     */
    public static function reverseOrFlag(string $ref, string $label, array $context = []): string
    {
        return DB::transaction(function () use ($ref, $label, $context): string {
            $earning = PartnerEarning::where('source_ref', $ref)->lockForUpdate()->first();
            if (! $earning) {
                return 'none';
            }
            if (in_array($earning->status, ['reversed', 'clawback_due'], true)) {
                return (string) $earning->status;
            }

            $partner = User::find($earning->partner_id);
            $ctx = ['earning_id' => $earning->id, 'source_ref' => $ref, 'net' => (float) $earning->net] + $context;

            try {
                $reversed = static::reverseSale($ref, $label);
            } catch (\RuntimeException $e) {
                $reversed = false; // the seller spent the held credit on purchases: flag below, never fail the refund
                $earning->refresh();
            }
            if ($reversed) {
                self::logEarning('partner_earning.clawback', $partner, $ctx);

                return 'reversed';
            }

            $net = (float) $earning->net;
            $currency = $earning->currency ?: null;
            if ($partner && $net > 0 && $earning->status !== 'paid'
                && app(Wallet::class)->balanceFor($partner, $currency) >= $net) {
                app(Wallet::class)->debit($partner, $net, $label, 'payout-clawback:'.$ref, $currency);
                $earning->update(['status' => 'reversed']);
                self::logEarning('partner_earning.clawback', $partner, $ctx);

                return 'reversed';
            }

            $why = $earning->status === 'paid' ? 'already paid out' : 'insufficient seller balance';
            $earning->update(['status' => 'clawback_due']);
            self::logEarning('partner_earning.clawback_due', $partner, $ctx + ['reason' => $why]);
            AlertDispatcher::send('payment', "marketplace clawback due: seller #{$earning->partner_id} keeps {$net} {$earning->currency} of {$ref} ({$why}) — {$label}");

            return 'clawback_due';
        });
    }

    /** @param  array<string, mixed>  $context */
    private static function logEarning(string $event, ?User $partner, array $context): void
    {
        try {
            log_activity($event, $partner, $context, null);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Seller requests a withdrawal. The amount is debited from the wallet NOW
     * (the money choke point), so a pending request can never be double-spent —
     * an over-balance request throws before any row is committed.
     *
     * @throws \InvalidArgumentException on a non-positive amount
     * @throws \RuntimeException on insufficient (withdrawable) balance
     */
    public static function requestWithdrawal(User $seller, float $amount, ?string $rail = null, ?string $currency = null): SellerWithdrawal
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Withdrawal amount must be positive.');
        }

        // P9-5b: default to the platform's active rail (manual | stripe_connect).
        $rail = $rail ?? StripeConnectService::activeRail();

        // Bug batch 10 · 1: drawn from ONE currency's balance (the wallet's by default), stamped with it.
        $currency = strtoupper((string) ($currency ?: app(Wallet::class)->currency()));

        return DB::transaction(function () use ($seller, $amount, $rail, $currency) {
            // P6-231: serialise this seller's requests, then refuse money still in the holdback.
            User::whereKey($seller->id)->lockForUpdate()->first();
            $available = static::availableBalance($seller, $currency);
            if ($amount > $available + 1e-9) {
                throw new \RuntimeException("Withdrawal refused: {$available} {$currency} is withdrawable now (recent sales are held for 14 days).");
            }

            $withdrawal = SellerWithdrawal::create([
                'user_id' => $seller->id,
                'currency_code' => $currency,
                'amount' => $amount,
                'status' => 'pending',
                'rail' => $rail,
            ]);

            // Debit through the choke point; insufficient funds throws and rolls
            // the whole request back (no orphaned pending row).
            app(Wallet::class)->debit($seller, $amount, 'withdrawal', 'withdraw:'.$withdrawal->id, $currency);

            return $withdrawal;
        });
    }

    /**
     * P6-230: run $action on the withdrawal row locked FOR UPDATE and re-read, only while it is still pending. Two
     * admin clicks (pay+pay, pay+reject, reject+reject) serialise here; the second sees a settled row and throws
     * before any side effect. The caller's model is refreshed afterwards.
     *
     * @param  callable(SellerWithdrawal): void  $action
     */
    private static function whilePending(SellerWithdrawal $withdrawal, string $error, callable $action): void
    {
        DB::transaction(function () use ($withdrawal, $error, $action) {
            $locked = SellerWithdrawal::whereKey($withdrawal->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->isPending()) {
                throw new \RuntimeException($error);
            }
            $action($locked);
        });
        $withdrawal->refresh();
    }

    /**
     * Superadmin marks a pending withdrawal paid, recording the external payment
     * reference. The money already left the wallet at request time, so this only
     * records the settlement.
     *
     * @throws \RuntimeException if the withdrawal is not pending
     */
    public static function markPaid(SellerWithdrawal $withdrawal, User $admin, string $reference): void
    {
        self::whilePending($withdrawal, 'Only a pending withdrawal can be marked paid.', function (SellerWithdrawal $w) use ($admin, $reference) {
            self::settlePaid($w, $admin, $reference);
        });
    }

    private static function settlePaid(SellerWithdrawal $w, User $admin, string $reference): void
    {
        $w->update([
            'status' => 'paid',
            'reference' => $reference,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);
    }

    /**
     * P9-5b: settle a pending stripe_connect withdrawal by transferring to the
     * seller's connected account; the Stripe transfer id becomes the reference.
     * P6-230: the transfer runs under the row lock (a second click waits, then sees `paid`); if the commit is lost
     * after Stripe accepted it, the retry reuses the per-withdrawal idempotency key and gets the same transfer back.
     *
     * @throws \RuntimeException if not pending or the seller has no connected account
     */
    public static function payViaStripe(SellerWithdrawal $withdrawal, User $admin): void
    {
        self::whilePending($withdrawal, 'Only a pending withdrawal can be paid.', function (SellerWithdrawal $w) use ($admin) {
            $transferId = StripeConnectService::payoutForWithdrawal($w);
            self::settlePaid($w, $admin, $transferId);
        });
    }

    /**
     * Superadmin rejects a pending withdrawal — the reserved amount is credited
     * back to the seller's wallet.
     *
     * @throws \RuntimeException if the withdrawal is not pending
     */
    public static function rejectWithdrawal(SellerWithdrawal $withdrawal, User $admin, ?string $note = null): void
    {
        self::whilePending($withdrawal, 'Only a pending withdrawal can be rejected.', function (SellerWithdrawal $w) use ($admin, $note) {
            $seller = User::find($w->user_id);
            if ($seller) {
                // Bug batch 10 · 1: back into the currency the withdrawal was drawn from.
                app(Wallet::class)->creditBack($seller, (float) $w->amount, (string) $w->minorMoneyCurrency(), 'withdrawal_rejected', 'withdraw:refund:'.$w->id);
            }

            $w->update([
                'status' => 'rejected',
                'note' => $note,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ]);
        });
    }
}
