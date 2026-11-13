<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Laundry\Services\Laundry\LaundryHeldSlots;
use Juwri\Modules\Ordering\Contracts\HeldSlotCounts;

/**
 * Laundry module provider (P1-09): registers the module's route files.
 */
class LaundryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ModuleRoutes::register('Laundry', __DIR__.'/../Routes');
        StoreDataRegistry::register('Laundry', new LaundryStoreData);
        // P3-11: live holds take capacity in the shared scheduled-order slot counts (Ordering's default holds nothing)
        $this->app->bind(HeldSlotCounts::class, LaundryHeldSlots::class);
    }

    public function boot(): void
    {
    }
}
