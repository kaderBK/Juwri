<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services;

use Juwri\Core\Contracts\Billing\OutstandingPlatformCharges;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P1-08b: a platform Transaction still in the pending (unpaid) state on the user's own account. success/consumed
 * charges are settled and never count.
 */
final class PendingPlatformCharges implements OutstandingPlatformCharges
{
    public function hasUnsettled(int $userId): bool
    {
        return Transaction::where('user_id', $userId)
            ->where('status', Transaction::STATUS_PENDING)
            ->exists();
    }
}
