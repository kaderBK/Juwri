<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Billing\OutstandingPlatformCharges;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\BranchMove;
use Juwri\Core\Services\Tenancy\StoreDataEngine;

/**
 * WI-G7: moves ownership of a store between users, or a branch between stores.
 *
 * P1-21 (J29-8): a store transfer is driven by the store-data registry, in ONE transaction: the store changes owner
 * (its `updated` hook re-keys account_id on every Move/Reset table of the store — {@see StoreDataEngine::rekeyTables()}),
 * then {@see StoreDataEngine::transfer()} re-points the store's user columns (staff owner, drivers, backups, imports)
 * and runs every Reset table's handler: the previous owner's gateway credentials, integration tokens, push devices,
 * messaging credentials, Paylink onboarding and domain verification are reset, never handed over. Store rows the
 * registry keeps with the previous account (cost/usage ledgers) and account-level rows (plan, wallet, API tokens,
 * webhooks, platform payments) stay — they may cover the user's other stores.
 *
 * Every apply writes an audit row and is expected to run behind a two-party consent flow (or a superadmin force).
 * Plan store-capacity is enforced at accept time.
 */
class OwnershipTransferService
{
    /** Build the human-facing manifest of what moves and what stays. */
    public function storeManifest(Store $store): array
    {
        return [
            'moves' => [
                'store' => $store->title ?? $store->name,
                'included' => 'Menu, branches, tables, media, settings, staff assignments, and this store\'s self-service backups.',
            ],
            'stays' => [
                'note' => 'Your plan/subscription, wallet, API tokens, webhooks, and past transactions stay with YOUR account — they may cover your other stores.',
            ],
            'warnings' => [
                'API tokens and webhook endpoints scoped to this store will keep pointing at your account until you rotate them.',
                'Payment gateways, integrations (delivery, POS, accounting, Google), messaging accounts, store API tokens and the Paylink onboarding are disconnected; the new owner connects their own.',
                'Your phones and installed dashboards stop receiving this store\'s notifications.',
                'Domains attached to the store move with it, but the new owner must verify them again.',
            ],
        ];
    }

    /**
     * Whether $recipient has room for one more store under their plan (WI-G7).
     * 999 is the unlimited sentinel.
     */
    public function recipientHasCapacity(User $recipient): bool
    {
        $limit = effective_limit('restaurant', $recipient);
        if ($limit >= 999) {
            return true;
        }
        $count = Store::where('user_id', $recipient->id)->count();

        return $count < $limit;
    }

    /**
     * E2 cooldown: is this store still inside its transfer cooldown window? True
     * when a store transfer for it was accepted/forced within
     * `transfer_cooldown_days` (per-store option, default 14; 0 disables the
     * cooldown entirely). Owner-offer path only — the superadmin forced path
     * (Admin\StoreController::forceTransfer → applyStore) never consults this.
     */
    public function cooldownActive(Store $store): bool
    {
        $days = (int) store_options($store->id, 'transfer_cooldown_days', '14');
        if ($days <= 0) {
            return false;
        }

        return StoreTransfer::where('kind', 'store')
            ->where('store_id', $store->id)
            ->whereIn('status', ['accepted', 'forced'])
            ->where('responded_at', '>=', now()->subDays($days))
            ->exists();
    }

    /**
     * E2 billing guard: does the initiator have an unsettled platform charge?
     * A platform Transaction still in the pending (unpaid) state on their own
     * account — you should not be able to hand a store away while you owe the
     * platform money. success/consumed charges are settled and never block.
     */
    public function initiatorHasUnsettledBilling(int $userId): bool
    {
        return app(OutstandingPlatformCharges::class)->hasUnsettled($userId);
    }

    /**
     * Apply a store transfer. Re-points the Post + user-keyed satellites to
     * $transfer->to_user_id inside a transaction, writes an audit row, and marks
     * the transfer accepted (or forced). Ownership is re-checked here.
     */
    public function applyStore(StoreTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $store = Store::where('id', $transfer->store_id)
                ->where('user_id', $transfer->from_user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $toId = (int) $transfer->to_user_id;

            $fromAccount = (int) $store->account_id;

            // The store itself: its account follows the new owner and the Store `updated` hook re-keys the store's
            // registered rows (P1-21).
            $store->update(['user_id' => $toId]);

            // User columns of THIS store re-pointed; the previous owner's connections reset (registry handlers).
            $reset = app(StoreDataEngine::class)->transfer($store->getConnection(), new StoreTransferContext(
                storeId: (int) $store->id,
                fromUserId: (int) $transfer->from_user_id,
                toUserId: $toId,
                fromAccountId: $fromAccount,
                toAccountId: (int) $store->account_id,
            ));

            $transfer->update([
                'status' => $transfer->by_superadmin ? 'forced' : 'accepted',
                'responded_at' => now(),
            ]);

            $this->audit($transfer->from_user_id, $store->id, 'store.transfer', $transfer->getMorphClass(), $transfer->id, [
                'from' => $transfer->from_user_id, 'to' => $toId, 'store' => $store->id,
                'by_superadmin' => $transfer->by_superadmin,
                // E12: before/after owner.
                'old' => ['user_id' => $transfer->from_user_id],
                'new' => ['user_id' => $toId],
                'reset' => $reset,
            ]);
        });
    }

    /**
     * Move a branch to another store (P6-341). Driven by {@see BranchMove}: every branch_id table of the TenantTables
     * registry is re-keyed to the target store/account, dropped, left as archive, or blocks the move (history such as
     * orders and fiscal records, cross-store links, stock on hand, an open table session, the source's last branch,
     * the target's branch cap, or an unclassified table). Returns the blocker code, or null when the branch moved.
     */
    public function applyBranch(StoreTransfer $transfer): ?string
    {
        return DB::transaction(function () use ($transfer): ?string {
            $branch = Branch::where('id', $transfer->branch_id)
                ->where('store_id', $transfer->store_id)
                ->lockForUpdate()
                ->firstOrFail();
            $target = Store::findOrFail($transfer->target_store_id);

            $result = BranchMove::move($branch, $target);
            if ($result['blocked'] !== null) {
                $transfer->update(['status' => 'declined', 'responded_at' => now()]);

                return $result['blocked'];
            }

            $transfer->update([
                'status' => $transfer->by_superadmin ? 'forced' : 'accepted',
                'responded_at' => now(),
            ]);

            $this->audit($transfer->from_user_id, $target->id, 'branch.transfer', $transfer->getMorphClass(), $transfer->id, [
                'branch' => $branch->id, 'from_store' => $transfer->store_id, 'to_store' => $target->id,
                'by_superadmin' => $transfer->by_superadmin,
                'rekeyed' => $result['rekeyed'], 'dropped' => $result['dropped'],
            ]);

            return null;
        });
    }

    private function audit(int $actorId, int $storeId, string $action, string $subjectType, int $subjectId, array $meta): void
    {
        try {
            // P1-21: a savepoint — on PostgreSQL a failed INSERT aborts the whole transaction, so a best-effort audit
            // that failed without one silently rolled the transfer back at commit.
            DB::transaction(fn () => ActivityLog::create([
                'actor_user_id' => $actorId,
                'store_id' => $storeId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'meta' => $meta,
                'ip' => request()->ip(),
            ]));
        } catch (\Throwable $e) {
            // audit is best-effort; never fail the transfer because logging failed
        }
    }
}
