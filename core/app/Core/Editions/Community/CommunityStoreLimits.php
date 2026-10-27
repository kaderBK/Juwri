<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Editions\StoreLimitPolicy;
use Juwri\Core\Editions\Limits\StoreLimitRules;
use Juwri\Core\Editions\Limits\StoreLimits;

/**
 * Community default of {@see StoreLimitPolicy}: one store of each store type per business, each with up to three
 * branches. A product default of the Community code, not a licence restriction (the AGPL allows modification).
 */
final class CommunityStoreLimits implements StoreLimitPolicy
{
    public function limits(): StoreLimits
    {
        return StoreLimitRules::community();
    }
}
