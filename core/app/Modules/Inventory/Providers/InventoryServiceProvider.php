<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Modules\Inventory\Console\Commands\LowStockDigest;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Inventory\Mail\LowStockAlertMail;

/**
 * Inventory module provider (P1-08e).
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Inventory', new InventoryStoreData);
        ModuleRoutes::register('Inventory', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [LowStockAlertMail::class]); // e-mails one store's owner
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([LowStockDigest::class]);
        }
    }
}
