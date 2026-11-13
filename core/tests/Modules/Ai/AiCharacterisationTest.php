<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use App\Services\Ai\E2eStubAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Ai\Services\AiLimitExceededException;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiResult;
use Juwri\Modules\Ai\Services\AiService;
use Tests\TestCase;

/**
 * P1-15 step 1 — characterisation of the AI layer before the drivers move onto laravel/ai. No real AI call: every
 * provider is an Http::fake. Pinned against snapshots in tests/Fixtures/ai/ (AI_SNAPSHOT=write regenerates):
 *  - the wire request each driver sends (URL, auth headers, JSON body — keys sorted, so only content counts) and
 *    the AiResult it returns, for the OpenAI-compatible driver (system/user, JSON mode, vision, kimi's pinned
 *    temperature + output floor) and the Anthropic driver (top-level system, JSON nudge, default max_tokens, image);
 *  - every provider error: exception class, message, retryable flag (5xx / 429 / 4xx / connection / empty answer /
 *    non-JSON body);
 *  - money: ai_usage rows and the ai_credits meter per call (1 credit per successful call — never for a failed
 *    provider, never twice for a fallback or a JSON retry), chain order + fallback, the monthly limit refusing before
 *    any HTTP call;
 *  - the E2E stub's deterministic answers.
 * AgentSafety, PiiRedactor and AiSpendGuard keep their own suites (AgentSafetyTest, AgentPiiRedactorTest,
 * AiSpendGuardTest); they sit above the drivers and are unchanged by the move.
 */
class AiCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    /** The current fake provider answer (Http::fake stubs accumulate, so one stub delegates to this). */
    private ?\Closure $responder = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn (Request $r) => ($this->responder ?? fn () => throw new \RuntimeException('no fake set'))($r));
        config([
            'ai.default' => 'mimo',
            'ai.fallback' => ['kimi', 'anthropic'],
            'ai.vision' => ['openai', 'anthropic'],
            'ai.retries' => 1,
            'ai.timeout' => 60,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1', 'api_key' => 'k-mimo',
                'model' => 'mimo-v2.5-pro', 'supports_json' => true, 'supports_vision' => false,
            ],
            'ai.providers.kimi' => [
                'driver' => 'openai', 'base_url' => 'https://api.moonshot.ai/v1', 'api_key' => 'k-kimi',
                'model' => 'kimi-k2.5', 'temperature' => 1, 'min_output_tokens' => 1024,
                'supports_json' => true, 'supports_vision' => false,
            ],
            'ai.providers.openai' => [
                'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1/', 'api_key' => 'k-openai',
                'model' => 'gpt-4o', 'supports_json' => true, 'supports_vision' => true,
            ],
            'ai.providers.deepseek' => [
                'driver' => 'openai', 'base_url' => 'https://api.deepseek.com', 'api_key' => 'k-ds',
                'model' => 'deepseek-chat', 'supports_json' => false, 'supports_vision' => false,
            ],
            'ai.providers.anthropic' => [
                'driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1', 'api_key' => 'k-ant',
                'model' => 'claude-sonnet-4-6', 'version' => '2023-06-01', 'supports_json' => true, 'supports_vision' => true,
            ],
            // AiSpendGuard default ceiling stays as configured: these tests pin what the drivers send for it.
        ]);
    }

    private function snapshot(string $name, mixed $actual): void
    {
        $path = base_path('tests/Fixtures/ai/'.$name.'.json');
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (getenv('AI_SNAPSHOT') === 'write') {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $json);
        }
        $this->assertFileExists($path, "snapshot {$name} missing — run with AI_SNAPSHOT=write");
        $this->assertSame(file_get_contents($path), $json, "{$name} drifted from the characterisation snapshot");
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $out = array_map(self::sortKeys(...), $v);
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    /** What reached the wire: URL, method, the auth/protocol headers and the JSON body (keys sorted). */
    private function wire(Request $r): array
    {
        $headers = [];
        foreach (['Authorization', 'x-api-key', 'anthropic-version', 'anthropic-beta', 'Content-Type', 'Accept'] as $h) {
            if ($r->hasHeader($h)) {
                $headers[$h] = $r->header($h)[0];
            }
        }

        return ['method' => $r->method(), 'url' => $r->url(), 'headers' => $headers, 'body' => self::sortKeys(json_decode($r->body(), true))];
    }

    private static function openAiBody(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7]];
    }

    private static function anthropicBody(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 13, 'output_tokens' => 5]];
    }

    private static function resultRow(AiResult $r): array
    {
        return ['text' => $r->text, 'provider' => $r->provider, 'model' => $r->model, 'prompt' => $r->promptTokens, 'completion' => $r->completionTokens];
    }

    /** @return array<string, mixed> */
    private function chatCase(string $provider, array $messages, array $options, array $fakeBody): array
    {
        $recorded = [];
        $this->responder = (function (Request $r) use (&$recorded, $fakeBody) {
            $recorded[] = $this->wire($r);

            return Http::response($fakeBody, 200);
        });
        $result = app(AiProviderFactory::class)->make($provider)->chat($messages, $options);

        return ['request' => $recorded, 'result' => self::resultRow($result)];
    }

    public function test_driver_requests_and_results_are_pinned(): void
    {
        $sys = ['role' => 'system', 'content' => 'You extract menus.'];
        $user = ['role' => 'user', 'content' => 'Kabsa 30'];
        $img = ['role' => 'user', 'content' => 'Read this photo', 'image' => 'data:image/png;base64,iVBORw0KGgo='];
        $raw = ['role' => 'user', 'content' => 'Raw photo', 'image' => 'QUJD'];
        $history = [$sys, ['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => '{"tool":"search_menu"}'], ['role' => 'user', 'content' => 'TOOL_RESULT: []']];

        $out = [
            'openai_plain' => $this->chatCase('mimo', [$sys, $user], ['max_tokens' => 2048], self::openAiBody('plain answer')),
            'openai_json' => $this->chatCase('mimo', [$sys, $user], ['json' => true, 'max_tokens' => 2048, 'temperature' => 0.5], self::openAiBody('{"a":1}')),
            'openai_json_unsupported' => $this->chatCase('deepseek', [$sys, $user], ['json' => true], self::openAiBody('{"a":1}')),
            'openai_history' => $this->chatCase('mimo', $history, [], self::openAiBody('ok')),
            'kimi_pinned_temperature_and_floor' => $this->chatCase('kimi', [$sys, $user], ['max_tokens' => 300, 'temperature' => 0.2], self::openAiBody('k')),
            'openai_vision_data_url' => $this->chatCase('openai', [$sys, $img], ['json' => true, 'max_tokens' => 100], self::openAiBody('{"items":[]}')),
            'openai_vision_raw_base64' => $this->chatCase('openai', [$raw], [], self::openAiBody('seen')),
            'openai_image_ignored_without_vision' => $this->chatCase('mimo', [$img], [], self::openAiBody('no eyes')),
            'anthropic_plain' => $this->chatCase('anthropic', [$sys, $user], [], self::anthropicBody('claude says')),
            'anthropic_json' => $this->chatCase('anthropic', [$sys, $user], ['json' => true, 'max_tokens' => 2048], self::anthropicBody('{"a":1}')),
            'anthropic_no_system' => $this->chatCase('anthropic', [$user], ['max_tokens' => 10], self::anthropicBody('x')),
            'anthropic_vision' => $this->chatCase('anthropic', [$sys, $img, $raw], [], self::anthropicBody('pic')),
            'anthropic_history' => $this->chatCase('anthropic', $history, ['max_tokens' => 64], self::anthropicBody('ok')),
        ];

        $this->snapshot('driver-chat', $out);
    }

    /** @return array<string, mixed> */
    private function errorCase(string $provider, callable $fake): array
    {
        $this->responder = $fake;
        try {
            app(AiProviderFactory::class)->make($provider)->chat([['role' => 'user', 'content' => 'hi']]);

            return ['thrown' => false];
        } catch (AiProviderException $e) {
            return ['class' => $e::class, 'message' => $e->getMessage(), 'retryable' => $e->retryable, 'provider' => $e->provider];
        }
    }

    public function test_driver_errors_are_pinned(): void
    {
        $out = [];
        foreach (['mimo', 'anthropic'] as $p) {
            $out[$p] = [
                'http_500' => $this->errorCase($p, fn () => Http::response('upstream boom', 500)),
                'http_503' => $this->errorCase($p, fn () => Http::response(['error' => ['message' => 'overloaded']], 503)),
                'http_429' => $this->errorCase($p, fn () => Http::response(['error' => ['message' => 'slow down']], 429)),
                'http_400' => $this->errorCase($p, fn () => Http::response(['error' => ['message' => 'bad request']], 400)),
                'http_401' => $this->errorCase($p, fn () => Http::response(['error' => ['message' => 'bad key']], 401)),
                'http_402' => $this->errorCase($p, fn () => Http::response(['error' => ['message' => 'no credits']], 402)),
                'connection' => $this->errorCase($p, fn () => throw new ConnectionException('cURL error 28: timed out')),
                'empty_answer' => $this->errorCase($p, fn () => Http::response($p === 'anthropic' ? self::anthropicBody('') : self::openAiBody(''), 200)),
                'not_json_body' => $this->errorCase($p, fn () => Http::response('not json at all', 200)),
            ];
        }
        $out['unconfigured'] = (function () {
            config(['ai.providers.mimo.api_key' => null]);

            return $this->errorCase('mimo', fn () => Http::response(self::openAiBody('never'), 200));
        })();

        $this->snapshot('driver-errors', $out);
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    /** @return array{usage: list<array<string, mixed>>, credits: int, http: int} */
    private function ledger(int $userId, int $httpCalls): array
    {
        return [
            'usage' => AiUsage::orderBy('id')->get(['user_id', 'provider', 'model', 'feature', 'prompt_tokens', 'completion_tokens', 'fell_back', 'audio_seconds'])
                ->map(fn ($r) => ['user' => $r->user_id === $userId ? 'owner' : $r->user_id] + array_diff_key($r->toArray(), ['user_id' => 1]))->all(),
            'credits' => (int) TenantUsageDaily::where('user_id', $userId)->where('metric', 'ai_credits')->sum('value'),
            'http' => $httpCalls,
        ];
    }

    public function test_metering_and_credit_deduction_per_call_are_pinned(): void
    {
        $user = $this->owner();
        $ai = app(AiService::class);
        $out = [];
        $calls = 0;

        // 1. A plain completion on the default provider: one row, one credit.
        $this->responder = (function () use (&$calls) {
            $calls++;

            return Http::response(self::openAiBody('hello'), 200);
        });
        $out['complete_default'] = ['text' => $ai->complete([['role' => 'user', 'content' => 'hi']], userId: $user->id, feature: 'unit')] + $this->ledger($user->id, $calls);

        // 2. Default 500 -> fallback serves: one row (fell_back), one more credit — the failed provider costs nothing.
        AiUsage::query()->delete();
        TenantUsageDaily::query()->delete();
        $calls = 0;
        $this->responder = (function (Request $r) use (&$calls) {
            $calls++;

            return str_contains($r->url(), 'xiaomimimo') ? Http::response('boom', 500) : Http::response(self::openAiBody('from kimi'), 200);
        });
        $out['complete_fallback'] = ['text' => $ai->complete([['role' => 'user', 'content' => 'hi']], userId: $user->id, feature: 'unit')] + $this->ledger($user->id, $calls);

        // 3. Chain walks to the Anthropic driver when both OpenAI-compatible providers fail.
        AiUsage::query()->delete();
        TenantUsageDaily::query()->delete();
        $calls = 0;
        $order = [];
        $this->responder = (function (Request $r) use (&$calls, &$order) {
            $calls++;
            $order[] = parse_url($r->url(), PHP_URL_HOST);

            return str_contains($r->url(), 'anthropic') ? Http::response(self::anthropicBody('claude'), 200) : Http::response('x', 502);
        });
        $out['complete_to_anthropic'] = ['text' => $ai->complete([['role' => 'user', 'content' => 'hi']], userId: $user->id, feature: 'unit'), 'order' => $order] + $this->ledger($user->id, $calls);

        // 4. JSON: malformed then valid on the same provider (retry-with-error): two HTTP calls, ONE row, ONE credit.
        AiUsage::query()->delete();
        TenantUsageDaily::query()->delete();
        $calls = 0;
        $bodies = [];
        $this->responder = (function (Request $r) use (&$calls, &$bodies) {
            $calls++;
            $bodies[] = array_column(json_decode($r->body(), true)['messages'], 'content');

            return Http::response(self::openAiBody($calls === 1 ? 'not json' : '{"ok":true}'), 200);
        });
        $out['json_retry'] = ['decoded' => $ai->json([['role' => 'user', 'content' => 'give json']], userId: $user->id, feature: 'unit'), 'messages_sent' => $bodies] + $this->ledger($user->id, $calls);

        // 5. JSON validator rejects the default twice -> fallback: one row, one credit.
        AiUsage::query()->delete();
        TenantUsageDaily::query()->delete();
        $calls = 0;
        $this->responder = (function (Request $r) use (&$calls) {
            $calls++;

            return Http::response(self::openAiBody(str_contains($r->url(), 'xiaomimimo') ? '{"wrong":1}' : '{"categories":[]}'), 200);
        });
        $out['json_validator_fallback'] = ['decoded' => $ai->json([['role' => 'user', 'content' => 'menu']], validator: fn ($d) => isset($d['categories']), userId: $user->id, feature: 'unit')] + $this->ledger($user->id, $calls);

        // 6. Every provider fails: nothing metered, no credit.
        AiUsage::query()->delete();
        TenantUsageDaily::query()->delete();
        $calls = 0;
        $this->responder = (function () use (&$calls) {
            $calls++;

            return Http::response('down', 500);
        });
        try {
            $ai->complete([['role' => 'user', 'content' => 'hi']], userId: $user->id, feature: 'unit');
            $thrown = null;
        } catch (AiProviderException $e) {
            $thrown = ['message' => $e->getMessage(), 'retryable' => $e->retryable, 'provider' => $e->provider];
        }
        $out['all_fail'] = ['thrown' => $thrown] + $this->ledger($user->id, $calls);

        // 7. Monthly limit reached: refused before any HTTP call, nothing metered.
        AiUsage::query()->delete();
        TenantUsageDaily::query()->delete();
        config(['ai.monthly_call_limit' => 1]);
        AiUsage::create(['user_id' => $user->id, 'provider' => 'mimo', 'model' => 'm', 'feature' => 'seed', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'fell_back' => false]);
        $calls = 0;
        $this->responder = (function () use (&$calls) {
            $calls++;

            return Http::response(self::openAiBody('never'), 200);
        });
        try {
            $ai->complete([['role' => 'user', 'content' => 'hi']], userId: $user->id);
            $limited = false;
        } catch (AiLimitExceededException) {
            $limited = true;
        }
        $out['monthly_limit'] = ['refused' => $limited] + $this->ledger($user->id, $calls);
        config(['ai.monthly_call_limit' => 0]);

        // 8. No user (system jobs): metered row without a credit.
        AiUsage::query()->delete();
        TenantUsageDaily::query()->delete();
        $calls = 0;
        $this->responder = (function () use (&$calls) {
            $calls++;

            return Http::response(self::openAiBody('sys'), 200);
        });
        $out['no_user'] = ['text' => $ai->complete([['role' => 'user', 'content' => 'hi']], feature: 'system')] + $this->ledger($user->id, $calls);
        $out['no_user']['rows_without_user'] = AiUsage::whereNull('user_id')->count();

        $this->snapshot('metering', $out);
    }

    /**
     * P1-15 step 3: every service that parses an AI answer as JSON goes through AiService::json() (json_object mode
     * on OpenAI-compatible providers, the system nudge on Anthropic, then decodeJson + the caller's validator). The
     * same fake answers must give the same parsed shapes through each driver — fenced, prose-wrapped, nested,
     * unicode, arrays at the top, and answers that only parse after the retry.
     */
    public function test_json_answers_parse_to_the_same_shapes(): void
    {
        $answers = [
            'plain' => '{"categories":[{"name":"Mains","items":[{"name":"Kabsa","price":30}]}]}',
            'fenced' => "```json\n{\"title\":\"Hello\",\"tags\":[\"a\",\"b\"]}\n```",
            'fenced_no_lang' => "```\n{\"ok\":true}\n```",
            'prose_wrapped' => 'Sure! Here is the JSON you asked for: {"name":"Bio","links":[]} — hope it helps.',
            'unicode' => '{"ar":"كبسة","emoji":"🍛","escaped":"é"}',
            'top_level_list' => '[{"a":1},{"a":2}]',
            'nested_numbers' => '{"price":12.50,"qty":0,"neg":-1,"big":12345678901,"null":null,"bool":false}',
        ];
        $out = [];
        foreach (['mimo' => 'openai', 'anthropic' => 'anthropic'] as $provider => $shape) {
            config(['ai.default' => $provider, 'ai.fallback' => []]);
            foreach ($answers as $case => $text) {
                $this->responder = fn () => Http::response($shape === 'anthropic' ? self::anthropicBody($text) : self::openAiBody($text), 200);
                $out[$case][$provider] = app(AiService::class)->json([['role' => 'user', 'content' => 'json please']], feature: 'unit');
            }
            // Not JSON first, JSON on the retry (the error is echoed back to the model).
            $n = 0;
            $this->responder = function () use (&$n, $shape) {
                $text = ++$n === 1 ? 'I cannot do JSON today' : '{"second":"try"}';

                return Http::response($shape === 'anthropic' ? self::anthropicBody($text) : self::openAiBody($text), 200);
            };
            $out['after_retry'][$provider] = app(AiService::class)->json([['role' => 'user', 'content' => 'json please']], feature: 'unit');
        }
        foreach ($out as $case => $byProvider) {
            $this->assertSame($byProvider['mimo'], $byProvider['anthropic'], "{$case}: both drivers parse alike");
        }

        $this->snapshot('json-parsing', $out);
    }

    public function test_e2e_stub_answers_are_pinned(): void
    {
        $stub = new E2eStubAiService(app(AiProviderFactory::class));
        $this->responder = (fn () => throw new \RuntimeException('the stub must never call a provider'));

        $this->snapshot('e2e-stub', [
            'complete' => $stub->complete([['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => 'Write a bio for Kabsa House']]),
            'json' => $stub->json([['role' => 'user', 'content' => 'x']]),
            'json_validated' => $stub->json([['role' => 'user', 'content' => 'x']], validator: fn ($d) => is_array($d)),
            'metered' => AiUsage::count(),
        ]);
    }
}
