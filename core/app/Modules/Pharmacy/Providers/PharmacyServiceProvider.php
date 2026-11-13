<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Juwri\Modules\Pharmacy\Console\Commands\PharmacySweepExpired;
use Juwri\Modules\Pharmacy\Console\PharmacySchedule;
use Juwri\Modules\Pharmacy\Database\Seeders\PharmacyDemoSeeder;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets;

/**
 * Pharmacy module provider (P1-08e).
 */
class PharmacyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        PharmacySchedule::register(); // P1-13: its console schedule entries (were in app/Console/Kernel)
        ModuleSeeders::register('Pharmacy', optional: [PharmacyDemoSeeder::class]);
        ModuleRoutes::register('Pharmacy', __DIR__.'/../Routes');
        StoreDataRegistry::register('Pharmacy', new PharmacyStoreData);
        // P1-23 (AUP 3.1): the pharmacy rules every customer-facing sales surface asks (Ordering's extension point).
        // One instance for both contracts (and direct use), so the per-store memo is shared.
        $this->app->singleton(PharmacyMarkets::class);
        $this->app->singleton(SalesRestrictions::class, fn ($app) => $app->make(PharmacyMarkets::class));
        // P1-23 follow-up: the per-market medicine promotion block (Menu's extension point).
        $this->app->singleton(PromotionRules::class, fn ($app) => $app->make(PharmacyMarkets::class));
    }

    public function boot(): void
    {
        // P1-23 (AUP 3.1): unpublished pharmacies stay off public listings (marketplace search, home rails, …).
        Hooks::on(Store::PUBLIC_LISTING_HOOK, 'pharmacy', fn (Builder $query) => $this->app->make(PharmacyMarkets::class)->restrictPublicListing($query));
        // A licence upload or a store-type change moves a store's market rules: forget the per-store memo.
        $flush = fn () => $this->app->make(PharmacyMarkets::class)->flush();
        PharmacyLicence::saved($flush);
        PharmacyLicence::deleted($flush);
        Store::saved($flush);
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([PharmacySweepExpired::class]);
        }
    }
}
