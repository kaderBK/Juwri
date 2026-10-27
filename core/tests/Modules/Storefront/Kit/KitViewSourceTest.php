<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P3-05: the kit views obey THEME-CONTRACT at the SOURCE level (the conformance gates check the rendered pages): nothing but the
 * view model in, no raw output, no inline handlers or styles, no model or database access, every UI string translated in all
 * 11 locales.
 */
class KitViewSourceTest extends TestCase
{
    /** @return list<string> */
    private function views(): array
    {
        $out = [];
        foreach (['_kit', 'table', 'brew', 'book', 'aisle', 'basket'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/views/store_templates/'.$dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (str_ends_with((string) $file, '.blade.php')) {
                    $out[] = (string) $file;
                }
            }
        }
        sort($out);

        return $out;
    }

    public function test_the_views_hold_none_of_the_forbidden_constructs(): void
    {
        $views = $this->views();
        $this->assertGreaterThan(30, count($views));
        $bad = [];
        foreach ($views as $file) {
            $src = (string) file_get_contents($file);
            $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src); // comments may talk about these things
            foreach ([
                '/\{!!/' => 'raw output',
                '/\son[a-z]+\s*=/i' => 'inline event handler',
                '/\sstyle\s*=/i' => 'style attribute',
                '/<script(?![^>]*\ssrc=)(?![^>]*type="application\/json")/i' => 'inline script',
                '/store_options\(|\brequest\(|\\\\App\\\\Models|\bDB::|\bauth\(|\bsession\(|theme_setting\(/' => 'data access outside the view model',
                '#https?://#' => 'external host',
            ] as $rx => $what) {
                if (preg_match($rx, $src)) {
                    $bad[] = basename(dirname($file)).'/'.basename($file).': '.$what;
                }
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_every_ui_string_is_translated_in_all_eleven_locales(): void
    {
        $keys = [];
        foreach ($this->views() as $file) {
            preg_match_all('/->(?:s|t)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', (string) file_get_contents($file), $m);
            foreach (array_merge($m[1], $m[2]) as $text) {
                if ($text !== '') {
                    $keys[Str::slug(stripslashes($text), '_')] = $text;
                }
            }
        }
        $this->assertGreaterThan(80, count($keys), 'the strings were not found');

        $locales = array_map('basename', glob(base_path('lang/*'), GLOB_ONLYDIR));
        $this->assertCount(11, $locales);
        $missing = [];
        foreach ($locales as $loc) {
            $table = require base_path("lang/{$loc}/lang.php");
            foreach ($keys as $slug => $text) {
                if (! isset($table[$slug]) || trim((string) $table[$slug]) === '') {
                    $missing[] = "{$loc}: {$text}";
                }
            }
        }
        $this->assertSame([], $missing, implode("\n", array_slice($missing, 0, 30)));
    }
}
