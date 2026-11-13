<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Http\Controllers\Admin\AiProvidersController;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * ZERO-TOUCH Track B4 — admin/owner transcription config UI:
 * platform AI-providers page carries Groq + a per-provider STT model + voice
 * badge; owner agent-settings shows the read-only effective STT state; the
 * store settings page carries the voice_note_transcription toggle.
 */
class AiTranscriptionConfigUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        config([
            'ai.transcribe' => ['groq', 'openai'],
            'ai.providers.groq' => [
                'driver' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => null, 'model' => 'llama-3.3-70b-versatile',
                'allowed_models' => ['llama-3.3-70b-versatile'],
                'supports_json' => true, 'supports_vision' => false,
                'supports_transcription' => true,
                'transcription_model' => 'whisper-large-v3-turbo',
            ],
        ]);
    }

    private function bootLamar(): User
    {
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);

        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'ai_chatbot' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        return $owner;
    }

    public function test_admin_providers_data_includes_groq_with_stt_capability(): void
    {
        $view = app(AiProvidersController::class)->index(request(), app(AiProviderFactory::class));
        $providers = $view->getData()['providers'];

        $this->assertArrayHasKey('groq', $providers);
        $this->assertSame('Groq', $providers['groq']['label']);
        $this->assertTrue($providers['groq']['supports_transcription']);
        $this->assertSame('whisper-large-v3-turbo', $providers['groq']['env_transcription_model']);

        // Chat-only providers carry no STT capability.
        $this->assertFalse($providers['mimo']['supports_transcription']);
        $this->assertFalse($providers['anthropic']['supports_transcription']);
    }

    public function test_admin_update_persists_platform_key_and_stt_model_override(): void
    {
        $this->withoutMiddleware();
        $admin = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($admin)->post(route('admin.ai-providers.update'), [
            'provider' => 'groq',
            'active' => 1,
            'secrets' => ['api_key' => 'gsk-platform', 'transcription_model' => 'whisper-large-v3'],
        ])->assertRedirect();

        $cred = Credential::whereNull('store_id')->where('feature', 'ai')->where('provider', 'groq')->firstOrFail();
        $this->assertSame('gsk-platform', $cred->secrets['api_key']);
        $this->assertSame('whisper-large-v3', $cred->secrets['transcription_model']);

        // The platform credential now powers the effective transcribe chain
        // (key from DB overrides the empty env key; STT model overridden too).
        $described = app(AiProviderFactory::class)->describeTranscribeChain(null);
        $this->assertSame('groq', $described[0]['provider']);
        $this->assertSame('whisper-large-v3', $described[0]['model']);

        // Clearing the STT model falls back to the env default.
        $this->actingAs($admin)->post(route('admin.ai-providers.update'), [
            'provider' => 'groq',
            'active' => 1,
            'secrets' => ['api_key' => '••••••••', 'transcription_model' => ''],
        ])->assertRedirect();

        $this->assertArrayNotHasKey('transcription_model', Credential::whereNull('store_id')->where('provider', 'groq')->first()->secrets);
        $this->assertSame('whisper-large-v3-turbo', app(AiProviderFactory::class)->describeTranscribeChain(null)[0]['model']);
    }

    public function test_agent_settings_shows_effective_stt_provider_when_configured(): void
    {
        $owner = $this->bootLamar();
        config(['ai.providers.groq.api_key' => 'gsk-live']);

        $html = $this->actingAs($owner)->get(route('agent.settings.page'))->assertOk()->getContent();

        $this->assertStringContainsString('Voice is transcribed by', $html);
        $this->assertStringContainsString('groq', $html);
        $this->assertStringContainsString('whisper-large-v3-turbo', $html);
    }

    public function test_agent_settings_shows_not_configured_state_without_provider(): void
    {
        $owner = $this->bootLamar();
        config(['ai.providers.groq.api_key' => null, 'ai.providers.openai.api_key' => null]);

        $html = $this->actingAs($owner)->get(route('agent.settings.page'))->assertOk()->getContent();

        $this->assertStringContainsString('No voice-capable AI provider is set up', $html);
    }

    public function test_store_settings_page_carries_transcription_toggle(): void
    {
        $owner = $this->bootLamar();
        $store = Store::where('user_id', $owner->id)->firstOrFail();

        $html = $this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk()->getContent();

        $this->assertStringContainsString('name="voice_note_transcription"', $html);
        $this->assertStringContainsString('Transcribe voice notes automatically', $html);
    }
}
