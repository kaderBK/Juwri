<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiService;
use Tests\TestCase;

/**
 * ZERO-TOUCH Track B1 — transcription capability on the AI provider layer:
 * OpenAI-compatible /audio/transcriptions (Groq/OpenAI), chain fallback,
 * metering by audio seconds, file guards, BYOK model override.
 */
class AiTranscribeTest extends TestCase
{
    use RefreshDatabase;

    private string $audio = '';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.transcribe' => ['groq', 'openai'],
            'ai.retries' => 0,
            'ai.providers.groq' => [
                'driver' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => 'test-groq', 'model' => 'llama-3.3-70b-versatile',
                'supports_json' => true, 'supports_vision' => false,
                'supports_transcription' => true,
                'transcription_model' => 'whisper-large-v3-turbo',
            ],
            'ai.providers.openai' => [
                'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'test-openai', 'model' => 'gpt-4o',
                'supports_json' => true, 'supports_vision' => true,
                'supports_transcription' => true,
                'transcription_model' => 'whisper-1',
            ],
        ]);

        $this->audio = tempnam(sys_get_temp_dir(), 'stt').'.webm';
        file_put_contents($this->audio, str_repeat('a', 2048)); // fake opus blob
    }

    protected function tearDown(): void
    {
        @unlink($this->audio);
        parent::tearDown();
    }

    private function verboseJson(string $text, float $duration = 4.2, string $lang = 'en'): array
    {
        return ['text' => $text, 'language' => $lang, 'duration' => $duration];
    }

    public function test_groq_transcribes_multipart_and_meters_audio_seconds(): void
    {
        Http::fake(['api.groq.com/*' => Http::response($this->verboseJson('add two pizzas', 7.4), 200)]);

        $result = app(AiService::class)->transcribe($this->audio, ['language' => 'en'], userId: 1, feature: 'stt_test');

        $this->assertSame('add two pizzas', $result->text);
        $this->assertSame('groq', $result->provider);
        $this->assertSame('whisper-large-v3-turbo', $result->model);
        $this->assertSame('en', $result->language);
        $this->assertEqualsWithDelta(7.4, $result->durationSeconds, 0.001);

        // Multipart POST to /audio/transcriptions with file + model fields.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.groq.com/openai/v1/audio/transcriptions')
                && $request->hasFile('file')
                && collect($request->data())->contains(fn ($p) => ($p['name'] ?? '') === 'model' && ($p['contents'] ?? '') === 'whisper-large-v3-turbo')
                && collect($request->data())->contains(fn ($p) => ($p['name'] ?? '') === 'language' && ($p['contents'] ?? '') === 'en');
        });

        // Metered: 1 usage row billed by audio seconds (ceil 7.4 -> 8).
        $this->assertDatabaseHas('ai_usage', [
            'provider' => 'groq', 'feature' => 'stt_test', 'audio_seconds' => 8, 'fell_back' => false,
        ]);
    }

    public function test_falls_back_to_openai_when_groq_fails(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response('boom', 500),
            'api.openai.com/*' => Http::response($this->verboseJson('hello', 3.0), 200),
        ]);

        $result = app(AiService::class)->transcribe($this->audio, [], userId: 1, feature: 'stt_test');

        $this->assertSame('openai', $result->provider);
        $this->assertSame('whisper-1', $result->model);
        $this->assertDatabaseHas('ai_usage', ['provider' => 'openai', 'fell_back' => true, 'audio_seconds' => 3]);
    }

    public function test_clean_exception_when_no_capable_provider(): void
    {
        config([
            'ai.providers.groq.api_key' => null,
            'ai.providers.openai.supports_transcription' => false,
        ]);
        Http::fake();

        try {
            app(AiService::class)->transcribe($this->audio, [], userId: 1);
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('No transcription provider', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_oversize_audio_rejected_before_any_http(): void
    {
        file_put_contents($this->audio, str_repeat('a', 2 * 1024 * 1024 + 10));
        Http::fake();

        $this->expectException(AiProviderException::class);
        try {
            app(AiService::class)->transcribe($this->audio, [], userId: 1);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_disallowed_extension_rejected_before_any_http(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'stt').'.exe';
        file_put_contents($bad, 'x');
        Http::fake();

        try {
            $this->expectException(AiProviderException::class);
            app(AiService::class)->transcribe($bad, [], userId: 1);
        } finally {
            Http::assertNothingSent();
            @unlink($bad);
        }
    }

    public function test_anthropic_never_supports_transcription_and_chain_skips_it(): void
    {
        config([
            'ai.transcribe' => ['anthropic', 'groq'],
            'ai.providers.anthropic' => [
                'driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1',
                'api_key' => 'test-anthropic', 'model' => 'claude-sonnet-4-6',
                // Even a (mis)configured capability flag must not enable STT.
                'supports_transcription' => true,
            ],
        ]);

        $factory = app(AiProviderFactory::class);
        $this->assertFalse($factory->make('anthropic')->supportsTranscription());

        $chain = $factory->transcribeChain(null);
        $this->assertSame(['groq'], array_map(fn ($p) => $p->name(), $chain));
    }

    public function test_byok_credential_overrides_key_and_stt_model(): void
    {
        // A restaurant that brings its own Groq key + a custom STT model.
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'stt-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create([
            'store_id' => $r->id,
            'feature' => 'ai',
            'provider' => 'groq',
            'active' => true,
            'secrets' => ['api_key' => 'byok-groq', 'transcription_model' => 'whisper-large-v3'],
        ]);

        Http::fake(['api.groq.com/*' => Http::response($this->verboseJson('shukran', 2.0, 'ar'), 200)]);

        $result = app(AiService::class)->transcribe($this->audio, [], userId: 1, storeId: $r->id);

        $this->assertSame('whisper-large-v3', $result->model);
        Http::assertSent(function ($request) {
            return $request->header('Authorization')[0] === 'Bearer byok-groq'
                && collect($request->data())->contains(fn ($p) => ($p['name'] ?? '') === 'model' && ($p['contents'] ?? '') === 'whisper-large-v3');
        });

        // Effective-state describe mirrors the same resolution.
        $described = app(AiProviderFactory::class)->describeTranscribeChain($r->id);
        $this->assertSame('groq', $described[0]['provider']);
        $this->assertSame('whisper-large-v3', $described[0]['model']);
        $this->assertSame('byok', $described[0]['key_source']);
    }

    public function test_empty_transcript_text_is_a_valid_result(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['text' => '', 'duration' => 1.0], 200)]);

        $result = app(AiService::class)->transcribe($this->audio, [], userId: 1);

        $this->assertSame('', $result->text);
        $this->assertNull($result->language);
    }
}
