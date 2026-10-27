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
use Juwri\Modules\Agents\Services\Agents\AgentSafety;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Agents AO5 #29/#31 — abuse filter + human handoff run before any model call. */
class AgentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'ai_chatbot' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'saf-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return $r;
    }

    private function convo(Store $r): AgentConversation
    {
        return AgentConversation::create(['store_id' => $r->id, 'channel' => 'web', 'session_key' => 'sk'.uniqid()]);
    }

    public function test_abuse_and_human_detectors(): void
    {
        $this->assertTrue(AgentSafety::isAbusive('you are an asshole'));
        $this->assertTrue(AgentSafety::isAbusive('aaaaaaaaaaaaaaaaaaaa'));
        $this->assertFalse(AgentSafety::isAbusive('two large pizzas please'));

        $this->assertTrue(AgentSafety::wantsHuman('can I talk to a human?'));
        $this->assertTrue(AgentSafety::wantsHuman('I want to file a complaint'));
        $this->assertFalse(AgentSafety::wantsHuman('what pizzas do you have'));
    }

    public function test_abusive_message_is_refused_without_calling_the_model(): void
    {
        $r = $this->storeFixture();
        $c = $this->convo($r);
        // No AI config set: if the model were called this would fall back. The
        // refusal path returns a distinct, deterministic string instead.
        $reply = AgentChatService::handle($c, $r, 'fuck you');
        $this->assertStringContainsString(___('I want to help with your order. Let’s keep it respectful — what can I get you?'), $reply);
        $this->assertSame(0, $c->messages()->where('role', 'tool')->count());
    }

    public function test_human_request_flags_conversation_for_handoff(): void
    {
        $r = $this->storeFixture();
        $c = $this->convo($r);
        $reply = AgentChatService::handle($c, $r, 'please let me speak to a person');
        $this->assertStringContainsString(___('I’ve flagged this for a team member — someone will follow up with you shortly.'), $reply);
        $this->assertTrue((bool) ($c->fresh()->meta['handoff'] ?? false));
    }
}
