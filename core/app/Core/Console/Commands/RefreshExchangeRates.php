<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Juwri\Core\Models\ExchangeRate;
use Juwri\Core\Services\ExchangeRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MC-30 — fetch the daily FX snapshot (Frankfurter, base USD) for display-only
 * conversion. On any failure it keeps the last-known rates (logs a warning) so a
 * feed outage degrades to stale, never to a wiped table. Upserts one row per
 * quote and prunes codes the feed stopped returning after 90 days.
 */
class RefreshExchangeRates extends Command
{
    protected $signature = 'currency:refresh-rates';

    protected $description = 'Fetch daily FX rates (Frankfurter, base USD) for display-only conversion';

    public function handle(): int
    {
        try {
            $resp = Http::timeout(15)->retry(2, 500)
                ->get('https://api.frankfurter.dev/v1/latest', ['base' => ExchangeRateService::BASE]);

            if (! $resp->ok()) {
                Log::warning('[fx] refresh failed HTTP '.$resp->status().' — keeping last-known rates');

                return self::FAILURE;
            }

            $rates = (array) $resp->json('rates', []);
        } catch (\Throwable $e) {
            Log::warning('[fx] refresh threw: '.$e->getMessage().' — keeping last-known rates');

            return self::FAILURE;
        }

        if (empty($rates)) {
            Log::warning('[fx] refresh returned no rates — keeping last-known');

            return self::FAILURE;
        }

        $now = now();
        $updated = 0;
        foreach ($rates as $quote => $rate) {
            if (! is_numeric($rate) || $rate <= 0) {
                continue;
            }
            ExchangeRate::updateOrCreate(
                ['base' => ExchangeRateService::BASE, 'quote' => strtoupper((string) $quote), 'provider' => ExchangeRateService::PROVIDER],
                ['rate' => $rate, 'fetched_at' => $now]
            );
            $updated++;
        }

        // Prune quote codes the feed no longer returns (untouched for 90 days).
        $pruned = ExchangeRate::where('provider', ExchangeRateService::PROVIDER)
            ->where('fetched_at', '<', $now->copy()->subDays(90))->delete();

        ExchangeRateService::flush();

        $this->info("[fx] updated {$updated} rates".($pruned ? ", pruned {$pruned} stale" : '').'.');

        return self::SUCCESS;
    }
}
