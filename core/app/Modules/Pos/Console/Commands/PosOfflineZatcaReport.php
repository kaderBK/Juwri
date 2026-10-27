<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Pos\Models\PosOfflineZatcaInvoice;
use Juwri\Modules\Pos\Services\Offline\OfflineZatca;

/** OP-12: report to ZATCA the simplified invoices tills signed offline (retries transport failures; a no-op while off). */
class PosOfflineZatcaReport extends Command
{
    protected $signature = 'pos:offline-zatca-report';

    protected $description = 'Report offline-signed till invoices to ZATCA';

    public function handle(OfflineZatca $zatca): int
    {
        if (! OfflineZatca::enabled()) {
            $this->info('off (pos.offline.zatca_device_signing)');

            return self::SUCCESS;
        }
        $sum = ['reported' => 0, 'rejected' => 0, 'failed' => 0];
        foreach (PosOfflineZatcaInvoice::query()->withoutGlobalScopes()->where('status', 'pending')->distinct()->pluck('store_id') as $storeId) {
            foreach ($zatca->report((int) $storeId) as $k => $n) {
                $sum[$k] += $n;
            }
        }
        $this->info("reported {$sum['reported']}, rejected {$sum['rejected']}, failed {$sum['failed']}");

        return self::SUCCESS;
    }
}
