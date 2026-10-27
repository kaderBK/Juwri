<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Two OpenAI-compatible providers, mimo default -> kimi fallback.
        config([
            'ai.default'  => 'mimo',
            'ai.fallback' => ['kimi'],
            'ai.vision'   => [],
            'ai.retries'  => 1,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'test-mimo', 'model' => 'mimo-v2.5-pro', 'supports_json' => true, 'supports_vision' => false,
            ],
            'ai.providers.kimi' => [
                'driver' => 'openai', 'base_url' => 'https://api.moonshot.ai/v1',
                'api_key' => 'test-kimi', 'model' => 'kimi-k2.5', 'supports_json' => true, 'supports_vision' => false,
            ],
        ]);
    }

    private function openAiBody(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage'   => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ];
    }

    /** Default provider 5xx -> chain falls through to the fallback provider. */
    public function test_falls_back_to_next_provider_on_server_error(): void
    {
        Http::fake([
            'api.xiaomimimo.com/*' => Http::response('upstream boom', 500),
            'api.moonshot.ai/*'    => Http::response($this->openAiBody('hello from kimi'), 200),
        ]);

        $out = app(AiService::class)->complete(
            [['role' => 'user', 'content' => 'hi']],
            userId: 1, feature: 'unit'
        );

        $this->assertSame('hello from kimi', $out);
        $this->assertDatabaseHas('ai_usage', ['provider' => 'kimi', 'fell_back' => true, 'feature' => 'unit']);
    }

    /** Malformed JSON on first attempt -> retry-with-error succeeds on same provider. */
    public function test_json_retries_with_error_feedback(): void
    {
        Http::fakeSequence('api.xiaomimimo.com/*')
            ->push('not json at all', 200)
            ->push($this->openAiBody('{"ok":true}'), 200);

        $decoded = app(AiService::class)->json(
            [['role' => 'user', 'content' => 'give json']],
            userId: 1, feature: 'unit'
        );

        $this->assertSame(['ok' => true], $decoded);
    }

    /** Validator rejects bad shape from default, accepts from fallback. */
    public function test_json_validator_drives_fallback(): void
    {
        config(['ai.retries' => 0]); // no same-provider retry, force provider fallback

        Http::fake([
            'api.xiaomimimo.com/*' => Http::response($this->openAiBody('{"wrong":1}'), 200),
            'api.moonshot.ai/*'    => Http::response($this->openAiBody('{"categories":[]}'), 200),
        ]);

        $decoded = app(AiService::class)->json(
            [['role' => 'user', 'content' => 'menu']],
            validator: fn ($d) => array_key_exists('categories', $d),
            userId: 1, feature: 'unit'
        );

        $this->assertSame(['categories' => []], $decoded);
        // P1-15 regression: the fallback is recorded (json() metered fell_back=false even when kimi served).
        $this->assertDatabaseHas('ai_usage', ['provider' => 'kimi', 'fell_back' => true, 'feature' => 'unit']);
        $this->assertSame(1, AiUsage::count());
    }

    /** All providers fail -> exception surfaces (caller handles gracefully). */
    public function test_throws_when_all_providers_fail(): void
    {
        config(['ai.retries' => 0]);
        Http::fake([
            'api.xiaomimimo.com/*' => Http::response('x', 500),
            'api.moonshot.ai/*'    => Http::response('y', 500),
        ]);

        $this->expectException(AiProviderException::class);
        app(AiService::class)->complete([['role' => 'user', 'content' => 'hi']], userId: 1);
    }

    /** Markdown-fenced JSON still decodes. */
    public function test_decode_strips_markdown_fences(): void
    {
        $svc = app(AiService::class);
        $this->assertSame(['a' => 1], $svc->decodeJson("```json\n{\"a\":1}\n```"));
        $this->assertSame(['b' => 2], $svc->decodeJson("Here you go: {\"b\":2}  done"));
        $this->assertNull($svc->decodeJson('totally not json'));
    }
}
