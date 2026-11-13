<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Broadcasting;

use App\Models\StaffMember;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\User;

/**
 * Who may listen to a store's private live channels: store.{id}.orders / .kds / .pos and, per branch,
 * store.{id}.branch.{bid}.orders / .kds / .pos (P5-19 G1).
 *
 * No new access logic: it asks the dashboard's own store-access rules.
 *  - owner: dashboard_can_access_store() (the store is one of the owner's);
 *  - staff: the store is the staff member's own (same helper through the staff session) and the member holds the
 *    channel's permission (StaffMember::hasPermission, the same call the staff.dashboard middleware and the
 *    KDS/POS controllers use: orders -> orders, kds -> kds, pos -> pos).
 *  - a staff member limited to one branch (restaurant_staff.branch_id) is refused the store-wide channels and may
 *    listen only to their own branch's channels; the owner and unrestricted staff may listen to any branch of the store.
 *  - a branch channel is refused unless the branch belongs to the channel's store (the id pair is never trusted).
 * It runs inside the TenantContext that the broadcast auth route opens (see routes/channels.php).
 */
final class StoreChannelAuthorizer
{
    public function authorize(mixed $user, int $storeId, string $suffix): bool
    {
        $permission = LiveUpdates::PERMISSIONS[$suffix] ?? null;
        if ($permission === null || $storeId <= 0) {
            return false;
        }

        $staff = $this->staff($user);
        if ($staff instanceof StaffMember) {
            // branch-limited staff use their branch channel (authorizeBranch), never the whole store's
            return (int) $staff->branch_id <= 0 && $this->staffMayListen($staff, $storeId, $permission);
        }

        return $this->isOwner($user, $storeId);
    }

    public function authorizeBranch(mixed $user, int $storeId, int $branchId, string $suffix): bool
    {
        $permission = LiveUpdates::PERMISSIONS[$suffix] ?? null;
        if ($permission === null || $storeId <= 0 || $branchId <= 0 || ! $this->branchBelongsToStore($branchId, $storeId)) {
            return false;
        }

        $staff = $this->staff($user);
        if ($staff instanceof StaffMember) {
            $limitedTo = (int) $staff->branch_id;

            return ($limitedTo <= 0 || $limitedTo === $branchId) && $this->staffMayListen($staff, $storeId, $permission);
        }

        return $this->isOwner($user, $storeId);
    }

    /** A staff member (staff session on the owner's web login, or a staff-only guard login), else null. */
    private function staff(mixed $user): ?StaffMember
    {
        $staff = staff_session() ?? ($user instanceof StaffMember ? $user : null);

        return $staff instanceof StaffMember ? $staff : null;
    }

    private function staffMayListen(StaffMember $staff, int $storeId, string $permission): bool
    {
        $ownStore = staff_session() !== null
            ? dashboard_can_access_store($storeId)
            : (int) $staff->store_id === $storeId;

        return $staff->active && $ownStore && $staff->hasPermission($permission);
    }

    /** The owner: a web login that is not a staff session. */
    private function isOwner(mixed $user, int $storeId): bool
    {
        return $user instanceof User && dashboard_can_access_store($storeId);
    }

    /** Unscoped on purpose: only the (branch, store) pair is checked here; access to the store is checked separately. */
    private function branchBelongsToStore(int $branchId, int $storeId): bool
    {
        return Branch::query()->withoutGlobalScopes()->whereKey($branchId)->where('store_id', $storeId)->exists();
    }
}
