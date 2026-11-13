<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Billing\EntitlementSource;
use Juwri\Core\Models\User;

/**
 * P1-13 Community default: nothing is sold, so every feature key is included and no count/value key is set
 * (null = unlimited, or the owning module's config default). Kill switches and bans still veto in Entitlements.
 */
final class CommunityEntitlementSource implements EntitlementSource
{
    /** Keys that carry a number (limits, quotas, sizes), never a boolean grant. */
    private const VALUE_KEY = '/(_limit|_mb|_calls|_soft|_per_month|_monthly)$/';

    public function trace(User $user, string $key): ?array
    {
        if (preg_match(self::VALUE_KEY, $key) === 1) {
            return null;
        }

        return ['lane' => 'edition', 'value' => '1'];
    }

    public function addonTotal(int $userId, string $key): int
    {
        return 0;
    }

    public function booleanAddonKeys(User $user): array
    {
        return [];
    }

    public function bundleGrants(User $user): array
    {
        return [];
    }

    public function purchases(User $user, string $key): array
    {
        return [];
    }

    public function flush(): void {}
}
