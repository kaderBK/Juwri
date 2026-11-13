<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

/**
 * Normalised result of a single image-generation call. Carries the raw decoded
 * image bytes so the caller can hand them straight to MediaCopyService.
 */
class AiImageResult
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $provider,
        public string $model,
    ) {
    }
}
