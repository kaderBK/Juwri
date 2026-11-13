<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Modules\Agents\Console\Commands\PurgeVoiceRetention;
use Juwri\Modules\Agents\Console\VoiceCostAnomalyCheck;
use Juwri\Modules\Agents\Console\VoiceErrorBudgetCheck;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Agents\Contracts\AgentPolicyStore;
use Juwri\Modules\Agents\Services\Agents\StoreAgentPolicies;
use Juwri\Modules\Agents\Services\Telephony\Bridge\FakeVoiceBridge;
use Juwri\Modules\Agents\Services\Telephony\Bridge\VoiceBridge;
use Juwri\Modules\Agents\Tools\AgentToolRegistry;

/**
 * Agents module provider (P1-09): registers the module's route files and (P1-16) the shared agent tool registry.
 * P1-13 13h: the agent rules go through {@see AgentPolicyStore} — per-store (Community) unless ee binds org-wide ones.
 */
class AgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Agents', new AgentsStoreData);
        ModuleRoutes::register('Agents', __DIR__.'/../Routes');
        $this->app->singleton(AgentToolRegistry::class, fn () => AgentToolRegistry::withDefaults());
        $this->app->singletonIf(AgentPolicyStore::class, StoreAgentPolicies::class);
        // P6-09 V-03: real Mode A/B bridges replace this binding (V-04/V-05).
        $this->app->singletonIf(VoiceBridge::class, FakeVoiceBridge::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeVoiceRetention::class, VoiceCostAnomalyCheck::class, VoiceErrorBudgetCheck::class]);
        }
    }
}
