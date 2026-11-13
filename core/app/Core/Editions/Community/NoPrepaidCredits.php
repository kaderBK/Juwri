<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Billing\PrepaidCredits;

/** Credit packs: Community sells no prepaid credits, so nothing is ever drawn (usage stops at the plan allowance). */
final class NoPrepaidCredits implements PrepaidCredits
{
    public function kindFor(string $channel, ?string $category = null, ?string $country = null): ?string
    {
        return null;
    }

    public function draw(int $userId, string $kind, int $units, string $usageKey, ?string $feature = null): bool
    {
        return false;
    }

    public function release(int $userId, string $usageKey): void {}
}
