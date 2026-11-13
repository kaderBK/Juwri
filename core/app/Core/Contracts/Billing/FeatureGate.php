<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

/**
 * P1-08 (plan-08c "FeatureGate"): may this store use a plan feature? For L1 modules that gate a feature themselves.
 * Billing binds the implementation (today App\Services\FeatureAccess::storeAllows — same answers).
 */
interface FeatureGate
{
    public function storeAllows(?int $storeId, string $feature): bool;
}
