<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use App\Services\Entitlements;
use App\Services\FeatureAccess;
use Juwri\Core\Editions\Community\CommunityEntitlementSource;
use Juwri\Core\Models\User;

/**
 * P1-13 extension point: what an owner BOUGHT (plan chain, add-ons, bundles) for {@see Entitlements}
 * and {@see FeatureAccess}. The registry lanes that stay in Core (kill switches, ban, entitlement
 * overrides) are not part of it. Billing (ee) binds plans/add-ons; Community grants every feature with no limits
 * ({@see CommunityEntitlementSource}).
 */
interface EntitlementSource
{
    /**
     * One entitlement value through the plan chain, with the lane that answered (e.g. plan_snapshot,
     * plan_entitlement, plan_legacy), or null when no lane holds the key (null limit = unlimited).
     *
     * @return array{lane: string, value: mixed}|null
     */
    public function trace(User $user, string $key): ?array;

    /** Extra capacity for a count key from active add-on packs. */
    public function addonTotal(int $userId, string $key): int;

    /**
     * Keys of the owner's active boolean add-ons; null = the read failed (callers fail closed).
     *
     * @return list<string>|null
     */
    public function booleanAddonKeys(User $user): ?array;

    /**
     * Keys granted by the owner's active bundles.
     *
     * @return list<string>
     */
    public function bundleGrants(User $user): array;

    /**
     * The purchases (boolean add-ons, bundles) that hold one key, for "why do I have this".
     *
     * @return list<array{source: string, kind: string, label: string, detail: ?string, ref: ?string, expires_at: ?string}>
     */
    public function purchases(User $user, string $key): array;

    /** Drop per-request memos. */
    public function flush(): void;
}
