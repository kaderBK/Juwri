<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services\Drivers;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * P1-15: the laravel/ai agent for one AiProviderInterface::chat() call — the instructions (system prompt), the
 * token / temperature settings and the provider-specific body options the driver resolved (JSON mode). No tools, no
 * conversation memory: the caller's messages are the whole history.
 */
final class ChatAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /** @param  array<string, mixed>  $providerOptions */
    public function __construct(
        private readonly string $instructions,
        private readonly ?int $maxTokens = null,
        private readonly ?float $temperature = null,
        private readonly array $providerOptions = [],
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
