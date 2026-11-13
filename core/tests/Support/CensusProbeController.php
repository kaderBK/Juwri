<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Juwri\Core\Models\Store;

/**
 * WI-R2: a deliberately scope-free, model-bound controller used ONLY by the
 * anti-vacuity probe in RouteTenantScopeCensusTest. Never wired into routes/;
 * the test registers a throwaway route to it at runtime.
 */
class CensusProbeController
{
    public function update(Store $store)
    {
        return response()->noContent();
    }
}
