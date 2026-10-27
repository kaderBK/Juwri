<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

/**
 * ZERO-TOUCH Track B: normalised result of a single speech-to-text call
 * (OpenAI-compatible /audio/transcriptions). durationSeconds comes from the
 * provider's verbose_json response when available and is the billing unit
 * recorded on the ai_usage row (audio_seconds).
 */
class TranscriptionResult
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?string $language = null,
        public ?float $durationSeconds = null,
    ) {
    }
}
