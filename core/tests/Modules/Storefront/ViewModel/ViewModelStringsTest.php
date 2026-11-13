<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\ViewModel;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P3-02: every string the view model translates (a `___('…')` literal in the builder or its presenters, and the
 * fulfilment labels of config/verticals.php it prints) exists in all 11 locales, and the Arabic value is a real
 * translation (not the English text).
 */
class ViewModelStringsTest extends TestCase
{
    private const LOCALES = ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    /** Identical in Arabic on purpose (a brand name). */
    private const SAME_IN_AR = ['WhatsApp'];

    /** @return list<string> */
    private function strings(): array
    {
        $files = array_merge(
            glob(app_path('Modules/Storefront/Services/ViewModel/*.php')) ?: [],
            [app_path('Modules/Ordering/Services/Storefront/StorefrontOrderingService.php'), app_path('Modules/Menu/Services/Storefront/StorefrontCatalogReader.php')],
        );
        $strings = [];
        foreach ($files as $file) {
            preg_match_all("/___\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $m);
            foreach ($m[1] as $s) {
                $strings[] = stripslashes($s);
            }
            preg_match_all('/___\(\s*"((?:[^"\\\\]|\\\\.)*)"/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $s) {
                $strings[] = stripcslashes($s);
            }
        }
        // the I18N list of the builder and the fulfilment labels go through ___() as variables
        $builder = (string) file_get_contents(app_path('Modules/Storefront/Services/ViewModel/ViewModelBuilder.php'));
        if (preg_match('/private const I18N = \[(.*?)\];/s', $builder, $block) === 1) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"/", $block[1], $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $strings[] = ($match[2] ?? '') !== '' ? stripcslashes($match[2]) : stripslashes($match[1]);
            }
        }
        foreach ((array) config('verticals.fulfillment') as $mode) {
            $strings[] = (string) $mode['label'];
        }

        return array_values(array_unique($strings));
    }

    public function test_every_view_model_string_is_translated_in_all_locales(): void
    {
        $strings = $this->strings();
        $this->assertGreaterThan(60, count($strings));
        $tables = [];
        foreach (self::LOCALES as $locale) {
            $tables[$locale] = require lang_path($locale.'/lang.php');
        }

        $missing = [];
        foreach ($strings as $text) {
            $key = Str::slug($text, '_');
            foreach (self::LOCALES as $locale) {
                if (! isset($tables[$locale][$key]) || trim((string) $tables[$locale][$key]) === '') {
                    $missing[] = "{$locale}: {$key} ({$text})";
                }
            }
            if (isset($tables['ar'][$key], $tables['en'][$key]) && $tables['ar'][$key] === $tables['en'][$key] && ! in_array($text, self::SAME_IN_AR, true)) {
                $missing[] = "ar: {$key} is the English text";
            }
        }

        $this->assertSame([], $missing);
    }
}
