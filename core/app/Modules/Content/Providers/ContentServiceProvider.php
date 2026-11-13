<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Content\Console\Commands\BlogAutopilotRun;
use Juwri\Modules\Content\Console\Commands\BuildReferenceDocs;
use Juwri\Modules\Content\Console\Commands\DocsAnalytics;
use Juwri\Modules\Content\Console\Commands\DocsStaleness;
use Juwri\Modules\Content\Console\Commands\LockedTemplatesImport;
use Juwri\Modules\Content\Console\Commands\LockedTemplatesRenderLibrary;
use Juwri\Modules\Content\Console\Commands\PruneStudioSessions;
use Juwri\Modules\Content\Console\Commands\SeedHomeFromLegacy;
use Juwri\Modules\Content\Console\Commands\StudioTemplateThumbs;
use Juwri\Modules\Content\Jobs\GenerateBlogPostJob;
use Juwri\Modules\Content\Jobs\PingIndexNow;
use Juwri\Modules\Content\Jobs\TranslateBlogPostJob;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Content\Database\Seeders\DocsScreenshotSeeder;
use Juwri\Modules\Content\Database\Seeders\FaqTranslationsSeeder;
use Juwri\Modules\Content\Database\Seeders\SeoAutopilotTopicSeeder;
use Juwri\Modules\Content\Database\Seeders\SeoMetaSeeder;

/**
 * Content module provider (P1-08f).
 */
class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Content', new ContentStoreData);
        ModuleSeeders::register('Content', demo: [DocsScreenshotSeeder::class], optional: [FaqTranslationsSeeder::class, SeoAutopilotTopicSeeder::class, SeoMetaSeeder::class]);
        ModuleRoutes::register('Content', __DIR__.'/../Routes');
        TenantJobs::classify(platform: [GenerateBlogPostJob::class, PingIndexNow::class, TranslateBlogPostJob::class]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (it lists some moved commands by name).
            $this->commands([
                BlogAutopilotRun::class,
                BuildReferenceDocs::class,
                DocsAnalytics::class,
                DocsStaleness::class,
                LockedTemplatesImport::class,
                LockedTemplatesRenderLibrary::class,
                PruneStudioSessions::class,
                SeedHomeFromLegacy::class,
                StudioTemplateThumbs::class,
            ]);
        }
    }
}
