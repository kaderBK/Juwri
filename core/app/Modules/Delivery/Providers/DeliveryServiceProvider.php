<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;

/**
 * Delivery module provider (P1-09): registers the module's route files.
 */
class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Delivery', new DeliveryStoreData);
        ModuleRoutes::register('Delivery', __DIR__.'/../Routes');
    }
}
