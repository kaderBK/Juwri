<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Juwri\Core\Editions\Community\NoPlanQuota;
use Juwri\Core\Models\User;

/**
 * P1-08 public surface (moved to Core in P1-13): plan quota notices for modules above Billing (Ordering, the
 * owner dashboard). Billing (ee) binds its plan quota service; Community has no plan quotas
 * ({@see NoPlanQuota}).
 */
interface PlanQuota
{
    /** Send the "approaching the order soft limit" notice once per period; true when it was sent now. */
    public function notifyOrderSoftOnce(User $owner): bool;

    /**
     * The owner's monthly order soft allowance, or null when the plan sets none.
     *
     * @return array{used: int, limit: int}|null
     */
    public function orderSoftStatus(User $owner): ?array;
}
