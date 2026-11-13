<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services\Drivers;

use Juwri\Core\Models\Store;
use Juwri\Core\Services\HostedServices\HostedServiceClient;
use Juwri\Modules\Ai\Services\AiImageResult;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderInterface;
use Juwri\Modules\Ai\Services\AiResult;
use Juwri\Modules\Ai\Services\TranscriptionResult;

/**
 * Self-hosted installations: AI through juwri's hosted AI credits proxy (POST api/hosted/v1/ai/complete, behind
 * `hosted.gate:ai`), paid with the installation's juwri AI credits — no provider key on this server. Every call is
 * signed and names the store using the credits (HostedServiceClient::send). A refusal (store past the edition's limit,
 * no credits, licence problem) is shown with juwri's own message and NOT retried elsewhere: the store keeps trading,
 * only the AI feature is unavailable. Text only.
 *
 * Switch on: AI_DEFAULT=juwri_hosted (after `php artisan juwri:services:connect`).
 */
class JuwriHostedProvider implements AiProviderInterface
{
    /** @param  array<string, mixed>  $config */
    public function __construct(protected string $name, protected array $config, protected ?int $storeId = null) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['enabled']) && app(HostedServiceClient::class)->connected();
    }

    public function supportsVision(): bool
    {
        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): AiResult
    {
        $store = $this->storeId !== null ? Store::query()->find($this->storeId) : null;
        if ($store === null) {
            throw new AiProviderException(___('juwri AI credits are used by a store: this call has none.'), $this->name, false);
        }
        $payload = ['messages' => array_map(fn ($m) => ['role' => (string) ($m['role'] ?? 'user'), 'content' => is_string($m['content'] ?? null) ? $m['content'] : ''], $messages)];
        $opts = array_intersect_key($options, array_flip(['max_tokens', 'temperature', 'json']));
        if ($opts !== []) {
            $payload['options'] = $opts;
        }
        try {
            $res = app(HostedServiceClient::class)->send('POST', '/api/hosted/v1/ai/complete', $payload, $store);
        } catch (\Throwable $e) {
            throw new AiProviderException('juwri AI service unreachable: '.substr($e->getMessage(), 0, 200), $this->name, true, $e);
        }
        if (! $res->successful()) {
            // juwri's answer (refused store, no credits, …) as it is — never served some other way
            throw new AiProviderException((string) ($res->json('message') ?: ___('The AI service is not available right now.')), $this->name, $res->status() >= 500);
        }

        return new AiResult(text: (string) $res->json('text'), provider: $this->name, model: 'juwri-hosted');
    }

    public function supportsImageGeneration(): bool
    {
        return false;
    }

    /** @param  array<string, mixed>  $options */
    public function generateImage(string $prompt, array $options = []): AiImageResult
    {
        throw new AiProviderException("Provider [{$this->name}] cannot generate images.", $this->name, false);
    }

    public function supportsTranscription(): bool
    {
        return false;
    }

    /** @param  array<string, mixed>  $options */
    public function transcribe(string $audioPath, array $options = []): TranscriptionResult
    {
        throw new AiProviderException("Provider [{$this->name}] cannot transcribe audio.", $this->name, false);
    }
}
