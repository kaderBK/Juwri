<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use App\Services\Entitlements;
use App\Services\FeatureAccess;
use Juwri\Core\Contracts\Billing\EntitlementSource;
use Juwri\Core\Contracts\Billing\FeatureGate;
use Juwri\Core\Contracts\Billing\PlanLimits;
use Juwri\Core\Models\User;

/** P1-08: Core's {@see PlanLimits} and {@see FeatureGate} over the entitlement resolver (moved from Billing to Core in P1-13: the resolver is Community; what was bought comes from {@see EntitlementSource}). Pure delegation. */
final class EntitlementGates implements FeatureGate, PlanLimits
{
    public function value(User $user, string $key): mixed
    {
        return Entitlements::planValue($user, $key);
    }

    public function storeAllows(?int $storeId, string $feature): bool
    {
        return FeatureAccess::storeAllows($storeId, $feature);
    }
}
