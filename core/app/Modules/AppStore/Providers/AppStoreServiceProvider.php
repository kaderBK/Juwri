<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\AppStore\Console\Commands\AppDunning;
use Juwri\Modules\AppStore\Console\Commands\AppTrialsTick;
use Juwri\Modules\AppStore\Console\Commands\HostedAppStoreDownload;
use Juwri\Modules\AppStore\Database\Seeders\AppStoreCatalogSeeder;
use Juwri\Modules\AppStore\Database\Seeders\AppStoreSeeder;
use Juwri\Modules\AppStore\Database\Seeders\AppStoreTranslationsSeeder;
use Juwri\Modules\AppStore\Mail\AppTrialReminderMail;

/**
 * AppStore module provider (P1-08f).
 */
class AppStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('AppStore', new AppStoreStoreData);
        ModuleSeeders::register('AppStore', optional: [AppStoreCatalogSeeder::class, AppStoreSeeder::class, AppStoreTranslationsSeeder::class]);
        ModuleRoutes::register('AppStore', __DIR__.'/../Routes');
        TenantJobs::classify(platform: [AppTrialReminderMail::class]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (it lists some moved commands by name).
            $this->commands([
                AppDunning::class,
                AppTrialsTick::class,
                HostedAppStoreDownload::class,
            ]);
        }
    }
}
