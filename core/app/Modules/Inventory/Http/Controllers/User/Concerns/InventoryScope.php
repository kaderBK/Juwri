<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Http\Controllers\User\Concerns;

use Juwri\Core\Models\Branch;

/**
 * P6-224: which stores and branches an inventory screen may touch. Stores: the dashboard actor's (a staff session is
 * bound to its own store, not every store of the owner). Branches: staff pinned to a branch work only there — any other
 * branch, or a store-wide document, is a 404; others may name any branch of the store (a foreign id is a 404).
 */
trait InventoryScope
{
    /** @return list<int> */
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids();
    }

    private function pinnedBranchId(): ?int
    {
        $staff = staff_session();

        return $staff && $staff->branch_id ? (int) $staff->branch_id : null;
    }

    /** The branch a write or a list works on: the pinned one, else the requested one (null = store-wide). */
    private function branchFor(int $storeId, mixed $requested): ?int
    {
        $branchId = is_numeric($requested) && (int) $requested > 0 ? (int) $requested : null;
        if (($pinned = $this->pinnedBranchId()) !== null) {
            abort_if($branchId !== null && $branchId !== $pinned, 404);
            $branchId = $pinned;
        }
        if ($branchId !== null) {
            abort_unless(Branch::query()->whereKey($branchId)->where('store_id', $storeId)->exists(), 404);
        }

        return $branchId;
    }

    /** A document of another branch (or a store-wide one) is invisible to branch-pinned staff. */
    private function assertBranchVisible(mixed $branchId): void
    {
        $pinned = $this->pinnedBranchId();
        abort_if($pinned !== null && (int) $branchId !== $pinned, 404);
    }
}
