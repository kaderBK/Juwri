<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiResult;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\AnthropicProvider as SdkAnthropicProvider;
use Laravel\Ai\Providers\OpenAiCompatibleProvider as SdkOpenAiCompatibleProvider;
use Throwable;

/**
 * P1-15: one chat completion through a laravel/ai provider, mapped to and from the AiProviderInterface contract.
 *
 * In: OpenAI-style messages (`role` system|user|assistant, `content`, optional `image` on a user message). Leading
 * system messages become the agent instructions; the last user message is the prompt (with its image attached);
 * everything between is the history. Out: an AiResult with the provider's usage, or an AiProviderException with the
 * same message / retryable flag the hand-rolled drivers produced (5xx + 429 + connection + empty answer retryable;
 * other 4xx not) — AiService's chain, JSON retry and metering read exactly those.
 */
final class LaravelAiChat
{
    public const OPENAI_COMPATIBLE = 'openai-compatible';

    public const ANTHROPIC = 'anthropic';

    /**
     * @param  array<string, mixed>  $sdkConfig  url, key, model (+ version for Anthropic)
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $providerOptions
     */
    public static function run(
        string $name,
        string $driver,
        array $sdkConfig,
        array $messages,
        bool $vision,
        ?string $extraInstructions,
        ?int $maxTokens,
        ?float $temperature,
        array $providerOptions = [],
    ): AiResult {
        $provider = self::provider($name, $driver, $sdkConfig);
        [$instructions, $history, $prompt, $attachments] = self::split($messages, $vision, $driver);
        if ($extraInstructions !== null) {
            $instructions = trim($instructions."\n".$extraInstructions);
        }
        $model = (string) ($sdkConfig['model'] ?? '');

        try {
            $response = $provider->prompt(new AgentPrompt(
                agent: new ChatAgent($instructions, $maxTokens, $temperature, $providerOptions),
                prompt: $prompt,
                attachments: $attachments,
                provider: $provider,
                model: $model,
                timeout: (int) config('ai.timeout', 60),
                messages: $history,
            ));
        } catch (Throwable $e) {
            throw self::failure($name, $e);
        }

        if ($response->text === '') {
            throw new AiProviderException("Provider [{$name}] returned no content.", $name, true);
        }

        return new AiResult(
            text: $response->text,
            provider: $name,
            model: $model,
            promptTokens: (int) $response->usage->inputTokens,
            completionTokens: (int) $response->usage->outputTokens,
        );
    }

    /** @param  array<string, mixed>  $sdkConfig */
    private static function provider(string $name, string $driver, array $sdkConfig): TextProvider
    {
        $events = app(Dispatcher::class);
        $config = [
            'name' => $name,
            'driver' => $driver,
            'key' => $sdkConfig['key'] ?? null,
            'url' => rtrim((string) ($sdkConfig['url'] ?? ''), '/'),
            'models' => ['text' => ['default' => (string) ($sdkConfig['model'] ?? '')]],
            // The hand-rolled drivers always sent Accept: application/json.
            'headers' => ['Accept' => 'application/json'],
        ];

        if ($driver === self::ANTHROPIC) {
            $config['version'] = $sdkConfig['version'] ?? '2023-06-01';
            $config['anthropic_beta'] = ''; // no beta header (the SDK default opts into web-fetch)

            return new SdkAnthropicProvider(new AnthropicGateway($events), $config, $events);
        }

        return new SdkOpenAiCompatibleProvider($config, $events);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{0: string, 1: list<UserMessage|AssistantMessage>, 2: string, 3: list<Base64Image|RemoteImage>}
     */
    private static function split(array $messages, bool $vision, string $driver): array
    {
        $system = [];
        $turns = [];
        foreach (array_values($messages) as $i => $m) {
            $role = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');
            // Anthropic takes every system message as the top-level system field (as before); for an
            // OpenAI-compatible provider the system messages lead the conversation in practice.
            if ($role === 'system' && ($driver === self::ANTHROPIC || $turns === [])) {
                $system[] = $content;

                continue;
            }
            $image = $vision && ! empty($m['image']) ? self::image((string) $m['image'], $driver) : null;
            $turns[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content, 'attachments' => $image ? [$image] : []];
        }

        $instructions = $driver === self::ANTHROPIC ? trim(implode("\n", $system)) : implode("\n", $system);

        $last = array_pop($turns) ?? ['role' => 'user', 'content' => '', 'attachments' => []];
        if ($last['role'] !== 'user') {
            $turns[] = $last;
            $last = ['role' => 'user', 'content' => '', 'attachments' => []];
        }

        $history = array_map(
            fn (array $t) => $t['role'] === 'assistant' ? new AssistantMessage($t['content']) : new UserMessage($t['content'], $t['attachments']),
            $turns,
        );

        return [$instructions, $history, $last['content'], $last['attachments']];
    }

    /** A data URL / raw base64 (JPEG assumed, as before) / http(s) URL as an SDK image. */
    private static function image(string $image, string $driver): Base64Image|RemoteImage
    {
        if (preg_match('#^data:(?<type>[^;]+);base64,(?<data>.+)$#s', $image, $m)) {
            return new Base64Image($m['data'], $m['type']);
        }
        if ($driver === self::OPENAI_COMPATIBLE && str_starts_with($image, 'http')) {
            return new RemoteImage($image);
        }

        return new Base64Image($image, 'image/jpeg');
    }

    /** The hand-rolled drivers' error contract, from whatever the SDK raised. */
    private static function failure(string $name, Throwable $e): AiProviderException
    {
        $cause = $e;
        while ($cause !== null && ! $cause instanceof RequestException && ! $cause instanceof ConnectionException) {
            $cause = $cause->getPrevious();
        }

        if ($cause instanceof RequestException && $cause->response !== null) {
            $status = $cause->response->status();

            return new AiProviderException(
                "Provider [{$name}] HTTP {$status}: ".$cause->response->body(),
                $name,
                $status === 429 || $status >= 500,
                $e,
            );
        }

        if ($cause instanceof ConnectionException) {
            return new AiProviderException("Provider [{$name}] connection error: {$cause->getMessage()}", $name, true, $e);
        }

        // The call went through but the answer is unusable (an error envelope, a body that is not JSON, an
        // unexpected shape): no content, retryable — as before.
        return new AiProviderException("Provider [{$name}] returned no content.", $name, true, $e);
    }
}
