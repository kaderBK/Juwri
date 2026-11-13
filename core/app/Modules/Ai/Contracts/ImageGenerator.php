<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Contracts;

use Juwri\Modules\Ai\Services\Studio\GeminiImageProvider;

/** P1-08 L1 public surface: the image model used for menu photos. Implemented by {@see GeminiImageProvider}. */
interface ImageGenerator
{
    public function isConfigured(): bool;

    /**
     * @param  list<string>  $refImagesB64
     * @return string the image bytes
     */
    public function generate(string $prompt, array $refImagesB64 = []): string;
}
