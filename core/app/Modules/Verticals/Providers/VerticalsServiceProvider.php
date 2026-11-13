<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Verticals\Contracts\GoodsCartQuote;
use Juwri\Modules\Verticals\Contracts\VehicleFitments;
use Juwri\Modules\Verticals\Jobs\Fitment\ImportFitmentsJob;
use Juwri\Modules\Verticals\Console\Commands\PruneSkinProfiles;
use Juwri\Modules\Verticals\Console\VerticalsSchedule;
use Juwri\Modules\Verticals\Services\Fitment\FitmentLookup;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsQuoteService;

/**
 * Verticals module provider (P1-09): registers the module's route files.
 */
class VerticalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        VerticalsSchedule::register(); // P6-71: nightly skin-profile prune
        StoreDataRegistry::register('Verticals', new VerticalsStoreData);
        ModuleRoutes::register('Verticals', __DIR__.'/../Routes');
        // The kiosk's idle return (Pos) clears the goods cart of the device's session without importing this module.
        // P3-13: the goods cart quote the premium goods themes show (Storefront reads it through the contract).
        $this->app->bind(GoodsCartQuote::class, GoodsQuoteService::class);
        // P3-08b: the vehicle a shopper picked and what fits it (Storefront reads it through the contract).
        $this->app->bind(VehicleFitments::class, FitmentLookup::class);
        TenantJobs::classify(tenant: [ImportFitmentsJob::class]);
        Hooks::on('kiosk.session-reset', 'verticals', fn () => app(GoodsCartService::class)->forgetToken());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneSkinProfiles::class]);
        }
    }
}
