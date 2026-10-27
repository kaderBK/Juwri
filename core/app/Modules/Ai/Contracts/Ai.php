<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Contracts;

use Juwri\Modules\Ai\Services\AiImageResult;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Ai\Services\TranscriptionResult;

/**
 * P1-08 L1 public surface: the AI entry point for modules above Ai (Menu). Implemented by
 * {@see AiService} (spend guard, provider chain, usage metering included). Limit errors
 * throw {@see AiLimitExceeded}, provider errors {@see AiProviderFailure}.
 */
interface Ai
{
    /**
     * A JSON answer from the provider chain, checked by $validator.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     */
    public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array;

    /**
     * A free-text completion across the fallback chain (same spend guard and metering as json()).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'general'): string;

    /** @param  array<string, mixed>  $options */
    public function generateImage(string $prompt, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'image_gen'): AiImageResult;

    /**
     * Speech-to-text through the store's transcription chain (same spend guard and metering as json()).
     *
     * @param  array<string, mixed>  $options
     */
    public function transcribe(string $audioPath, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'transcription'): TranscriptionResult;
}
