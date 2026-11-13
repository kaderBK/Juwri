<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentMessage;
use Juwri\Modules\Agents\Services\Agents\PiiRedactor;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Agents AO5 #30 — PII in customer chat is redacted before it is stored. */
class AgentPiiRedactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    public function test_redacts_email_phone_and_card(): void
    {
        $this->assertSame('reach me at [email]', PiiRedactor::redact('reach me at john.doe@example.com'));
        $this->assertSame('call [phone]', PiiRedactor::redact('call +1 (415) 555-2671'));
        $this->assertSame('card [card]', PiiRedactor::redact('card 4111 1111 1111 1111'));
    }

    public function test_leaves_ordinary_text_untouched(): void
    {
        $this->assertSame('two large pizzas please', PiiRedactor::redact('two large pizzas please'));
    }

    public function test_web_chat_stores_redacted_user_message(): void
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'ai_chatbot' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'pii-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);
        StoreOption::updateStoreOption($r->id, 'agent_chat_enabled', '1');

        $this->postJson("/agent-chat/{$r->id}/message", ['message' => 'my email is jane@site.com and phone 0912345678'])
            ->assertOk();

        $stored = AgentMessage::where('role', 'user')->latest('id')->first();
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('jane@site.com', $stored->content);
        $this->assertStringNotContainsString('0912345678', $stored->content);
        $this->assertStringContainsString('[email]', $stored->content);
    }
}
