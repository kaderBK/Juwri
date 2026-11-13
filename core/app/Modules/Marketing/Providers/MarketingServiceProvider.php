<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Providers;

use App\Mails\CampaignMail;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Marketing\Console\Commands\CartRecovery;
use Juwri\Modules\Marketing\Console\Commands\CrmAutoTag;
use Juwri\Modules\Marketing\Console\Commands\WhatsAppCartRecovery;
use Juwri\Modules\Marketing\Console\SweepStuckCampaigns;
use Juwri\Modules\Marketing\Jobs\SendCampaignJob;
use Juwri\Modules\Marketing\Jobs\SendSingleCampaignMessageJob;

/**
 * Marketing module provider (P1-08f).
 */
class MarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Marketing', new MarketingStoreData);
        TenantJobs::classify(tenant: [SendCampaignJob::class, SendSingleCampaignMessageJob::class, CampaignMail::class]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (it lists some moved commands by name).
            $this->commands([
                CartRecovery::class,
                CrmAutoTag::class,
                WhatsAppCartRecovery::class, // P1-13 13c: from Channels (legacy signature, delegates to CartRecovery)
                SweepStuckCampaigns::class,
            ]);
        }
    }
}
