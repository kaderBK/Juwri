<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Juwri\Core\Models\User;

/**
 * P1-08 (plan-08c "PlanLimits"): a plan/entitlement value of an owner (e.g. `sms_monthly_limit`, `storage_mb`,
 * `ai_monthly_calls`), for L1 modules that enforce their own quota. Billing owns entitlements and binds the
 * implementation (today App\Services\Entitlements::planValue — same values, same sentinels).
 */
interface PlanLimits
{
    /** The raw plan value for the key (null = the plan does not set it). */
    public function value(User $user, string $key): mixed;
}
