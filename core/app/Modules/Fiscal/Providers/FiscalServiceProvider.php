<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Contracts\Stores\CountryCurrency;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;
use Juwri\Modules\Fiscal\Contracts\FiscalInvoices;
use Juwri\Modules\Fiscal\Contracts\FiscalReceipts;
use Juwri\Modules\Fiscal\Contracts\ZatcaCertificates;
use Juwri\Modules\Fiscal\Contracts\ZatcaDeviceUnits;
use Juwri\Modules\Fiscal\Contracts\ZatcaSigning;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Services\FiscalCountryCurrency;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\FiscalInvoicing;
use Juwri\Modules\Fiscal\Services\FiscalReceipt;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCertificateFacts;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDeviceUnitService;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaSigningService;

/**
 * Fiscal module provider (P1-08b). FiscalSlaWatch stays registered by name in app/Console/Kernel.
 *
 * J25-8: the adapter registry (one FiscalAdapter per country, from config('fiscal.adapters')) and the receipt
 * context builder Ordering calls. The authority clients (ZatcaClient/JofotaraClient/EtaClient: Fake unless an
 * env flag enables the live one) stay bound in AppServiceProvider until P1-09 moves provider bindings.
 */
class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Fiscal', new FiscalStoreData);
        $this->app->bind(CountryCurrency::class, FiscalCountryCurrency::class);
        ModuleRoutes::register('Fiscal', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [SubmitFiscalDocumentJob::class]);
        $this->app->bind(ZatcaSigning::class, ZatcaSigningService::class);
        $this->app->bind(ZatcaDeviceUnits::class, ZatcaDeviceUnitService::class);
        $this->app->bind(ZatcaCertificates::class, ZatcaCertificateFacts::class);

        $this->app->bind(FiscalReceipts::class, FiscalReceipt::class);
        $this->app->bind(FiscalInvoices::class, FiscalInvoicing::class);

        $this->app->singleton(FiscalDriverManager::class, function ($app) {
            $manager = new FiscalDriverManager;
            foreach ((array) config('fiscal.adapters', []) as $country => $def) {
                $class = $def['adapter'] ?? null;
                if (empty($def['enabled']) || ! is_string($class) || $class === '') {
                    continue;
                }
                $manager->register((string) $country, function () use ($app, $class, $country): FiscalAdapter {
                    $adapter = $app->make($class);
                    if (! $adapter instanceof FiscalAdapter) {
                        throw new \InvalidArgumentException("fiscal.adapters.$country: $class is not a FiscalAdapter");
                    }

                    return $adapter;
                });
            }

            return $manager;
        });
    }
}
