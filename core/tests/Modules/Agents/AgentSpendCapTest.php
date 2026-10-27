<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AbuseHold;
use Juwri\Modules\Agents\Models\AgentMessage;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-11: the agent's spend limits. A cap that is reached means the turn is refused BEFORE any provider is called (no HTTP request
 * leaves the app, no tool runs, no order is placed); under the cap the same turn goes through (so the refusals are not vacuous).
 * Prepaid-credit and own-key interplay of the monthly allowance is pinned in ee CreditUsagePathsTest.
 */
class AgentSpendCapTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config([
            'ai.default' => 'mimo', 'ai.fallback' => [], 'ai.retries' => 1, 'ai.timeout' => 5,
            'ai.providers.mimo' => ['driver' => 'openai', 'base_url' => 'https://api.example-ai.test/v1', 'api_key' => 'k-platform', 'model' => 'm', 'supports_json' => true, 'supports_vision' => false],
            'settings.abuse_ai_spend_mode' => 'enforce',
        ]);
        Http::fake(function (Request $r) {
            $this->sent[] = $r;
            $o = $this->script[min($this->turn++, count($this->script) - 1)];

            return Http::response(['choices' => [['message' => ['content' => json_encode($o)]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5]], 200);
        });
        $this->replies([['reply' => 'Hello from the model']]);

        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => '1'], 'status' => 1,
        ]);
        $this->owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->store = Store::create([
            'user_id' => $this->owner->id, 'slug' => 'cap-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => 'Cap', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($this->store->id, 'agent_chat_enabled', '1');
        $cat = MenuCategory::create(['store_id' => $this->store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $this->store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);
    }

    /** @var list<array<string, mixed>> */
    private array $script = [];

    private int $turn = 0;

    /** The provider answers these JSON objects in turn (as OpenAI-style chat completions); every request is recorded in $sent. */
    private function replies(array $objects): void
    {
        $this->script = $objects;
        $this->turn = 0;
        $this->sent = [];
    }

    private function usage(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            AiUsage::create(['user_id' => $this->owner->id, 'provider' => 'mimo', 'model' => 'm', 'feature' => 'chatbot', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'fell_back' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function say(string $text = 'hi')
    {
        return $this->postJson(route('agent.chat.message', $this->store->id), ['message' => $text, 'session_key' => 'cap-session']);
    }

    public function test_under_the_cap_the_turn_reaches_the_provider(): void
    {
        config(['settings.abuse_ai_spend_user_daily_cap' => 5]);

        $this->say()->assertOk()->assertJsonPath('reply', 'Hello from the model');

        $this->assertCount(1, $this->sent, 'the model was called once');
        $this->assertSame(1, AiUsage::where('user_id', $this->owner->id)->count(), 'and metered');
    }

    public function test_a_reached_daily_cap_refuses_the_turn_before_any_provider_call(): void
    {
        config(['settings.abuse_ai_spend_user_daily_cap' => 2]);
        $this->usage(2);

        $reply = $this->say('please order a pizza')->assertOk()->json('reply');

        $this->assertNotSame('Hello from the model', $reply);
        $this->assertCount(0, $this->sent, 'no request left the app');
        $this->assertSame(2, AiUsage::where('user_id', $this->owner->id)->count(), 'nothing more was metered');
        $this->assertSame(0, AgentMessage::where('role', 'tool')->count(), 'no tool ran');
        $this->assertSame(0, Order::where('store_id', $this->store->id)->count(), 'and no order was placed');
    }

    public function test_the_cap_is_hit_in_the_middle_of_a_turn_and_the_loop_stops(): void
    {
        config(['settings.abuse_ai_spend_user_daily_cap' => 1]);
        $this->replies([['tool' => 'search_menu', 'args' => ['query' => 'pizza']], ['reply' => 'never reached']]);

        $reply = $this->say('what pizza do you have')->assertOk()->json('reply');

        $this->assertCount(1, $this->sent, 'the second model call (after the tool) was refused by the cap, not sent');
        $this->assertNotSame('never reached', $reply);
        $this->assertSame(1, AgentMessage::where('role', 'tool')->count(), 'the first tool step is logged, nothing after it');
    }

    public function test_a_used_up_monthly_allowance_with_no_prepaid_credit_refuses_without_a_provider_call(): void
    {
        config(['ai.monthly_call_limit' => 3, 'settings.abuse_ai_spend_mode' => 'off']); // the daily guard is off: only the plan allowance speaks
        $this->usage(3);

        $reply = $this->say()->assertOk()->json('reply');

        $this->assertNotSame('Hello from the model', $reply);
        $this->assertCount(0, $this->sent);
        $this->assertSame(3, AiUsage::where('user_id', $this->owner->id)->count());
    }

    public function test_an_account_under_review_is_frozen_regardless_of_the_guard_mode(): void
    {
        config(['settings.abuse_ai_spend_mode' => 'off']);
        AbuseHold::set($this->owner->id, true);

        $reply = $this->say()->assertOk()->json('reply');

        $this->assertNotSame('Hello from the model', $reply);
        $this->assertCount(0, $this->sent);
    }
}
