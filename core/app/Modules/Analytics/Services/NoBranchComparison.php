<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Juwri\Modules\Analytics\Contracts\BranchComparison;

/** P1-13 13h Community default: no cross-branch comparison — the branch report shows one branch at a time. */
final class NoBranchComparison implements BranchComparison
{
    public function enabled(): bool
    {
        return false;
    }

    public function rows(int $storeId, Collection $branches, Carbon $since): Collection
    {
        return collect();
    }
}
