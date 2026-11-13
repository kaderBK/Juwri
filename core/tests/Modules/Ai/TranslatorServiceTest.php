<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Juwri\Modules\Ai\Services\TranslatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TranslatorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.default'  => 'mimo',
            'ai.fallback' => [],
            'ai.vision'   => [],
            'ai.retries'  => 0,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'k', 'model' => 'm', 'supports_json' => true, 'supports_vision' => false,
            ],
        ]);
    }

    /** Dynamic fake: reads the request payload and echoes each value with a prefix. */
    private function fakeEcho(string $prefix = 'X:'): void
    {
        Http::fake(['api.xiaomimimo.com/*' => function ($request) use ($prefix) {
            $content = $request->data()['messages'][1]['content'] ?? '';
            preg_match('/\{.*\}/s', $content, $m);
            $payload = json_decode($m[0] ?? '{}', true) ?: [];
            $out = [];
            foreach ($payload as $k => $v) {
                $out[$k] = $prefix . $v;
            }
            return Http::response([
                'choices' => [['message' => ['content' => json_encode($out, JSON_UNESCAPED_UNICODE)]]],
                'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ], 200);
        }]);
    }

    public function test_translate_single_record(): void
    {
        $this->fakeEcho('AR:');
        $out = app(TranslatorService::class)->translate(
            ['name' => 'Chicken Soup', 'description' => 'Warm bowl'],
            'Arabic', 'English', 1, null
        );
        $this->assertSame('AR:Chicken Soup', $out['name']);
        $this->assertSame('AR:Warm bowl', $out['description']);
    }

    public function test_batch_dedupes_and_returns_per_record(): void
    {
        $this->fakeEcho('AR:');
        $records = [
            'a' => ['title' => 'Water'],
            'b' => ['title' => 'Water'],          // duplicate string
            'c' => ['title' => 'Juice', 'description' => 'Fresh'],
        ];
        $out = app(TranslatorService::class)->translateBatch($records, 'Arabic', ['userId' => 1]);

        $this->assertSame('AR:Water', $out['a']['title']);
        $this->assertSame('AR:Water', $out['b']['title']);
        $this->assertSame('AR:Juice', $out['c']['title']);
        $this->assertSame('AR:Fresh', $out['c']['description']);

        // Deduped: 3 unique strings (Water, Juice, Fresh) -> a single batched call.
        Http::assertSentCount(1);
    }

    public function test_empty_short_circuits_without_calling_ai(): void
    {
        Http::fake();
        $out = app(TranslatorService::class)->translateBatch(['a' => ['title' => '  ']], 'Arabic');
        $this->assertFalse($out['a']['_lowconf']);
        Http::assertNothingSent();
    }

    public function test_low_confidence_flag_when_untranslated(): void
    {
        // Echo returns the value unchanged (target == source) -> low confidence.
        Http::fake(['api.xiaomimimo.com/*' => function ($request) {
            $content = $request->data()['messages'][1]['content'] ?? '';
            preg_match('/\{.*\}/s', $content, $m);
            $payload = json_decode($m[0] ?? '{}', true) ?: [];
            return Http::response([
                'choices' => [['message' => ['content' => json_encode($payload)]]],
                'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ], 200);
        }]);

        $out = app(TranslatorService::class)->translateBatch(['a' => ['title' => 'Pizza']], 'Arabic', ['userId' => 1]);
        $this->assertTrue($out['a']['_lowconf']);
    }

    public function test_glossary_terms_go_into_the_prompt(): void
    {
        $captured = '';
        Http::fake(['api.xiaomimimo.com/*' => function ($request) use (&$captured) {
            $captured = $request->data()['messages'][0]['content'] ?? '';
            return Http::response(['choices' => [['message' => ['content' => '{"s0":"x"}']]], 'usage' => []], 200);
        }]);

        app(TranslatorService::class)->translateBatch(['a' => ['title' => 'Big Mac']], 'Arabic', [
            'userId' => 1, 'tone' => 'formal',
            'glossary' => [['term' => 'Big Mac'], ['term' => 'Coke', 'translation' => 'كوكاكولا']],
        ]);

        $this->assertStringContainsString('Keep these terms unchanged', $captured);
        $this->assertStringContainsString('Big Mac', $captured);
        $this->assertStringContainsString('كوكاكولا', $captured);
        $this->assertStringContainsString('formal tone', $captured);
    }

    public function test_second_call_is_served_from_cache(): void
    {
        $this->fakeEcho('AR:');
        $first = app(TranslatorService::class)->translateBatch(['a' => ['title' => 'Pizza']], 'Arabic', ['userId' => 1]);
        $this->assertSame('AR:Pizza', $first['a']['title']);

        Http::fake(['*' => Http::response('boom', 500)]); // must not hit network
        $second = app(TranslatorService::class)->translateBatch(['a' => ['title' => 'Pizza']], 'Arabic', ['userId' => 1]);
        $this->assertSame('AR:Pizza', $second['a']['title']);
    }
}
