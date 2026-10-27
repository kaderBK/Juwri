<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Ordering\Services\Payments\LatePayments;
use Juwri\Modules\Ordering\Services\Slots\SlotHolds;

/**
 * P3-06b (API-10, API-32): per-minute sweep — cancels headless hosted orders whose 15-minute payment window ended
 * unpaid (stock and tenders given back), and accepted late card holds whose authorisation lapsed while they waited
 * for the owner. Idempotent: every order is re-checked under its row lock.
 * P3-09: also clears expired delivery / collection slot holds and their seats (capacity is freed lazily on every read
 * too; this keeps the tables small).
 */
class ExpireHostedPayments extends Command
{
    protected $signature = 'orders:expire-hosted-payments';

    protected $description = 'Cancel headless orders whose hosted payment window ended unpaid, and lapsed late card holds';

    public function handle(HostedOrderPayments $hosted, LatePayments $late, SlotHolds $holds): int
    {
        $expired = $hosted->sweep();
        $lapsed = $late->expireAuthorisations();
        $holds->sweep();
        if ($expired + $lapsed > 0) {
            $this->info("Expired {$expired} hosted payment(s); voided {$lapsed} lapsed late hold(s).");
        }

        return self::SUCCESS;
    }
}
