<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * P6-299 — Owner forms: labels not tied to their inputs. Screen readers announce
 * "edit text, blank" and clicking a label does not focus its field. Every
 * `<label class="ui-label">` in the eight worst owner views must now carry a
 * `for` that matches an `id` in the same view (or wrap its control), so labels
 * and their inputs are programmatically associated.
 */
class OwnerFormLabelBindingTest extends TestCase
{
    /** @var array<int, string> relative view paths (the eight files fixed by hand) */
    private const FILES = [
        'owner/stores/edit.blade.php',
        'owner/biolink/partials/_block_fields.blade.php',
        'owner/menu/schedules.blade.php',
        'owner/menu/index.blade.php',
        'owner/hotel/info.blade.php',
        'owner/menu/category.blade.php',
        'owner/branches/index.blade.php',
        'owner/menu/extras.blade.php',
    ];

    public function test_every_ui_label_is_bound_to_a_control(): void
    {
        $offenders = [];
        foreach (self::FILES as $rel) {
            $src = $this->source($rel);
            foreach ($this->uiLabelTags($src) as $tag) {
                if (! $this->hasAttribute($tag['raw'], 'for') && ! $this->wrapsControl($src, $tag)) {
                    $offenders[$rel][] = preg_replace('/\s+/', ' ', $tag['raw']);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'unbound `.ui-label` elements (add a `for=` matching an `id`, or wrap the control): '
            .json_encode($offenders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_every_label_for_target_exists_in_the_same_file(): void
    {
        $missing = [];
        foreach (self::FILES as $rel) {
            $src = $this->source($rel);
            $ids = $this->attributeValues($src, 'id');
            foreach ($this->attributeValues($src, 'for') as $target) {
                if (! in_array($target, $ids, true)) {
                    $missing[$rel][] = $target;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            '`for=` targets with no matching `id` in the same view: '
            .json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function source(string $rel): string
    {
        $path = resource_path('views/'.$rel);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * All `<label …>` opening tags that carry the `ui-label` class. Blade ids
     * like `{{ $s->id }}` contain a `>` (inside `->`), so the tag end is found by
     * scanning past quoted strings and `{{ … }}` blocks rather than a `[^>]*`.
     *
     * @return array<int, array{raw: string, start: int, end: int}>
     */
    private function uiLabelTags(string $src): array
    {
        $tags = [];
        $offset = 0;
        while (($open = strpos($src, '<label', $offset)) !== false) {
            $after = $src[$open + 6] ?? '';
            if ($after !== '' && ! preg_match('/[\s>\/]/', $after)) {
                $offset = $open + 6;

                continue;
            }
            $end = $this->tagEnd($src, $open + 6);
            $raw = substr($src, $open, $end - $open + 1);
            if ($this->hasClass($raw, 'ui-label')) {
                $tags[] = ['raw' => $raw, 'start' => $open, 'end' => $end];
            }
            $offset = $end + 1;
        }

        return $tags;
    }

    private function tagEnd(string $src, int $from): int
    {
        $len = strlen($src);
        $depth = 0;
        $quote = null;
        for ($i = $from; $i < $len; $i++) {
            $c = $src[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;

                continue;
            }
            if ($c === '{' && ($src[$i + 1] ?? '') === '{') {
                $depth++;
                $i++;

                continue;
            }
            if ($c === '}' && ($src[$i + 1] ?? '') === '}') {
                $depth--;
                $i++;

                continue;
            }
            if ($c === '>' && $depth === 0) {
                return $i;
            }
        }

        return $len - 1;
    }

    private function hasClass(string $tag, string $needle): bool
    {
        if (preg_match('/class\s*=\s*"([^"]*)"/', $tag, $m)
            && in_array($needle, preg_split('/\s+/', $m[1]) ?: [], true)) {
            return true;
        }

        return (bool) preg_match("/class\s*=\s*'([^']*)'/", $tag, $m)
            && in_array($needle, preg_split('/\s+/', $m[1]) ?: [], true);
    }

    private function hasAttribute(string $tag, string $name): bool
    {
        return (bool) preg_match('/(?<![\w:.-])'.$name.'\s*=\s*["\']/', $tag);
    }

    private function wrapsControl(string $src, array $tag): bool
    {
        $close = strpos($src, '</label>', $tag['end']);
        if ($close === false) {
            return false;
        }
        $inner = substr($src, $tag['end'] + 1, $close - $tag['end'] - 1);

        return (bool) preg_match('/<(input|select|textarea)\b/i', $inner);
    }

    /**
     * @return array<int, string>
     */
    private function attributeValues(string $src, string $name): array
    {
        preg_match_all('/(?<![\w:.-])'.$name.'\s*=\s*"([^"]*)"/', $src, $double);
        preg_match_all('/(?<![\w:.-])'.$name."\s*=\s*'([^']*)'/", $src, $single);

        return array_merge($double[1], $single[1]);
    }
}
