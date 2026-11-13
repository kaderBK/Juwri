<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\AgentMessage;
use Tests\TestCase;

/**
 * Agents AO9 #45 — owner transcript dashboard: list conversations + read one,
 * strictly scoped to restaurants the owner owns.
 */
class AgentTranscriptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureStaffDashboardPermission::class,
            Installed::class,
        ]);
    }

    private function storeFixture(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function convo(int $storeId, ?int $orderId = null): AgentConversation
    {
        return AgentConversation::create([
            'store_id' => $storeId, 'channel' => 'web', 'session_key' => 'sk'.uniqid(),
            'order_id' => $orderId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_owner_lists_own_conversations_with_message_counts(): void
    {
        [$owner, $store] = $this->storeFixture();
        $c = $this->convo($store->id, 55);
        AgentMessage::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'hi']);
        AgentMessage::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'hello!']);

        $res = $this->actingAs($owner)->getJson("/ai-ordering/{$store->id}/transcripts");

        $res->assertOk();
        $this->assertCount(1, $res->json('conversations'));
        $this->assertSame(2, $res->json('conversations.0.messages'));
        $this->assertSame(55, $res->json('conversations.0.order_id'));
    }

    public function test_owner_reads_a_full_transcript(): void
    {
        [$owner, $store] = $this->storeFixture();
        $c = $this->convo($store->id);
        AgentMessage::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'two pizzas']);
        AgentMessage::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'coming up', 'tool_calls' => ['createOrder']]);

        $res = $this->actingAs($owner)->getJson("/ai-ordering/{$store->id}/transcripts/{$c->id}");

        $res->assertOk();
        $this->assertCount(2, $res->json('messages'));
        $this->assertSame('two pizzas', $res->json('messages.0.content'));
        $this->assertTrue($res->json('messages.1.has_tool'));
    }

    public function test_cannot_list_another_owners_transcripts(): void
    {
        [, $victimPost] = $this->storeFixture();
        [$attacker] = $this->storeFixture();

        $this->actingAs($attacker)->getJson("/ai-ordering/{$victimPost->id}/transcripts")->assertNotFound();
    }

    public function test_cannot_read_a_conversation_from_another_restaurant(): void
    {
        [$owner, $ownPost] = $this->storeFixture();
        [, $foreignPost] = $this->storeFixture();
        $foreign = $this->convo($foreignPost->id);
        AgentMessage::create(['conversation_id' => $foreign->id, 'role' => 'user', 'content' => 'secret']);

        // owner passes their own storeId but a conversation id they don't own → 404
        $this->actingAs($owner)->getJson("/ai-ordering/{$ownPost->id}/transcripts/{$foreign->id}")->assertNotFound();
    }
}
