<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services\Drivers;

use Juwri\Modules\Ai\Services\AiImageResult;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderInterface;
use Juwri\Modules\Ai\Services\AiResult;

/**
 * Driver for the Anthropic Messages API. Dormant unless ANTHROPIC_API_KEY is set.
 */
class AnthropicProvider implements AiProviderInterface
{
    public function __construct(protected string $name, protected array $config)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['api_key']);
    }

    public function supportsVision(): bool
    {
        return (bool) ($this->config['supports_vision'] ?? true);
    }

    public function chat(array $messages, array $options = []): AiResult
    {
        if (!$this->isConfigured()) {
            throw new AiProviderException("Provider [{$this->name}] has no API key configured.", $this->name, false);
        }

        // P1-15: the HTTP call runs through laravel/ai's Anthropic provider. Every system message becomes the
        // top-level system field; strict JSON is nudged via the system prompt (Anthropic has no json_object flag).
        return LaravelAiChat::run(
            $this->name,
            LaravelAiChat::ANTHROPIC,
            ['url' => $this->config['base_url'], 'key' => $this->config['api_key'], 'model' => $this->config['model'], 'version' => $this->config['version'] ?? '2023-06-01'],
            $messages,
            $this->supportsVision(),
            !empty($options['json']) ? 'Respond with a single valid JSON object and nothing else.' : null,
            (int) ($options['max_tokens'] ?? 4096),
            null,
        );
    }

    public function supportsImageGeneration(): bool
    {
        return false;
    }

    public function generateImage(string $prompt, array $options = []): AiImageResult
    {
        throw new AiProviderException("Provider [{$this->name}] cannot generate images.", $this->name, false);
    }

    /** ZERO-TOUCH Track B: the Messages API has no speech-to-text endpoint. */
    public function supportsTranscription(): bool
    {
        return false;
    }

    public function transcribe(string $audioPath, array $options = []): \Juwri\Modules\Ai\Services\TranscriptionResult
    {
        throw new AiProviderException("Provider [{$this->name}] cannot transcribe audio.", $this->name, false);
    }
}
