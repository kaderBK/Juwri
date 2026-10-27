<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Found by the P1-12 Arabic journey: the cart's remove button of the cv-family storefronts carried the hard-coded English
 * aria-label "Remove" (screen readers read English in an Arabic cart). The label now comes from the page's own i18n table.
 */
class ThemeCartRemoveLabelTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['crave' => ['crave'], 'glow' => ['glow'], 'matcha' => ['matcha'], 'velora' => ['velora']];
    }

    /** @return array<string, array{string}> every theme whose cart script renders a remove button */
    public static function otherThemes(): array
    {
        return array_combine(
            $names = ['velvet', 'nebula', 'minimal', 'dough', 'plate', 'ember', 'soiree', 'crumb', 'picnic'],
            array_map(fn ($t) => [$t], $names),
        );
    }

    /** No theme script may ship the English label again, whatever its own i18n table is called. */
    public function test_no_theme_script_hard_codes_the_remove_label(): void
    {
        $scripts = glob(base_path('../assets/store_templates/*/js/script.js')) ?: [];
        $this->assertNotEmpty($scripts);
        foreach ($scripts as $file) {
            $this->assertStringNotContainsString('aria-label="Remove"', (string) file_get_contents($file), "$file: hard-coded English label");
        }
    }

    #[DataProvider('otherThemes')]
    public function test_the_other_themes_read_the_label_from_their_translated_table(string $theme): void
    {
        $js = (string) file_get_contents(base_path("../assets/store_templates/$theme/js/script.js"));
        $view = (string) file_get_contents(base_path("resources/views/store_templates/$theme/index.blade.php"));

        $this->assertMatchesRegularExpression('/aria-label="(?:\$\{|\' \+ )[^"]*\.remove \|\| /', $js, "$theme: the label is not read from the i18n table");
        $this->assertMatchesRegularExpression("/remove['\"]?\s*(=>|:)\s*(@json\()?'?\{?\{?\s*___\('Remove'\)/", $view, "$theme: the page does not hand the translated label to the script");
    }

    /** Nebula's script read CFG.translations.* (dish sheet, call waiter, resume banner) but the page never defined it. */
    public function test_nebula_hands_over_every_translation_its_script_reads(): void
    {
        $js = (string) file_get_contents(base_path('../assets/store_templates/nebula/js/script.js'));
        $view = (string) file_get_contents(base_path('resources/views/store_templates/nebula/index.blade.php'));
        preg_match_all('/CFG\.translations\.(\w+)/', $js, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $key) {
            $this->assertMatchesRegularExpression('/translations: \{.*?\b'.$key.':\s/s', $view, "nebula: translations.$key is read but never defined");
        }
    }

    #[DataProvider('themes')]
    public function test_the_remove_button_label_is_translated_not_hard_coded(string $theme): void
    {
        $js = (string) file_get_contents(base_path("../assets/store_templates/$theme/js/script.js"));
        $view = (string) file_get_contents(base_path("resources/views/store_templates/$theme/index.blade.php"));

        $this->assertStringNotContainsString('aria-label="Remove"', $js, "$theme: hard-coded English label");
        $this->assertStringContainsString('CFG.i18n.remove', $js, "$theme: the label is not read from the i18n table");
        $this->assertStringContainsString("remove:        @json(___('Remove'))", $view, "$theme: the page does not hand the translated label to the script");
    }

    public function test_remove_has_a_real_translation_in_every_locale(): void
    {
        $en = include base_path('lang/en/lang.php');
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            $table = include base_path("lang/$locale/lang.php");
            $this->assertArrayHasKey('remove', $table, $locale);
            $this->assertNotSame($en['remove'], $table['remove'], "[$locale] 'Remove' is still English");
        }
    }
}
