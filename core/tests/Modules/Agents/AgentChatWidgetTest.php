<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Support\AgentChatWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * ZERO-TOUCH Track A — storefront agent-chat widget:
 * render gating (owner option + plan feature), STT seam visibility
 * (Route::has('agent.stt')), locale wiring, and the structured
 * create_order payload the endpoint hands to the confirm card.
 */
class AgentChatWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        session()->regenerateToken(); // csrf_token() inside view renders
    }

    /** @return array{0: Store, 1: Menu} */
    private function storeFixture(bool $optionOn = true, bool $planOn = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'ai_chatbot' => $planOn ? '1' : '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#10b981', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'agent_chat_enabled', $optionOn ? '1' : '0');
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return [$r, $item];
    }

    private function render(?Store $store): string
    {
        return view('store_templates.partials.agent-chat', ['store' => $store])->render();
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

    // ---- A1: render gating -------------------------------------------------

    public function test_widget_renders_when_enabled(): void
    {
        [$r] = $this->storeFixture();
        $html = $this->render($r);

        $this->assertStringContainsString('id="agent-chat"', $html);
        $this->assertStringContainsString('id="agent-chat-panel"', $html);
        $this->assertStringContainsString('window.__agentChatCfg', $html);
        // Endpoint URL is in the cfg (JSON-escaped slashes).
        $this->assertStringContainsString(str_replace('/', '\/', route('agent.chat.message', $r->id)), $html);
        // Inline blocks are nonce'd (CSP enforce readiness).
        $this->assertMatchesRegularExpression('/<style nonce="[^"]+">/', $html);
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">/', $html);
        // Hidden until JS boots → no-JS visitors see nothing.
        $this->assertStringContainsString('<div id="agent-chat" hidden>', $html);
    }

    public function test_widget_absent_when_owner_disabled(): void
    {
        [$r] = $this->storeFixture(optionOn: false);
        $this->assertSame('', trim($this->render($r)));
    }

    public function test_widget_absent_when_plan_lacks_feature(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        [$r] = $this->storeFixture(planOn: false);
        $this->assertSame('', trim($this->render($r)));
    }

    public function test_widget_absent_without_post(): void
    {
        $this->assertSame('', trim($this->render(null)));
    }

    public function test_seam_shapes_for_js_runtime_themes(): void
    {
        [$r] = $this->storeFixture();
        $seam = AgentChatWidget::seam($r);
        $this->assertTrue($seam['enabled']);
        $this->assertSame(route('agent.chat.message', $r->id), $seam['endpoint']);
        $this->assertArrayHasKey('sttEndpoint', $seam);
        $this->assertNotEmpty($seam['csrf']);
        $this->assertNotEmpty($seam['strings']['placeholder']);

        [$off] = $this->storeFixture(optionOn: false);
        $this->assertSame(['enabled' => false], AgentChatWidget::seam($off));
        $this->assertSame(['enabled' => false], AgentChatWidget::seam(null));
    }

    // ---- A2: voice hooks (STT seam gated on Route::has) ---------------------

    public function test_mic_button_is_hidden_by_default_markup(): void
    {
        // Layer 3 default: mic is revealed only by JS capability detection.
        [$r] = $this->storeFixture();
        $this->assertStringContainsString('id="agent-chat-mic" class="agent-chat__mic" hidden', $this->render($r));
    }

    public function test_stt_endpoint_absent_without_route(): void
    {
        if (Route::has('agent.stt')) {
            $this->markTestSkipped('agent.stt exists in this build (Track B merged) — covered by the with-route test.');
        }
        [$r] = $this->storeFixture();
        $this->assertNull(AgentChatWidget::sttEndpoint($r));
        // cfg carries stt:null → the widget never renders the recorder fallback.
        $this->assertStringContainsString('"stt":null', $this->render($r));
    }

    /**
     * The endpoint appears only when the route exists AND a transcription
     * provider is actually configured — the route alone is not enough, because
     * an unconfigured chain 422s and the mic would record the user's voice and
     * throw it away (the only path on browsers without the Web Speech API).
     */
    public function test_stt_endpoint_present_when_route_exists_and_a_provider_is_configured(): void
    {
        if (! Route::has('agent.stt')) {
            // A↔B seam: simulate Track B's endpoint (contract: multipart audio → {"text"}).
            Route::post('__zt-test/agent-stt/{storeId}', fn () => ['text' => ''])->name('agent.stt');
            Route::getRoutes()->refreshNameLookups();
        }
        $this->withTranscribeProvider();
        [$r] = $this->storeFixture();

        $url = AgentChatWidget::sttEndpoint($r);
        $this->assertNotNull($url);
        $this->assertSame(route('agent.stt', $r->id), $url);
        $this->assertStringContainsString(str_replace('/', '\/', $url), $this->render($r));
    }

    /**
     * The bug this guards: CI has no API key, so the chain is empty — before the
     * fix the widget still advertised the endpoint and the mic recorded into a
     * 422.
     */
    public function test_stt_endpoint_absent_when_no_transcription_provider_is_configured(): void
    {
        if (! Route::has('agent.stt')) {
            Route::post('__zt-test/agent-stt/{storeId}', fn () => ['text' => ''])->name('agent.stt');
            Route::getRoutes()->refreshNameLookups();
        }
        config(['ai.transcribe' => []]);
        [$r] = $this->storeFixture();

        $this->assertNull(AgentChatWidget::sttEndpoint($r));
        $this->assertStringContainsString('"stt":null', $this->render($r));
    }

    /** A configured, transcription-capable provider chain for this test run. */
    private function withTranscribeProvider(): void
    {
        config([
            'ai.transcribe' => ['openai'],
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-key',
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        ]);
    }

    public function test_locale_is_wired_for_speech_recognition(): void
    {
        [$r] = $this->storeFixture();
        app()->setLocale('ar');
        $this->assertSame('ar', AgentChatWidget::seam($r)['locale']);
        $this->assertStringContainsString('"locale":"ar"', $this->render($r));
    }

    // ---- A1.3: endpoint smoke + structured order payload --------------------

    public function test_endpoint_returns_structured_order_payload_and_links_conversation(): void
    {
        [$r, $item] = $this->storeFixture();
        $this->fakeAi([
            ['tool' => 'create_order', 'args' => ['items' => [['item_id' => $item->id, 'quantity' => 1]], 'type' => 'takeaway', 'client_uuid' => 'w1']],
            ['reply' => 'Done! Your order is placed.'],
        ]);

        $res = $this->postJson(route('agent.chat.message', $r->id), ['message' => 'one pizza please'])
            ->assertOk()
            ->assertJsonPath('reply', 'Done! Your order is placed.');

        $order = $res->json('order');
        $this->assertNotNull($order, 'structured order payload present');
        $this->assertNotEmpty($order['order_id']);
        $this->assertNotEmpty($order['track_token']);
        $this->assertStringContainsString('/track/', (string) $order['continue_url']);
        // The widget link must point at the human tracking PAGE, not the API JSON.
        $this->assertSame(url('/order/track/'.$order['track_token']), $order['track_url']);
        // C→A seam: key exists, null until Track C ships eta_range.
        $this->assertArrayHasKey('eta_range', $order);

        // Conversation is linked to its order (analytics + owner transcripts).
        $conv = AgentConversation::where('store_id', $r->id)->first();
        $this->assertSame((int) $order['order_id'], (int) $conv->order_id);
    }

    // ---- A4.1: owner toggle UI -----------------------------------------------

    public function test_owner_settings_page_shows_widget_toggle_and_voice_help(): void
    {
        [$r] = $this->storeFixture();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);

        $this->actingAs($r->user)->get(route('agent.settings.page'))->assertOk()->assertSeeHtml('name="agent_chat_enabled"')
            ->assertSee(___('Show the chat & order widget on my menu'))
            ->assertSee(___('Shows as a floating chat button on your menu page.'))
            ->assertSee(___('Customers can also dictate messages — a microphone appears automatically when their browser supports voice input.'));
    }

    public function test_endpoint_returns_null_order_for_plain_replies(): void
    {
        [$r] = $this->storeFixture();
        $this->fakeAi([['reply' => 'We open at 9.']]);

        $this->postJson(route('agent.chat.message', $r->id), ['message' => 'opening hours?'])
            ->assertOk()
            ->assertJsonPath('reply', 'We open at 9.')
            ->assertJsonPath('order', null);

        $this->assertNull(AgentConversation::where('store_id', $r->id)->first()->order_id);
    }
}
