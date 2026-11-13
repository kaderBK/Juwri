<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Ai\Contracts\AiSpend;
use Juwri\Modules\Ai\Contracts\ImageGenerator;
use Juwri\Modules\Ai\Contracts\Translator;
use Juwri\Modules\Ai\Jobs\LangChromeTranslateJob;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Ai\Services\AiSpendApi;
use Juwri\Modules\Ai\Services\Studio\GeminiImageProvider;
use Juwri\Modules\Ai\Services\TranslatorService;
use Juwri\Core\Support\ModuleRoutes;

/**
 * Ai module provider (P1-08b).
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Ai', new AiStoreData);
        ModuleRoutes::register('Ai', __DIR__.'/../Routes');
        TenantJobs::classify(platform: [
            LangChromeTranslateJob::class, // platform UI strings
        ]);

        // P1-08 L1 public surface: contracts resolve to the existing classes (instance overrides of those classes,
        // e.g. test fakes, still apply).
        $this->app->bind(Ai::class, AiService::class);
        $this->app->bind(AiProviderChain::class, AiProviderFactory::class);
        $this->app->bind(Translator::class, TranslatorService::class);
        $this->app->bind(ImageGenerator::class, GeminiImageProvider::class);
        $this->app->bind(AiSpend::class, AiSpendApi::class);
    }
}
