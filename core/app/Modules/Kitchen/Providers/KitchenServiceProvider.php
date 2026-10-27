<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Modules\Kitchen\Console\Commands\ChecklistsGenerate;
use Juwri\Core\Support\ModuleRoutes;

/**
 * Kitchen module provider (P1-08e).
 */
class KitchenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Kitchen', new KitchenStoreData);
        ModuleRoutes::register('Kitchen', __DIR__.'/../Routes');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([ChecklistsGenerate::class]);
        }
    }
}
