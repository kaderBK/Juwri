<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Hr\Console\Commands\MigrateStaffDocsPrivate;
use Juwri\Modules\Hr\Console\Commands\ShiftReminders;
use Juwri\Modules\Hr\Console\Commands\StaffDocumentsExpiryAlert;

/**
 * Hr module provider (P1-08c).
 */
class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Hr', new HrStoreData);
        ModuleRoutes::register('Hr', __DIR__.'/../Routes');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([MigrateStaffDocsPrivate::class, ShiftReminders::class, StaffDocumentsExpiryAlert::class]);
        }
    }
}
