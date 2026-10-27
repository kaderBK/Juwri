<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Concerns;

use Juwri\Core\Models\Branch;

/**
 * Single source of truth for the multi-branch scoping rule (was copy-pasted across
 * FloorMapController::tableQuery, StaffAppController::scopeToBranch, and
 * FloorMapController::turnTimes). Legacy rows with a NULL branch_id belong to the
 * restaurant's DEFAULT (first) branch, so pre-multi-branch data keeps showing there;
 * every other branch sees only its own rows. Keeping this in one place prevents the
 * three copies from drifting into a cross-branch data leak.
 */
trait ScopesToBranch
{
    /**
     * Apply a branch filter to a query on any table that has a branch_id column.
     * $branchId === null means "whole restaurant" (no branch filter).
     */
    protected function scopeToBranch($query, int $storeId, ?int $branchId)
    {
        if ($branchId === null) {
            return $query;
        }
        if ((int) $branchId === (int) $this->defaultBranchId($storeId)) {
            return $query->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id'));
        }

        return $query->where('branch_id', $branchId);
    }

    /** The restaurant's default (first-created) branch id. */
    protected function defaultBranchId(int $storeId): ?int
    {
        return Branch::where('store_id', $storeId)->orderBy('id')->value('id');
    }
}
