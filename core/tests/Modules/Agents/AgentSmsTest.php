<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Services\Agents\AgentChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Agents AO7 #35: SMS inbound runs the SAME agent tool loop (channel='sms'). */
class AgentSmsTest extends TestCase
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

    public function test_sms_message_runs_tool_loop(): void
    {
        [$r] = $this->storeFixture();
        $this->fakeAi([
            ['tool' => 'search_menu', 'args' => ['query' => 'pizza']],
            ['reply' => 'We have Pizza for 12.'],
        ]);

        $reply = AgentChatService::forSms($r->id, '+15551230000', 'got pizza?');
        $this->assertSame('We have Pizza for 12.', $reply);

        $conv = AgentConversation::where('store_id', $r->id)->where('channel', 'sms')->first();
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
        $this->assertNull(AgentChatService::forSms($r->id, '+15551230000', 'hi'));
    }

    /** The inbound webhook routes a non-keyword SMS to the restaurant owning the destination number. */
    public function test_inbound_webhook_routes_to_agent_and_replies(): void
    {
        [$r] = $this->storeFixture();
        // Explicit local fixture escape hatch: production must keep this false.
        config(['sms.inbound_allow_unsigned' => true]);
        // (from-number only, so the reply send log-simulates instead of hitting Twilio.)
        Credential::create([
            'store_id' => $r->id, 'feature' => 'sms', 'provider' => 'twilio', 'active' => true,
            'secrets' => ['twilio_from' => '+15559990000'],
        ]);
        $this->fakeAi([
            ['tool' => 'search_menu', 'args' => ['query' => 'pizza']],
            ['reply' => 'We have Pizza for 12.'],
        ]);

        $this->post('/webhook/sms/inbound', [
            'From' => '+1 555 123 0000', 'To' => '+15559990000', 'Body' => 'do you have pizza?',
        ])->assertOk();

        // A conversation was opened on the sms channel...
        $this->assertNotNull(AgentConversation::where('store_id', $r->id)->where('channel', 'sms')->first());
        // ...and the reply was sent back (simulated, no live creds) to the sender.
        $this->assertDatabaseHas('sms_logs', ['to' => '+15551230000', 'feature' => 'agent_sms']);
    }

    /** With an auth token configured, an inbound lacking a valid Twilio signature is rejected 403 and never reaches the agent. */
    public function test_inbound_without_signature_is_rejected_when_token_set(): void
    {
        [$r] = $this->storeFixture();
        Credential::create([
            'store_id' => $r->id, 'feature' => 'sms', 'provider' => 'twilio', 'active' => true,
            'secrets' => ['twilio_sid' => 'x', 'twilio_token' => 'authtok', 'twilio_from' => '+15559990000'],
        ]);
        $this->fakeAi([['reply' => 'nope']]);

        $this->post('/webhook/sms/inbound', [
            'From' => '+15551230000', 'To' => '+15559990000', 'Body' => 'do you have pizza?',
        ])->assertStatus(403);

        $this->assertNull(AgentConversation::where('store_id', $r->id)->where('channel', 'sms')->first());
        $this->assertDatabaseMissing('sms_logs', ['feature' => 'agent_sms']);
    }

    /** A STOP keyword still opts out and never reaches the agent. */
    public function test_stop_keyword_does_not_reach_agent(): void
    {
        [$r] = $this->storeFixture();
        config(['sms.inbound_allow_unsigned' => true]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'sms', 'provider' => 'twilio', 'active' => true,
            'secrets' => ['twilio_from' => '+15559990000'],
        ]);

        $this->post('/webhook/sms/inbound', ['From' => '+15551230000', 'To' => '+15559990000', 'Body' => 'STOP'])->assertOk();

        $this->assertTrue(SmsOptout::isOptedOut('+15551230000'));
        $this->assertNull(AgentConversation::where('store_id', $r->id)->where('channel', 'sms')->first());
    }

    public function test_unsigned_stop_is_rejected_when_verification_secret_is_missing(): void
    {
        [$r] = $this->storeFixture();
        config(['sms.inbound_allow_unsigned' => false]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'sms', 'provider' => 'twilio', 'active' => true,
            'secrets' => ['twilio_from' => '+15559990000'],
        ]);

        $this->post('/webhook/sms/inbound', [
            'From' => '+15551230000',
            'To' => '+15559990000',
            'Body' => 'STOP',
        ])->assertForbidden();

        $this->assertFalse(SmsOptout::isOptedOut('+15551230000'));
    }

    /** An inbound to an unknown destination number is ignored (no agent, no crash). */
    public function test_unknown_destination_is_ignored(): void
    {
        [$r] = $this->storeFixture();
        $this->post('/webhook/sms/inbound', ['From' => '+15551230000', 'To' => '+19998887777', 'Body' => 'hi'])->assertOk();
        $this->assertNull(AgentConversation::where('store_id', $r->id)->where('channel', 'sms')->first());
    }

    /** P6-100: a re-delivered inbound (same MessageSid) runs the agent and replies once, even after a cache flush. */
    public function test_replayed_inbound_message_replies_once(): void
    {
        [$r] = $this->storeFixture();
        config(['sms.inbound_allow_unsigned' => true]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'sms', 'provider' => 'twilio', 'active' => true,
            'secrets' => ['twilio_from' => '+15559990000'],
        ]);
        $this->fakeAi([['reply' => 'One pizza ordered.'], ['reply' => 'Second reply.']]);
        $sms = ['From' => '+15551230000', 'To' => '+15559990000', 'Body' => 'one pizza please', 'MessageSid' => 'SMreplay1'];

        $this->post('/webhook/sms/inbound', $sms)->assertOk();
        Cache::flush();
        $this->post('/webhook/sms/inbound', $sms)->assertOk();

        $this->assertSame(1, DB::table('sms_logs')->where('feature', 'agent_sms')->count());
        $this->assertDatabaseHas('webhook_receipts', ['provider' => 'sms_inbound', 'event_id' => $r->id.':SMreplay1', 'status' => 'processed']);

        // A different message from the same sender is still answered.
        $this->post('/webhook/sms/inbound', ['MessageSid' => 'SMreplay2'] + $sms)->assertOk();
        $this->assertSame(2, DB::table('sms_logs')->where('feature', 'agent_sms')->count());
    }

    /** P6-249 sweep: the unsigned-inbound compatibility switch is ignored in production. */
    public function test_production_refuses_unsigned_inbound_even_when_the_switch_is_on(): void
    {
        [$r] = $this->storeFixture();
        config(['sms.inbound_allow_unsigned' => true]);
        $this->app['env'] = 'production';
        Credential::create([
            'store_id' => $r->id, 'feature' => 'sms', 'provider' => 'twilio', 'active' => true,
            'secrets' => ['twilio_from' => '+15559990000'],
        ]);

        $this->post('/webhook/sms/inbound', ['From' => '+15551230000', 'To' => '+15559990000', 'Body' => 'STOP'])->assertForbidden();
        $this->assertFalse(SmsOptout::isOptedOut('+15551230000'));
    }
}
