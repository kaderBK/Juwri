<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use App\Services\GoogleWallet\GoogleWalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Loyalty\Jobs\SyncGoogleWalletPass;

class LoyaltyAccount extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    public static function forCustomer(int $customerId, int $storeId): self
    {
        // Pass the numeric defaults explicitly: firstOrCreate does NOT hydrate a
        // column's DB default into the in-memory model on the CREATE path, so
        // without these a brand-new account returns balance/lifetime_earned as
        // null on that first request (rendering "null points" until the next
        // load re-SELECTs the row). Keys stay in arg 1; defaults in arg 2.
        return static::firstOrCreate(
            ['customer_id' => $customerId, 'store_id' => $storeId],
            ['balance' => 0, 'lifetime_earned' => 0]
        );
    }

    // Idempotent: earn points/stamps for an order; no-op if ledger row exists for this order
    public function earn(int $orderId, int $delta, string $note = ''): bool
    {
        return DB::transaction(function () use ($orderId, $delta, $note) {
            // unique constraint on order_id prevents double-earn
            $exists = LoyaltyLedger::where('loyalty_account_id', $this->id)
                ->where('order_id', $orderId)->exists();
            if ($exists) {
                return false;
            }

            $account = static::lockForUpdate()->find($this->id);
            $newBalance = $account->balance + $delta;
            $account->update([
                'balance' => $newBalance,
                'lifetime_earned' => $account->lifetime_earned + $delta,
            ]);
            LoyaltyLedger::create([
                'loyalty_account_id' => $account->id,
                'order_id' => $orderId,
                'type' => 'earn',
                'delta' => $delta,
                'balance_after' => $newBalance,
                'note' => $note,
            ]);
            $this->balance = $newBalance;
            $this->syncGoogleWallet();

            return true;
        });
    }

    // Redeem: debit balance, write ledger row.
    // NB: the ledger's order_id column carries a GLOBAL unique index and is already
    // claimed by this order's earn() row, so a redeem row keeps order_id null. The owning
    // order is recorded in the non-unique redeemed_order_id column (and $note) so
    // restoreRedeem() can re-credit these points if the order is later reversed.
    public function redeem(int $delta, string $note = '', ?int $orderId = null): bool
    {
        return DB::transaction(function () use ($delta, $note, $orderId) {
            $account = static::lockForUpdate()->find($this->id);
            if ($account->balance < $delta) {
                return false;
            }
            // Idempotent per owning order: a retried checkout must not debit twice.
            if ($orderId && LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('redeemed_order_id', $orderId)->where('type', 'redeem')->exists()) {
                return false;
            }
            $newBalance = $account->balance - $delta;
            $account->update([
                'balance' => $newBalance,
                'lifetime_redeemed' => $account->lifetime_redeemed + $delta,
            ]);
            LoyaltyLedger::create([
                'loyalty_account_id' => $account->id,
                'order_id' => null,
                'redeemed_order_id' => $orderId,
                'type' => 'redeem',
                'delta' => -$delta,
                'balance_after' => $newBalance,
                'note' => $note,
            ]);
            $this->balance = $newBalance;
            $this->syncGoogleWallet();

            return true;
        });
    }

    /**
     * Restore the points a customer REDEEMED on an order, when that order is cancelled /
     * voided / fully refunded — the reward discount is reversed with the order, so the
     * points they paid for it must come back too (the counterpart to reverseEarn, which
     * claws back the points EARNED). Sums this order's redeem rows (located by the
     * redeemed_order_id column) and writes ONE compensating positive 'adjustment'.
     * Idempotent (note-keyed, one restore per order) and self-contained. No-op when the
     * order redeemed nothing (redeemed_order_id was added in 2026-08; older redeem rows
     * are null and are intentionally not restorable). Returns the points restored.
     */
    public function restoreRedeem(int $orderId, string $note = ''): int
    {
        return DB::transaction(function () use ($orderId, $note) {
            $account = static::lockForUpdate()->find($this->id);

            // Redeem rows store a negative delta; the redeemed amount is their magnitude.
            $redeemed = (int) abs((int) LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('redeemed_order_id', $orderId)->where('type', 'redeem')->sum('delta'));
            if ($redeemed <= 0) {
                return 0; // nothing redeemed against this order (or pre-column rows)
            }

            $restoreNote = $note !== '' ? $note : 'Restored redemption — Order #'.$orderId;
            // P6-240: dedup on (order, positive adjustment) — not on the note text, so two
            // callers passing different notes can't restore twice. The legacy note check
            // stays for restore rows written before this key existed.
            $already = LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('type', 'adjustment')
                ->where(fn ($q) => $q->where(fn ($k) => $k->where('redeemed_order_id', $orderId)->where('delta', '>', 0))
                    ->orWhere(fn ($k) => $k->whereNull('redeemed_order_id')->where('note', $restoreNote)))
                ->exists();
            if ($already) {
                return 0; // already restored
            }

            $newBalance = $account->balance + $redeemed;
            $account->update([
                'balance' => $newBalance,
                'lifetime_redeemed' => max(0, $account->lifetime_redeemed - $redeemed),
            ]);
            LoyaltyLedger::create([
                'loyalty_account_id' => $account->id,
                'order_id' => null,
                'redeemed_order_id' => $orderId,
                'type' => 'adjustment',
                'delta' => $redeemed,
                'balance_after' => $newBalance,
                'note' => $restoreNote,
            ]);
            $this->balance = $newBalance;
            $this->syncGoogleWallet();

            return $redeemed;
        });
    }

    /**
     * A2 fix — atomic reward redemption for the standalone "redeem reward"
     * endpoints. The debounce (redeemedRecently) and the debit MUST happen under
     * the SAME row lock, or two concurrent requests each pass the recency gate
     * before either writes its redeem row and both debit — a customer with a
     * balance >= 2x the threshold loses double the points for one intended
     * redemption. Doing the recency check INSIDE the lockForUpdate transaction
     * makes the loser observe the winner's committed redeem row and abort.
     *
     * Distinct from redeem() (checkout, per-order, deliberately NOT debounced —
     * two orders may each redeem within seconds). Returns a status:
     *   'ok'           — points debited
     *   'recent'       — a redeem happened within $debounceSeconds (429)
     *   'insufficient' — balance below $delta (422)
     */
    public function redeemForReward(int $delta, string $note = '', int $debounceSeconds = 8): string
    {
        return DB::transaction(function () use ($delta, $note, $debounceSeconds) {
            $account = static::lockForUpdate()->find($this->id);

            // Recency check INSIDE the lock: a concurrent redeem that already
            // committed its ledger row is visible here; the loser aborts.
            $recent = LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('type', 'redeem')
                ->where('created_at', '>=', now()->subSeconds($debounceSeconds))
                ->exists();
            if ($recent) {
                return 'recent';
            }
            if ($account->balance < $delta) {
                return 'insufficient';
            }

            $newBalance = $account->balance - $delta;
            $account->update([
                'balance' => $newBalance,
                'lifetime_redeemed' => $account->lifetime_redeemed + $delta,
            ]);
            LoyaltyLedger::create([
                'loyalty_account_id' => $account->id,
                'order_id' => null,
                'type' => 'redeem',
                'delta' => -$delta,
                'balance_after' => $newBalance,
                'note' => $note,
            ]);
            $this->balance = $newBalance;
            $this->syncGoogleWallet();

            return 'ok';
        });
    }

    /**
     * A2 / P6-240 — claw back the points EARNED for an order (on refund / cancel / void).
     *
     * $target is the CUMULATIVE number of earned points that should be forfeited for this
     * order (null = all of them; a partial refund passes its proportional share). The
     * method writes only the difference to what earlier reversals already took, so it is
     * idempotent per order regardless of the note text, and a later bigger refund tops
     * the claw-back up instead of being skipped.
     *
     * Debt rule (DECISIONS J46): the full amount is always clawed back. If the customer
     * already spent the points, the balance goes NEGATIVE — a debt that later earnings
     * repay. Redemption is blocked while the balance is below the reward cost (redeem()
     * and redeemForReward() check balance >= delta, which a negative balance never meets).
     * The ledger row records the real clawed amount, so sum(delta) == balance holds.
     *
     * Reversal rows keep order_id null (the earn row owns the unique order_id) and carry
     * the order in redeemed_order_id with a NEGATIVE delta (restoreRedeem's rows are
     * positive). No-op when the order never earned. Returns true when points were taken.
     */
    /** P6-253: points taken by the last successful reverseEarn() call (for the customer notice). */
    public int $lastClawed = 0;

    public function reverseEarn(int $orderId, string $note = '', ?int $target = null): bool
    {
        $this->lastClawed = 0;

        return DB::transaction(function () use ($orderId, $note, $target) {
            $account = static::lockForUpdate()->find($this->id);

            $earn = LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('order_id', $orderId)->where('type', 'earn')->first();
            if (! $earn || (int) $earn->delta <= 0) {
                return false; // nothing earned for this order
            }
            $earned = (int) $earn->delta;
            $target = $target === null ? $earned : max(0, min($earned, $target));

            // Already forfeited for this order: keyed rows + legacy note-keyed rows
            // (written before the key existed, by either caller's note).
            $already = (int) abs((int) LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('type', 'adjustment')->where('delta', '<', 0)
                ->where(fn ($q) => $q->where('redeemed_order_id', $orderId)
                    ->orWhere(fn ($k) => $k->whereNull('redeemed_order_id')->whereIn('note', [
                        'Reversed earn — Order #'.$orderId,
                        'Reversed earn — refund — Order #'.$orderId,
                    ])))
                ->sum('delta'));

            $claw = $target - $already;
            if ($claw <= 0) {
                return false; // already reversed (up to this share)
            }

            $newBalance = (int) $account->balance - $claw; // may go negative: a debt
            $account->update([
                'balance' => $newBalance,
                'lifetime_earned' => (int) $account->lifetime_earned - $claw,
            ]);
            LoyaltyLedger::create([
                'loyalty_account_id' => $account->id,
                'order_id' => null,
                'redeemed_order_id' => $orderId,
                'type' => 'adjustment',
                'delta' => -$claw,
                'balance_after' => $newBalance,
                'note' => $note !== '' ? $note : 'Reversed earn — Order #'.$orderId,
            ]);
            $this->balance = $newBalance;
            $this->lastClawed = $claw;
            $this->syncGoogleWallet();

            return true;
        });
    }

    /**
     * HPMS E4: idempotent positive credit (referral bonus). Writes an
     * 'adjustment' ledger row (the enum has no 'referral' value) keyed by a
     * unique note so a repeat is a no-op. order_id stays null (many are allowed).
     */
    public function creditOnce(int $delta, string $note): bool
    {
        if ($delta <= 0) {
            return false;
        }

        return DB::transaction(function () use ($delta, $note) {
            $account = static::lockForUpdate()->find($this->id);
            if (LoyaltyLedger::where('loyalty_account_id', $account->id)->where('note', $note)->exists()) {
                return false; // already credited
            }
            $newBalance = $account->balance + $delta;
            $account->update([
                'balance' => $newBalance,
                'lifetime_earned' => $account->lifetime_earned + $delta,
            ]);
            LoyaltyLedger::create([
                'loyalty_account_id' => $account->id,
                'order_id' => null,
                'type' => 'adjustment',
                'delta' => $delta,
                'balance_after' => $newBalance,
                'note' => $note,
            ]);
            $this->balance = $newBalance;
            $this->syncGoogleWallet();

            return true;
        });
    }

    /**
     * HPMS E4: expire points that have been sitting past the window. FIFO model —
     * every debit (redeem + adjustment) is treated as consuming the oldest points
     * first, so the expirable amount is the points earned before the cutoff that
     * no debit has already consumed, clamped to the current balance. Writes ONE
     * negative 'adjustment' row; naturally idempotent (its own debit reduces next
     * run's expirable to zero). Returns the amount expired (0 = nothing to do).
     */
    public function expireStale(int $windowDays, ?string $note = null): int
    {
        if ($windowDays <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($windowDays, $note) {
            $account = static::lockForUpdate()->find($this->id);
            $cutoff = now()->subDays($windowDays);

            $earnedOld = (int) LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('type', 'earn')->where('created_at', '<=', $cutoff)->sum('delta');
            $debited = (int) abs((int) LoyaltyLedger::where('loyalty_account_id', $account->id)
                ->where('delta', '<', 0)->sum('delta'));

            $expire = max(0, $earnedOld - $debited);
            $expire = min($expire, (int) $account->balance);
            if ($expire <= 0) {
                return 0;
            }

            $newBalance = $account->balance - $expire;
            $account->update(['balance' => $newBalance]);
            LoyaltyLedger::create([
                'loyalty_account_id' => $account->id,
                'order_id' => null,
                'type' => 'adjustment',
                'delta' => -$expire,
                'balance_after' => $newBalance,
                'note' => $note ?: 'Points expired (older than '.$windowDays.' days)',
            ]);
            $this->balance = $newBalance;
            $this->syncGoogleWallet();

            return $expire;
        });
    }

    public function ledger()
    {
        return $this->hasMany(LoyaltyLedger::class);
    }

    /**
     * Double-submit guard for the standalone "redeem reward" endpoints: redeem()
     * writes order_id = null, so nothing dedups a second click. True when this
     * account already redeemed within the last $seconds (a debounce, not a hard
     * idempotency key — a genuine second redemption a few seconds later is allowed).
     */
    public function redeemedRecently(int $seconds = 8): bool
    {
        return $this->ledger()
            ->where('type', 'redeem')
            ->where('created_at', '>=', now()->subSeconds($seconds))
            ->exists();
    }

    public function getProgressPctAttribute(): int
    {
        $program = LoyaltyProgram::where('store_id', $this->store_id)->first();
        if (! $program || $program->reward_threshold <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->balance / $program->reward_threshold) * 100));
    }

    /**
     * Google Wallet (google-suite Lane C): after any balance change, queue a pass
     * balance sync so a customer who saved this loyalty card sees the new total.
     * Dark by default — dispatches ONLY when the platform creds are present AND the
     * store toggled google_wallet on. Fully self-guarded: a wallet failure must never
     * break loyalty accrual / redemption. The job re-reads live balance and is
     * dispatched afterCommit so a worker never PATCHes a pre-commit balance.
     */
    private function syncGoogleWallet(): void
    {
        try {
            if (GoogleWalletService::enabledForStore((int) $this->store_id)) {
                // afterCommit: these hooks fire inside the loyalty mutation transaction
                // and queue.after_commit is false, so a worker could otherwise dequeue
                // and PATCH the pre-commit balance. Defer to commit (the gift-card hooks
                // already dispatch post-transaction).
                SyncGoogleWalletPass::dispatch('loyalty', (int) $this->store_id, (int) $this->customer_id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('Google Wallet loyalty sync dispatch failed: '.$e->getMessage());
        }
    }
}
