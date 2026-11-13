<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Loyalty\Console\Commands\DeliverScheduledGiftCards;
use Juwri\Modules\Loyalty\Console\Commands\LoyaltyExpirePoints;
use Juwri\Modules\Loyalty\Console\Commands\WinBackOffers;
use Juwri\Modules\Loyalty\Contracts\GoogleWalletPassIds;
use Juwri\Modules\Loyalty\Jobs\SyncGoogleWalletPass;
use Juwri\Modules\Loyalty\Mail\GiftCardMail;
use Juwri\Modules\Loyalty\Services\GoogleWallet\DefaultGoogleWalletPassIds;

/**
 * Loyalty module provider (P1-08e).
 */
class LoyaltyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Loyalty', new LoyaltyStoreData);
        ModuleRoutes::register('Loyalty', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [SyncGoogleWalletPass::class, GiftCardMail::class]);
        $this->app->bind(GoogleWalletPassIds::class, DefaultGoogleWalletPassIds::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (it lists ExpireGiftCards and SendBirthdayOffers by name).
            $this->commands([DeliverScheduledGiftCards::class, LoyaltyExpirePoints::class, WinBackOffers::class]);
        }
    }
}
