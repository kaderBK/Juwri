<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Content\Models\StudioCreditLedger;
use Juwri\Modules\Content\Models\StudioSession;

/**
 * Server-side credit ledger for the Menu Studio. Balance is derived from the
 * append-only ledger; debits are checked + written inside a locked transaction
 * so the client can never overspend, and paid actions are refunded on failure.
 *
 * Ownership overlay (W1-CREDIT): once a session belongs to an account
 * (`user_id !== null`) balance/credit/debit — and the credit() ref-dedupe —
 * operate over ALL of that owner's rows (`where('user_id', ...)`), so paid
 * credits follow the account across sessions and devices and never strand.
 * Guests are unchanged: scoped to their single session's own null-user rows.
 */
class StudioCreditService
{
    public function balance(StudioSession $session): int
    {
        return (int) $this->scopeFor($session)->sum('delta');
    }

    /**
     * Add credits (e.g. a settled top-up). Idempotent when $ref is given — a
     * repeated top-up callback with the same ref is a no-op. For an owned
     * session the dedupe spans the whole account, so a re-delivered Stripe
     * event never double-credits even if it lands on a different session.
     */
    public function credit(StudioSession $session, int $amount, string $reason, ?string $ref = null): void
    {
        if ($amount <= 0) {
            return;
        }
        if ($ref !== null && $this->scopeFor($session)->where('ref', $ref)->exists()) {
            return; // already applied
        }
        StudioCreditLedger::create([
            'studio_session_id' => $session->id,
            'user_id' => $session->user_id,
            'delta' => $amount,
            'reason' => $reason,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }

    /**
     * Ledger rows in this session's account scope whose ref equals $ref (or,
     * with $prefix, starts with it). Used for referral dedupe and caps so an
     * owner is judged across all their sessions, like credit()'s dedupe.
     */
    public function refCount(StudioSession $session, string $ref, bool $prefix = false): int
    {
        $query = $this->scopeFor($session);

        return $prefix
            ? $query->where('ref', 'like', $ref.'%')->count()
            : $query->where('ref', $ref)->count();
    }

    /**
     * Atomically debit if the balance covers $cost. Returns true on success,
     * false when the balance is insufficient (no row written). For an owner the
     * spend draws down the account-wide balance across all their sessions.
     */
    public function debit(StudioSession $session, int $cost, string $reason): bool
    {
        if ($cost <= 0) {
            return true;
        }

        return DB::transaction(function () use ($session, $cost, $reason) {
            // Lock the scoped ledger rows for the balance read. lockForUpdate is
            // real on MySQL (prod); a no-op on sqlite (tests) — keep it either way.
            $balance = (int) Sql::lockedSum($this->scopeFor($session), 'delta');
            if ($balance < $cost) {
                return false;
            }
            StudioCreditLedger::create([
                'studio_session_id' => $session->id,
                'user_id' => $session->user_id,
                'delta' => -$cost,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * Run a paid action: debit first (server-side guard), execute, and REFUND if
     * the action throws. Throws InsufficientCreditsException when the balance is
     * too low so the caller can prompt a top-up.
     *
     * @template T
     *
     * @param  callable():T  $action
     * @return T
     */
    public function spend(StudioSession $session, int $cost, string $reason, callable $action)
    {
        if (! $this->debit($session, $cost, $reason)) {
            throw new InsufficientCreditsException("Not enough credits for {$reason}.");
        }
        try {
            return $action();
        } catch (\Throwable $e) {
            $this->credit($session, $cost, 'refund:'.$reason);
            throw $e;
        }
    }

    /**
     * Backfill this session's ledger rows onto the owning account (called when a
     * guest session is claimed). Idempotent — only null-user rows are moved, so a
     * repeat is a no-op. No-op until the schema carries user_id, so a listener/
     * caller can ship ahead of the column migration (SPEC §5.2/§5.4).
     */
    public function transferSessionToUser(StudioSession $session, int $userId): void
    {
        if (! Schema::hasColumn('studio_credit_ledger_entries', 'user_id')) {
            return;
        }
        StudioCreditLedger::where('studio_session_id', $session->id)
            ->whereNull('user_id')
            ->update(['user_id' => $userId]);
    }

    /**
     * Ledger query scoped to this session's account: owner → all rows for the
     * user; guest → this session's own null-user rows (the pre-ownership scope).
     *
     * @return Builder<StudioCreditLedger>
     */
    private function scopeFor(StudioSession $session): Builder
    {
        if ($session->user_id !== null) {
            return StudioCreditLedger::query()->where('user_id', $session->user_id);
        }

        return StudioCreditLedger::query()
            ->where('studio_session_id', $session->id)
            ->whereNull('user_id');
    }
}
