<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Contracts;

use Juwri\Modules\Ai\Services\AiProviderFactory;

/** P1-08 L1 public surface: the store's AI provider chain. Implemented by {@see AiProviderFactory}. */
interface AiProviderChain
{
    /** @return array<int, mixed> the configured providers, in order (empty = no AI for this store/need) */
    public function chain(?int $storeId = null, bool $needsVision = false): array;

    /** @return array<int, mixed> the transcription-capable providers, in order (empty = no transcription) */
    public function transcribeChain(?int $storeId = null): array;
}
