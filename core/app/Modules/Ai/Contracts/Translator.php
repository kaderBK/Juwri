<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Contracts;

use Juwri\Modules\Ai\Services\TranslatorService;

/** P1-08 L1 public surface: AI translation. Implemented by {@see TranslatorService}. */
interface Translator
{
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function translate(array $fields, string $targetLang, ?string $sourceLang = null, ?int $userId = null, ?int $storeId = null): array;

    /**
     * @param  array<int|string, mixed>  $records
     * @param  array<string, mixed>  $opts
     * @return array<int|string, mixed>
     */
    public function translateBatch(array $records, string $targetLang, array $opts = []): array;

    /** Translation-memory hook used before calling the AI: fn(string $sourceText, string $lang): ?string (null = none). */
    public function useMemory(?callable $memoryGet): void;
}
