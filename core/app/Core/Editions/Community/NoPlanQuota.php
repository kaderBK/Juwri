<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Billing\PlanQuota;
use Juwri\Core\Models\User;

/** P1-13 Community default: no plan quotas, so no soft-limit notices. */
final class NoPlanQuota implements PlanQuota
{
    public function notifyOrderSoftOnce(User $owner): bool
    {
        return false;
    }

    public function orderSoftStatus(User $owner): ?array
    {
        return null;
    }
}
