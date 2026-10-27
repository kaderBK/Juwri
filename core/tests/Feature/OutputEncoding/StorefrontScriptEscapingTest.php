<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\OutputEncoding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P6-73. The storefront themes build their item sheet and cart with innerHTML. Item, option, extra, allergen and
 * category names are typed by the store owner or a menu-edit staff member and shown to every customer, so a name
 * such as `<img src=x onerror=alert(1)>` or `"><svg onload=...>` must be HTML-escaped at every interpolation.
 *
 * This is a static guard (no browser): each theme script may not interpolate the named fields raw into a template.
 * A wrapper (ffEsc/esc/fEsc/eh/jwEsc/...) in front of the field is what keeps the line out of the patterns below.
 */
class StorefrontScriptEscapingTest extends TestCase
{
    /** Fields that carry owner/staff-typed text. */
    private const FIELDS = '(?:item|c|i|it|baseItem|a|v|e|o|g|x|m|d)\.(?:name|desc|title|label|variantLabel|reward_description)';

    /** @return array<string, array{0: string}> */
    public static function scripts(): array
    {
        $dir = dirname(__DIR__, 4).'/assets/store_templates';
        $out = [];
        foreach (glob($dir.'/*/js/script.js') ?: [] as $file) {
            $out[basename(dirname($file, 2))] = [$file];
        }

        return $out;
    }

    #[DataProvider('scripts')]
    public function test_no_owner_typed_field_is_interpolated_raw_into_html(string $file): void
    {
        $theme = basename(dirname($file, 2));
        $bad = [];
        foreach (file($file) ?: [] as $n => $line) {
            // Only lines that build markup.
            if (! str_contains($line, '<') && ! str_contains($line, '`')) {
                continue;
            }
            $patterns = [
                // template literal: ${item.name}
                '/\$\{\s*'.self::FIELDS.'\s*\}/',
                // string concatenation inside markup: '>' + item.name + '<'  /  "…" + c.name + "…"
                '/[\'"]\s*\+\s*'.self::FIELDS.'\s*\+\s*[\'"]/',
                '/>\s*[\'"]\s*\+\s*'.self::FIELDS.'\s*[;,)]/',
                // scoop's localised pickers return raw text: ${name(i)}, ${desc(i)}, ${catName(c)}
                '/\$\{\s*(?:name|desc|catName)\(/',
            ];
            // toast(msg) in crumb/picnic escapes inside the helper; `notes` is a plain-text order note, not markup.
            $plainText = preg_match('/toast\(|const notes/', $line) === 1;
            foreach ($patterns as $i => $rx) {
                if ($i === 3 && $plainText) {
                    continue;
                }
                if (preg_match($rx, $line) === 1) {
                    $bad[] = ($n + 1).': '.trim(mb_substr($line, 0, 140));
                }
            }
        }

        // Names that are rendered through textContent / data-attrs only are not flagged here: they never match
        // because they are not part of a markup string. A real raw interpolation fails the test with its line.
        $this->assertSame([], $bad, "raw owner-typed field in an HTML template ({$theme}):\n".implode("\n", $bad));
    }
}
