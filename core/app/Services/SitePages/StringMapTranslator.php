<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\SitePages;

use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;

/**
 * A flat key → string map translated through the shared AI provider chain (AiService::json), chunked to stay within a
 * character cap. Shared by the home-page builder (SiteTranslator) and the Reseller module's page/post translation.
 * P1-13 13f: was TranslateService::translateMap (the reseller pages' service, now the Reseller module's, ee).
 */
class StringMapTranslator
{
    /** Soft cap on characters per AiService call; larger sets are chunked. */
    private const CHAR_CAP = 8000;

    public function __construct(private Ai $ai) {}

    /**
     * Translate a flat map of key→string, chunking to stay within the char cap.
     * Returns key→translated string. Empty input short-circuits (no AI call).
     *
     * @param  array<string, string>  $strings
     * @return array<string, string>
     *
     * @throws AiProviderFailure when the provider chain fails.
     */
    public function translateMap(array $strings, string $source, string $target): array
    {
        if (empty($strings)) {
            return [];
        }

        $out = [];
        foreach ($this->chunk($strings) as $batch) {
            $prompt = [
                ['role' => 'system', 'content' => "You are a professional translator. Translate each string value from locale '{$source}' to locale '{$target}'. ".
                    'Return ONLY a JSON object mapping every input key to its translated string. '.
                    'Preserve any HTML tags, placeholders, and JSON structure inside values; translate only human-readable text. '.
                    'Do not add or remove keys.'],
                ['role' => 'user', 'content' => json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ];

            $decoded = $this->ai->json(
                $prompt,
                [],
                fn ($d) => is_array($d),
                null,
                null,
                'reseller_translate'
            );

            foreach ($batch as $key => $_) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    $out[$key] = $decoded[$key];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $strings
     * @return array<int, array<string, string>>
     */
    private function chunk(array $strings): array
    {
        $chunks = [];
        $current = [];
        $len = 0;
        foreach ($strings as $key => $val) {
            $vlen = mb_strlen($val) + mb_strlen($key) + 8;
            if ($current && $len + $vlen > self::CHAR_CAP) {
                $chunks[] = $current;
                $current = [];
                $len = 0;
            }
            $current[$key] = $val;
            $len += $vlen;
        }
        if ($current) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
