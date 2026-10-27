<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Models\MenuCategory;
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

/** Agents P5: WhatsApp inbound runs the SAME tool loop (binds to the built WhatsApp suite). */
class AgentWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(bool $agent = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => $agent ? '1' : '0'],
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
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return [$r];
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

    public function test_whatsapp_message_runs_tool_loop(): void
    {
        [$r] = $this->storeFixture();
        $this->fakeAi([
            ['tool' => 'search_menu', 'args' => ['query' => 'pizza']],
            ['reply' => 'We have Pizza for 12.'],
        ]);

        $reply = AgentChatService::forWhatsApp($r->id, '+15551230000', 'got pizza?');
        $this->assertSame('We have Pizza for 12.', $reply);

        $conv = AgentConversation::where('store_id', $r->id)->where('channel', 'whatsapp')->first();
        $this->assertNotNull($conv);
        $roles = $conv->messages()->pluck('role')->all();
        $this->assertContains('user', $roles);
        $this->assertContains('tool', $roles);
        $this->assertContains('assistant', $roles);
    }

    public function test_returns_null_when_agent_ordering_disabled(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        [$r] = $this->storeFixture(agent: false);
        $this->assertNull(AgentChatService::forWhatsApp($r->id, '+15551230000', 'hi'));
    }
}
