<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Biolink\Console\Commands\BioAggregate;
use Juwri\Modules\Biolink\Console\Commands\BioMigrateLegacy;
use Juwri\Modules\Biolink\Console\Commands\BioMigrateWelcome;
use Juwri\Modules\Biolink\Console\Commands\BioRetireLegacyWelcome;
use Juwri\Modules\Biolink\Jobs\TranslateBioBlocksJob;
use Juwri\Core\Support\ModuleRoutes;

/**
 * Biolink module provider (P1-08f).
 */
class BiolinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Biolink', new BiolinkStoreData);
        ModuleRoutes::register('Biolink', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [TranslateBioBlocksJob::class]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (it lists some moved commands by name).
            $this->commands([
                BioAggregate::class,
                BioMigrateLegacy::class,
                BioMigrateWelcome::class,
                BioRetireLegacyWelcome::class,
            ]);
        }
    }
}
