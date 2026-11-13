<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Models\PosOfflineCardPayment;
use Juwri\Modules\Pos\Services\Offline\OfflineCardSettlement;

/** Ask the card provider about every offline card payment still pending (captured → paid; declined → manager exception). */
class PosOfflineCardsSync extends Command
{
    protected $signature = 'pos:offline-cards-sync';

    protected $description = 'Settle or flag offline card payments from the provider status';

    public function handle(OfflineCardSettlement $settlement): int
    {
        $stores = PosOfflineCardPayment::query()->withoutGlobalScopes()->where('state', 'pending_offline')->distinct()->pluck('store_id');
        $sum = ['captured' => 0, 'declined' => 0, 'pending' => 0];
        foreach (Store::query()->whereIn('id', $stores)->get() as $store) {
            foreach ($settlement->syncStore($store) as $k => $n) {
                $sum[$k] += $n;
            }
        }
        $this->info("captured {$sum['captured']}, declined {$sum['declined']}, still pending {$sum['pending']}");

        return self::SUCCESS;
    }
}
