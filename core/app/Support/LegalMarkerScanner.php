<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

/**
 * P1-23: scan the website legal source tree (<root>/<locale>/*.md) for {{FILL:key}} / {{VERIFY:key}} markers
 * (key optional: {{FILL}}). Locales and files are walked in sorted order so the output is stable; one entry per
 * marker occurrence. The sentence is the trimmed line segment that contains the marker, split on sentence-final
 * punctuation (. ! ? ؟ ؛) so a sentence that ends mid-line still resolves to one clause. Fallback is the whole
 * trimmed line.
 */
final class LegalMarkerScanner
{
    /** {{FILL:key}} / {{VERIFY:key}} (key optional). Matches the existing Juwri\Core\Legal\LegalPage::MARKER set
     *  and extends it with the bare {{FILL}} / {{VERIFY}} form. */
    private const MARKER = '/\{\{(FILL|VERIFY)(?::([a-z0-9_]+))?\}\}/u';

    /** Sentence-final punctuation across English + Arabic. */
    private const SENTENCE_END = '/[.!?؟؛]/u';

    /**
     * @return list<array{document: string, locale: string, file: string, line: int, type: string, key: string, sentence: string}>
     */
    public function scan(string $root): array
    {
        $out = [];
        if (! is_dir($root)) {
            return $out;
        }
        foreach ($this->listLocaleDirs($root) as $localeDir) {
            $locale = basename($localeDir);
            $files = glob($localeDir.'/*.md') ?: [];
            sort($files);
            foreach ($files as $file) {
                $document = (string) pathinfo($file, PATHINFO_FILENAME);
                $relative = $locale.'/'.basename($file);
                $lines = @file($file, FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    continue;
                }
                foreach ($lines as $i => $line) {
                    if (preg_match_all(self::MARKER, $line, $matches, PREG_SET_ORDER) === 0) {
                        continue;
                    }
                    foreach ($matches as $m) {
                        $out[] = [
                            'document' => $document,
                            'locale' => $locale,
                            'file' => $relative,
                            'line' => $i + 1,
                            'type' => $m[1],
                            'key' => $m[2] ?? '',
                            'sentence' => $this->sentenceFor($line, $m[0]),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function listLocaleDirs(string $root): array
    {
        $dirs = glob(rtrim($root, '/').'/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        return $dirs;
    }

    private function sentenceFor(string $line, string $marker): string
    {
        $trimmed = trim($line);
        $parts = preg_split(self::SENTENCE_END, $trimmed) ?: [];
        foreach ($parts as $part) {
            if (str_contains($part, $marker)) {
                return trim($part);
            }
        }

        return $trimmed;
    }
}
