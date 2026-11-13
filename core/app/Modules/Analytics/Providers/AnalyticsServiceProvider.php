<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Analytics\Console\Commands\ComputeForecasts;
use Juwri\Modules\Analytics\Mail\DailyAnomalyAlertMail;
use Juwri\Modules\Analytics\Mail\WeeklyDigestMail;
use Juwri\Modules\Analytics\Contracts\BranchComparison;
use Juwri\Modules\Analytics\Services\NoBranchComparison;

/**
 * Analytics module provider (P1-08f).
 *
 * P1-13 13h: standard and per-branch reports are Community; cross-branch comparison and the cross-business peer
 * benchmarks are ee (the Enterprise package's Analytics half), which binds its own {@see BranchComparison}.
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Analytics', new AnalyticsStoreData);
        TenantJobs::classify(tenant: [DailyAnomalyAlertMail::class, WeeklyDigestMail::class]);
        $this->app->singletonIf(BranchComparison::class, NoBranchComparison::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (it lists some moved commands by name).
            $this->commands([
                ComputeForecasts::class,
            ]);
        }
    }
}
