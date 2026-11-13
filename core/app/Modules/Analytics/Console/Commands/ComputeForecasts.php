<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Console\Commands;

use App\Services\Analytics\ForecastService;
use Illuminate\Console\Command;
use Juwri\Core\Models\Store;

/**
 * GAPS-RUN-2 B5-P2: nightly demand-forecast cache per restaurant.
 */
class ComputeForecasts extends Command
{
    protected $signature = 'analytics:forecast';

    protected $description = 'Compute and cache next-7-day demand forecasts per restaurant';

    public function handle(ForecastService $service): int
    {
        $n = 0;
        Store::query()->select('id')->chunkById(100, function ($stores) use ($service, &$n) {
            foreach ($stores as $store) {
                $service->refresh($store->id);
                $n++;
            }
        });
        $this->info("Forecasts refreshed for {$n} restaurant(s).");

        return self::SUCCESS;
    }
}
