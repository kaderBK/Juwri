<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Reservations\Console\Commands\RwgFeeds;
use Juwri\Modules\Reservations\Jobs\PushRwgBookingUpdateJob;
use Juwri\Modules\Reservations\Jobs\PushRwgWaitlistRtuJob;
use Juwri\Core\Support\ModuleRoutes;

/**
 * Reservations module provider (P1-08d).
 */
class ReservationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Reservations', new ReservationsStoreData);
        ModuleRoutes::register('Reservations', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [PushRwgBookingUpdateJob::class, PushRwgWaitlistRtuJob::class]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([RwgFeeds::class]);
        }
    }
}
