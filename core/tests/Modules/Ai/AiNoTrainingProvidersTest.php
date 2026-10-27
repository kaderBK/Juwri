<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\Studio\GeminiImageProvider;
use Tests\TestCase;

/**
 * P1-23 (Subscription Terms 5.3, DPA 11.2): "we use AI providers that do not train their models on the data we send
 * them". With ai.require_no_training on (juwri Cloud), a provider whose no_training flag is false never receives the
 * PLATFORM key — text, vision, image and transcription chains and the Menu Studio image model. A store's own key
 * (BYOK) is the store's contract with that provider and is not filtered. Off (the self-hosted default) = unchanged.
 */
class AiNoTrainingProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = fn (string $url, bool $noTraining, array $extra = []) => array_merge([
            'driver' => 'openai', 'base_url' => $url, 'api_key' => 'platform-key', 'model' => 'm',
            'allowed_models' => [], 'supports_json' => true, 'supports_vision' => true, 'no_training' => $noTraining,
        ], $extra);

        config([
            'ai.require_no_training' => false,
            'ai.default' => 'mimo',
            'ai.fallback' => ['deepseek', 'openai'],
            'ai.vision' => ['mimo', 'openai'],
            'ai.image' => ['gemini', 'openai'],
            'ai.transcribe' => ['groq', 'mimo'],
            'ai.providers' => [
                'mimo' => $provider('https://mimo.test/v1', false, ['supports_transcription' => true]),
                'deepseek' => $provider('https://deepseek.test', false),
                'openai' => $provider('https://openai.test/v1', true, ['supports_image_generation' => true]),
                'gemini' => $provider('https://gemini.test', false, ['supports_image_generation' => true]),
                'groq' => $provider('https://groq.test/v1', true, ['supports_transcription' => true]),
            ],
            'studio.gemini.api_key' => 'studio-key',
            'studio.gemini.no_training' => false,
        ]);
    }

    private function factory(): AiProviderFactory
    {
        return app(AiProviderFactory::class);
    }

    /** @param  array<int, object>  $chain */
    private function keys(array $chain): array
    {
        return array_map(fn ($p) => $p->name(), $chain);
    }

    private function store(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_switch_off_keeps_every_configured_provider(): void
    {
        $this->assertSame(['mimo', 'deepseek', 'openai'], $this->keys($this->factory()->chain(null)));
        $this->assertSame(['gemini', 'openai'], $this->keys($this->factory()->imageChain(null)));
        $this->assertSame(['groq', 'mimo'], $this->keys($this->factory()->transcribeChain(null)));
        $this->assertTrue(app(GeminiImageProvider::class)->isConfigured());
    }

    public function test_switch_on_sends_platform_keys_only_to_no_training_providers(): void
    {
        config(['ai.require_no_training' => true]);

        $this->assertSame(['openai'], $this->keys($this->factory()->chain(null)));
        $this->assertSame(['openai'], $this->keys($this->factory()->chain(null, true)), 'vision chain too');
        $this->assertSame(['openai'], $this->keys($this->factory()->imageChain(null)));
        $this->assertSame(['groq'], $this->keys($this->factory()->transcribeChain(null)));
        $this->assertSame(['openai'], array_column($this->factory()->describeChain(null), 'provider'));
        $this->assertSame(['groq'], array_column($this->factory()->describeTranscribeChain(null), 'provider'));
        $this->assertFalse(app(GeminiImageProvider::class)->isConfigured(), 'Menu Studio: free-tier Gemini is off');

        config(['studio.gemini.no_training' => true]);
        $this->assertTrue(app(GeminiImageProvider::class)->isConfigured(), 'paid-tier Gemini flagged no_training');
    }

    public function test_a_platform_credential_row_is_still_a_platform_key(): void
    {
        config(['ai.require_no_training' => true, 'ai.providers.deepseek.api_key' => '']);
        Credential::create([
            'store_id' => null, 'feature' => 'ai', 'provider' => 'deepseek', 'active' => true,
            'secrets' => ['api_key' => 'db-platform-key'],
        ]);

        $this->assertNotContains('deepseek', $this->keys($this->factory()->chain(null)));
    }

    public function test_a_store_own_key_is_not_filtered(): void
    {
        config(['ai.require_no_training' => true]);
        $store = $this->store();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'store-own-key'],
        ]);

        $this->assertSame(['mimo', 'openai'], $this->keys($this->factory()->chain($store->id)));
        $described = $this->factory()->describeChain($store->id);
        $this->assertSame(['provider' => 'mimo', 'key_source' => 'byok'], ['provider' => $described[0]['provider'], 'key_source' => $described[0]['key_source']]);
        $this->assertSame(['groq', 'mimo'], $this->keys($this->factory()->transcribeChain($store->id)));
    }

    public function test_shipped_flags_match_the_providers_terms_we_rely_on(): void
    {
        $shipped = require config_path('ai.php');

        $this->assertFalse($shipped['require_no_training'], 'self-hosted default: off');
        foreach (['openai' => true, 'anthropic' => true, 'groq' => true, 'gemini' => false, 'mimo' => false, 'kimi' => false, 'deepseek' => false] as $key => $flag) {
            $this->assertSame($flag, $shipped['providers'][$key]['no_training'], "$key no_training default");
        }
    }
}
