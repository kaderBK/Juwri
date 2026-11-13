<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

use Illuminate\Support\Facades\Cache;
use Juwri\Modules\Ai\Contracts\Translator;

/**
 * Translates short menu strings into one target language via the AI provider chain.
 *
 *  - translateBatch(): dedupes identical strings across many records and sends them
 *    in as few AI calls as possible (chunked), instead of one call per item.
 *  - Reuses a persistent translation memory (owner-approved) + a 30-day cache.
 *  - Honours a per-restaurant glossary (keep-as-is / fixed translations), brand
 *    tone, and a length hint so translations fit the UI.
 *  - Flags low-confidence output (untranslated / wildly mis-sized) for review.
 *
 * The caller writes results into the model `translations` JSON with the machine +
 * source-hash flags and never overwrites owner-edited text.
 */
class TranslatorService implements Translator
{
    private const CACHE_TTL = 60 * 60 * 24 * 30; // 30 days

    private const CHUNK = 40;                // unique strings per AI call

    /** Optional translation-memory hook: fn(string $sourceText, string $lang): ?string */
    public $memoryGet = null;

    public function __construct(protected AiService $ai) {}

    /** P1-08 (Translator contract): set the translation-memory hook — same as assigning $memoryGet. */
    public function useMemory(?callable $memoryGet): void
    {
        $this->memoryGet = $memoryGet;
    }

    /**
     * Backwards-compatible single-record translate (kept for callers that pass a
     * flat field map for one target language).
     *
     * @param  array<string,string>  $fields
     * @return array<string,string>
     */
    public function translate(array $fields, string $targetLang, ?string $sourceLang = null, ?int $userId = null, ?int $storeId = null): array
    {
        $out = $this->translateBatch(['_' => $fields], $targetLang, [
            'sourceLang' => $sourceLang, 'userId' => $userId, 'storeId' => $storeId,
        ]);
        $rec = $out['_'] ?? [];
        unset($rec['_lowconf']);

        return $rec;
    }

    /**
     * Translate many records into one language, deduping strings and batching calls.
     *
     * @param  array<string|int, array<string,string>>  $records  refId => [field => text]
     * @param  array  $opts  sourceLang, glossary[], tone, userId, storeId
     * @return array<string|int, array<string,mixed>> refId => [field => translated, '_lowconf' => bool]
     */
    public function translateBatch(array $records, string $targetLang, array $opts = []): array
    {
        // 1. Gather unique non-empty source strings across all records/fields.
        $unique = [];
        foreach ($records as $fields) {
            foreach ((array) $fields as $text) {
                if (is_string($text) && trim($text) !== '') {
                    $unique[$text] = true;
                }
            }
        }
        $unique = array_keys($unique);
        if (empty($unique)) {
            return array_map(fn () => ['_lowconf' => false], $records);
        }

        // 2. Resolve each unique string from memory -> cache -> pending.
        $map = [];      // sourceText => translatedText
        $pending = [];
        foreach ($unique as $text) {
            if ($this->memoryGet && ($m = ($this->memoryGet)($text, $targetLang)) !== null && $m !== '') {
                $map[$text] = $m;

                continue;
            }
            $cached = Cache::get($this->cacheKey($text, $targetLang));
            if ($cached !== null) {
                $map[$text] = $cached;
            } else {
                $pending[] = $text;
            }
        }

        // 3. Translate the misses in chunks (one AI call per chunk).
        foreach (array_chunk($pending, self::CHUNK) as $chunk) {
            $translated = $this->callModel($chunk, $targetLang, $opts);
            foreach ($chunk as $i => $text) {
                $value = $translated['s'.$i] ?? null;
                $value = is_string($value) && trim($value) !== '' ? trim($value) : $text;
                Cache::put($this->cacheKey($text, $targetLang), $value, self::CACHE_TTL);
                $map[$text] = $value;
            }
        }

        // 4. Reassemble per record + confidence.
        $result = [];
        foreach ($records as $refId => $fields) {
            $rec = ['_lowconf' => false];
            foreach ((array) $fields as $field => $text) {
                if (! is_string($text) || trim($text) === '') {
                    continue;
                }
                $trans = $map[$text] ?? $text;
                $rec[$field] = $trans;
                if ($this->lowConfidence($text, $trans)) {
                    $rec['_lowconf'] = true;
                }
            }
            $result[$refId] = $rec;
        }

        return $result;
    }

    /**
     * @param  array<int,string>  $chunk  0-indexed source strings
     * @return array<string,string> 's0' => translation, ...
     */
    protected function callModel(array $chunk, string $targetLang, array $opts): array
    {
        $payload = [];
        foreach ($chunk as $i => $text) {
            $payload['s'.$i] = $text;
        }
        $keys = array_keys($payload);

        $system = $this->buildSystemPrompt($targetLang, $keys, $opts);

        $decoded = $this->ai->json(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => "Strings to translate (JSON, translate the values only):\n".json_encode($payload, JSON_UNESCAPED_UNICODE)],
            ],
            ['max_tokens' => 4096],
            function ($d) use ($keys) {
                return array_any($keys, fn ($k) => array_key_exists($k, $d));
            },
            $opts['userId'] ?? null,
            $opts['storeId'] ?? null,
            // Metering tag: callers reusing this batch translator for another
            // surface (e.g. biolink block translate → 'bio_translate') pass their
            // own feature; menu translation keeps today's tag by default.
            is_string($opts['feature'] ?? null) && $opts['feature'] !== '' ? $opts['feature'] : 'menu_translate'
        );

        $out = [];
        foreach ($keys as $k) {
            if (isset($decoded[$k]) && is_scalar($decoded[$k])) {
                $out[$k] = (string) $decoded[$k];
            }
        }

        return $out;
    }

    protected function buildSystemPrompt(string $targetLang, array $keys, array $opts): string
    {
        $p = 'You are a professional menu translator. Translate the given string values into '
            .$targetLang.'. Keep proper nouns, brand names, numbers, prices and measurements '
            .'unchanged. Do not add or remove information.';

        if (! empty($opts['sourceLang'])) {
            $p .= ' The source language is '.$opts['sourceLang'].'.';
        }

        // #7 tone
        $tone = $opts['tone'] ?? null;
        if ($tone === 'formal') {
            $p .= ' Use a polite, formal tone.';
        } elseif ($tone === 'casual') {
            $p .= ' Use a friendly, casual tone.';
        }

        // #8 length hint (UI fit)
        $p .= ' Keep each translation close to the length of its source and concise so it fits menu UI.';

        // #3 glossary
        $glossary = $opts['glossary'] ?? [];
        if (! empty($glossary)) {
            $keep = [];
            $fixed = [];
            foreach ($glossary as $g) {
                $term = trim((string) ($g['term'] ?? ''));
                if ($term === '') {
                    continue;
                }
                if (! empty($g['translation'])) {
                    $fixed[] = '"'.$term.'" -> "'.$g['translation'].'"';
                } else {
                    $keep[] = '"'.$term.'"';
                }
            }
            if ($keep) {
                $p .= ' Keep these terms unchanged: '.implode(', ', array_slice($keep, 0, 50)).'.';
            }
            if ($fixed) {
                $p .= ' Use these exact translations where they appear: '.implode('; ', array_slice($fixed, 0, 50)).'.';
            }
        }

        $p .= ' Return ONLY a JSON object with exactly these keys: '.implode(', ', $keys)
            .'. Each value is the translated string for the matching input.';

        return $p;
    }

    /** Heuristic: is this translation suspicious enough to flag for review? */
    protected function lowConfidence(string $source, string $target): bool
    {
        $s = trim($source);
        $t = trim($target);
        if ($t === '' || $t === $s) {
            return true; // empty or untranslated
        }
        $ls = max(1, mb_strlen($s));
        $lt = mb_strlen($t);

        return $lt < $ls * 0.3 || $lt > $ls * 3.5;
    }

    protected function cacheKey(string $text, string $targetLang): string
    {
        return 'ai_tr:'.strtolower($targetLang).':'.sha1($text);
    }
}
