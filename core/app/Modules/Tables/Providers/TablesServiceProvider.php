<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Tables\Contracts\QrImages;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;

/**
 * Tables module provider (P1-09): registers the module's route files.
 */
class TablesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Tables', new TablesStoreData);
        ModuleRoutes::register('Tables', __DIR__.'/../Routes');
        // P6-172: the order receipt PDF (Ordering) draws its QR through this contract.
        $this->app->bind(QrImages::class, QrRenderService::class);
    }
}
