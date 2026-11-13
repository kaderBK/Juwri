<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Contracts\Stores\UploadQuota;
use Juwri\Core\Models\Credential;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Media\Console\Commands\AuditMedia;
use Juwri\Modules\Media\Console\Commands\MigrateMediaToS3;
use Juwri\Modules\Media\Console\Commands\StorageBackfill;
use Juwri\Modules\Media\Contracts\Images;
use Juwri\Modules\Media\Contracts\MediaCopier;
use Juwri\Modules\Media\Contracts\StockImages;
use Juwri\Modules\Media\Contracts\StorageQuotas;
use Juwri\Modules\Media\Database\Seeders\PublicMediaSeeder;
use Juwri\Modules\Media\Services\MediaCopyService;
use Juwri\Modules\Media\Services\PublicApi;
use Juwri\Modules\Media\Services\StockImageService;
use Juwri\Modules\Media\Services\StorageService;

/**
 * Media module provider (P1-08b). ConvertToWebp stays registered by name in app/Console/Kernel.
 */
class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Media', new MediaStoreData);
        ModuleSeeders::register('Media', optional: [PublicMediaSeeder::class]);
        ModuleRoutes::register('Media', __DIR__.'/../Routes');
        // P1-08 L1 public surface: what higher modules may use.
        $this->app->singleton(PublicApi::class);
        $this->app->alias(PublicApi::class, StorageQuotas::class);
        $this->app->alias(PublicApi::class, UploadQuota::class); // P6-320: Core's view of the same counters
        $this->app->alias(PublicApi::class, Images::class);
        $this->app->bind(MediaCopier::class, MediaCopyService::class);
        $this->app->bind(StockImages::class, StockImageService::class);
    }

    public function boot(): void
    {
        // H11: StorageService::url() memoizes storage credentials; any model-event-firing Credential write drops
        // that memo. Was Credential::booted() — Core may not import Media, so Media listens to Core's model.
        Credential::saved(static fn () => StorageService::flushUrlCredentialMemo());
        Credential::deleted(static fn () => StorageService::flushUrlCredentialMemo());

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([AuditMedia::class, MigrateMediaToS3::class, StorageBackfill::class]);
        }
    }
}
