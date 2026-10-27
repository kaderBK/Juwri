<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Blog\Autopilot;

use Juwri\Modules\Ai\Services\AiImageResult;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiService;

/**
 * Blog Autopilot A1: in-test AiService fake. NEVER makes a network call.
 *
 * Canned responses are routed by prompt marker (see PromptMarks — matched as a
 * substring anywhere in the call's messages, registration order wins) with a
 * FIFO queue fallback for order-keyed scripting. json() runs the caller's
 * validator exactly like the real service (a rejected payload throws
 * AiProviderFailure), so validator logic is exercised for real. Every call
 * is recorded on $calls for assertions (method, feature, messages, options).
 *
 * Lives in app/ next to the engine (precedent: FakeZatcaClient,
 * FakeDoorDashClient, ...) so both Blog Autopilot test suites can autoload it.
 */
class FakeAutopilotAi extends AiService
{
    /** 1x1 transparent PNG — valid bytes for the real storage pipeline. */
    public const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array<int, array{method:string, feature:string, messages:array<array-key, mixed>, options:array<array-key, mixed>, userId:int|null, prompt?:string}> */
    public array $calls = [];

    /** @var array<string, array<int, array<array-key, mixed>>> marker => queue of JSON responses */
    protected array $markerJson = [];

    /** @var array<string, array<int, string>> marker => queue of text responses */
    protected array $markerText = [];

    /** @var array<int, array<array-key, mixed>> */
    protected array $jsonQueue = [];

    /** @var array<int, string> */
    protected array $textQueue = [];

    protected bool $imagesFail = false;

    protected bool $allFail = false;

    public function __construct()
    {
        parent::__construct(new AiProviderFactory);
    }

    // ---------------------------------------------------------- scripting

    /**
     * Queue a JSON response for calls whose messages contain $marker.
     *
     * @param  array<array-key, mixed>  $response
     */
    public function onJson(string $marker, array $response): static
    {
        $this->markerJson[$marker][] = $response;

        return $this;
    }

    /** Queue a text response for calls whose messages contain $marker. */
    public function onText(string $marker, string $response): static
    {
        $this->markerText[$marker][] = $response;

        return $this;
    }

    /**
     * Order-keyed fallback: next json() call without a marker match.
     *
     * @param  array<array-key, mixed>  $response
     */
    public function pushJson(array $response): static
    {
        $this->jsonQueue[] = $response;

        return $this;
    }

    /** Order-keyed fallback: next complete() call without a marker match. */
    public function pushText(string $response): static
    {
        $this->textQueue[] = $response;

        return $this;
    }

    /** Make generateImage() fail (image failure must be non-fatal). */
    public function failImages(bool $fail = true): static
    {
        $this->imagesFail = $fail;

        return $this;
    }

    /** Make every call fail (unrecoverable provider outage). */
    public function failAll(bool $fail = true): static
    {
        $this->allFail = $fail;

        return $this;
    }

    /**
     * Calls made for one method name ('json'|'complete'|'generateImage').
     *
     * @return list<array{method:string, feature:string, messages:array<array-key, mixed>, options:array<array-key, mixed>, userId:int|null, prompt?:string}>
     */
    public function callsFor(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }

    // ------------------------------------------------------------- fakes

    /**
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $options
     */
    public function complete(array $messages, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'general'): string
    {
        $this->record('complete', $feature, $messages, $options, null, $userId);
        $this->guardSystemRole($messages);

        if ($this->allFail) {
            throw new AiProviderException('FakeAutopilotAi: forced failure.', 'fake', false);
        }

        $haystack = $this->haystack($messages);
        foreach ($this->markerText as $marker => &$queue) {
            if ($queue !== [] && str_contains($haystack, $marker)) {
                return array_shift($queue);
            }
        }
        unset($queue);

        if ($this->textQueue !== []) {
            return array_shift($this->textQueue);
        }

        throw new AiProviderException('FakeAutopilotAi: no canned text response for: '.mb_substr($haystack, 0, 200), 'fake', false);
    }

    /**
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $options
     * @return array<array-key, mixed>
     */
    public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
    {
        $this->record('json', $feature, $messages, $options, null, $userId);
        $this->guardSystemRole($messages);

        if ($this->allFail) {
            throw new AiProviderException('FakeAutopilotAi: forced failure.', 'fake', false);
        }

        $haystack = $this->haystack($messages);
        $response = null;
        foreach ($this->markerJson as $marker => &$queue) {
            if ($queue !== [] && str_contains($haystack, $marker)) {
                $response = array_shift($queue);
                break;
            }
        }
        unset($queue);

        if ($response === null && $this->jsonQueue !== []) {
            $response = array_shift($this->jsonQueue);
        }

        if ($response === null) {
            throw new AiProviderException('FakeAutopilotAi: no canned JSON response for: '.mb_substr($haystack, 0, 200), 'fake', false);
        }

        // Mirror the real service: a validator rejection is a provider failure.
        if ($validator && ! $validator($response)) {
            throw new AiProviderException('JSON failed validation from fake', 'fake', false);
        }

        return $response;
    }

    /** @param array<array-key, mixed> $options */
    public function generateImage(string $prompt, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'image_gen'): AiImageResult
    {
        $this->record('generateImage', $feature, [['role' => 'user', 'content' => $prompt]], $options, $prompt, $userId);

        if ($this->allFail || $this->imagesFail) {
            throw new AiProviderException('FakeAutopilotAi: forced image failure.', 'fake', false);
        }

        return new AiImageResult(
            bytes: (string) base64_decode(self::PNG_1PX, true),
            mime: 'image/png',
            provider: 'fake',
            model: 'fake-image-1',
        );
    }

    // ----------------------------------------------------------- helpers

    /**
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $options
     */
    protected function record(string $method, string $feature, array $messages, array $options, ?string $prompt = null, ?int $userId = null): void
    {
        $call = ['method' => $method, 'feature' => $feature, 'messages' => $messages, 'options' => $options, 'userId' => $userId];
        if ($prompt !== null) {
            $call['prompt'] = $prompt;
        }
        $this->calls[] = $call;
    }

    /** @param array<array-key, mixed> $messages */
    protected function haystack(array $messages): string
    {
        return implode("\n", array_map(fn ($m) => (string) ($m['content'] ?? ''), $messages));
    }

    /**
     * Injection-guard tripwire (SPEC hard rule 11): untrusted content markers
     * must never appear in a SYSTEM-role message. The engine wraps untrusted
     * strings in <<<DATA sentinels; finding one in a system message is a bug.
     */
    /** @param array<array-key, mixed> $messages */
    protected function guardSystemRole(array $messages): void
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system' && str_contains((string) ($m['content'] ?? ''), '<<<DATA')) {
                throw new \LogicException('Untrusted content leaked into a system-role message.');
            }
        }
    }
}
