<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Branch;
use Juwri\Modules\Analytics\Services\NoBranchComparison;

/**
 * P1-13 13h (owner decision 2026-10-03, BranchReportController SPLIT): the branch report shows one branch at a time
 * in Community ({@see NoBranchComparison}); comparing every branch of a store side by side is ee (cross-branch
 * analytics), which binds its own implementation.
 */
interface BranchComparison
{
    /** Does the report compare all branches of a store side by side? */
    public function enabled(): bool;

    /**
     * One report row per branch, in the given order (only called when {@see enabled()}).
     *
     * @param  Collection<int, Branch>  $branches
     * @return Collection<int, object>
     */
    public function rows(int $storeId, Collection $branches, Carbon $since): Collection;
}
