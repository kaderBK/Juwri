<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services;

use Juwri\Modules\Messaging\Contracts\MessageChargeGate;

/** Messaging's default gate when no billing module is bound: every send allowed, nothing to charge. */
final class NullMessageChargeGate implements MessageChargeGate
{
    public function authorize(?int $userId, string $category, bool $billable, string $to): array
    {
        return ['billable' => false, 'category' => $category, 'est' => 0.0, 'sell' => 0.0, 'walletCharge' => 0.0];
    }

    public function release(array $plan, ?int $userId): void {}
}
