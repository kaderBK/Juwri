<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Juwri\Core\Contracts\Editions\StoreLimitPolicy;
use Juwri\Core\Editions\Limits\StoreLimits;

/** Test-suite binding: no store/branch limits (fixtures create what a test needs; see CreatesApplication). */
final class UnlimitedStoreLimits implements StoreLimitPolicy
{
    public function limits(): StoreLimits
    {
        return new StoreLimits('testing', null, null, null);
    }
}
