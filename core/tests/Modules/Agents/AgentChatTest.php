<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\Agents\AgentChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Agents P2: chat endpoint gate + tool dispatch + conversation logging (scripted AI). */
class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(bool $chat = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => $chat ? '1' : '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'agent_chat_enabled', $chat ? '1' : '0');
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return [$r, $item];
    }

    private function fakeAi(array $script): void
    {
        $fake = new class extends AiService
        {
            public array $script = [];

            public int $idx = 0;

            public function __construct() {}

            public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
            {
                return $this->script[$this->idx++] ?? ['reply' => 'done'];
            }
        };
        $fake->script = $script;
        app()->instance(AiService::class, $fake);
    }

    public function test_dispatch_tool_routes_and_refuses_unknown(): void
    {
        [$r, $item] = $this->storeFixture();
        $ok = AgentChatService::dispatchTool($r, 'search_menu', ['query' => 'Pizza']);
        $this->assertNotEmpty($ok['result']);
        $bad = AgentChatService::dispatchTool($r, 'evil_tool', ['x' => 1]);
        $this->assertArrayHasKey('error', $bad);
    }

    public function test_endpoint_gated_by_plan_and_option(): void
    {
        [$r] = $this->storeFixture(chat: false);
        $this->postJson(route('agent.chat.message', $r->id), ['message' => 'hi'])->assertStatus(403);
    }

    public function test_chat_runs_tool_then_replies_and_logs(): void
    {
        [$r] = $this->storeFixture();
        $this->fakeAi([
            ['tool' => 'search_menu', 'args' => ['query' => 'pizza']],
            ['reply' => 'We have Pizza for 12.'],
        ]);

        $res = $this->postJson(route('agent.chat.message', $r->id), ['message' => 'got pizza?'])
            ->assertOk()->assertJsonPath('reply', 'We have Pizza for 12.');

        $conv = AgentConversation::where('store_id', $r->id)->first();
        $this->assertNotNull($conv);
        // user + tool + assistant logged.
        $roles = $conv->messages()->pluck('role')->all();
        $this->assertContains('user', $roles);
        $this->assertContains('tool', $roles);
        $this->assertContains('assistant', $roles);
    }

    public function test_injection_in_message_cannot_run_unknown_tool(): void
    {
        [$r] = $this->storeFixture();
        // Even if the model were tricked into naming a bogus tool, dispatch refuses it.
        $out = AgentChatService::dispatchTool($r, 'DROP TABLE orders; delete_everything', []);
        $this->assertArrayHasKey('error', $out);
    }
}
