<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Models\StoreOption;
use App\Support\AgentChatWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Contracts\AgentPolicyStore;
use Juwri\Modules\Agents\Services\Agents\StoreAgentPolicies;
use Tests\TestCase;

/**
 * P1-13 13h: the agents' rules go through the AgentPolicyStore extension point. The Community default answers from
 * each store's own settings exactly as the callers read them before; a bound implementation (ee org-wide governance,
 * later) is what the chat widget and the chat endpoint consult.
 */
class AgentPolicyStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_community_default_reads_the_per_store_settings(): void
    {
        $this->assertInstanceOf(StoreAgentPolicies::class, app(AgentPolicyStore::class));
        $store = $this->store();
        $policies = app(AgentPolicyStore::class);

        $this->assertFalse($policies->chatEnabled($store->id));
        $this->assertFalse($policies->walletSpendAllowed($store->id));

        StoreOption::updateStoreOption($store->id, 'agent_chat_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'agent_wallet_spend', '1');
        $this->assertTrue($policies->chatEnabled($store->id));
        $this->assertTrue($policies->walletSpendAllowed($store->id));

        StoreOption::updateStoreOption($store->id, 'agent_chat_enabled', '0');
        StoreOption::updateStoreOption($store->id, 'agent_wallet_spend', '0');
        $this->assertFalse($policies->chatEnabled($store->id));
        $this->assertFalse($policies->walletSpendAllowed($store->id));
    }

    public function test_a_bound_policy_store_decides_for_the_chat_widget_and_endpoint(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'agent_chat_enabled', '1');
        $this->app->instance(AgentPolicyStore::class, new class implements AgentPolicyStore
        {
            public function chatEnabled(int $storeId): bool
            {
                return false; // e.g. an org-wide rule switches the chat agent off
            }

            public function walletSpendAllowed(int $storeId): bool
            {
                return false;
            }
        });

        $this->assertFalse(AgentChatWidget::enabled($store));
        $this->withoutMiddleware()->postJson(route('agent.chat.message', $store->id), ['message' => 'hi'])->assertForbidden();
    }

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'S '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
