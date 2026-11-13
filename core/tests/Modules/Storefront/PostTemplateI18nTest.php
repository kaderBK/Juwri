<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 6 — vertical theme metadata is translatable. The theme-picker renders
 * the theme name + description through ___(), so a lang key per name/description
 * localizes them. Previously the name rendered raw English in both pickers.
 */
class PostTemplateI18nTest extends TestCase
{
    public function test_vertical_theme_names_localize(): void
    {
        $this->assertSame('Shelf', trans('lang.shelf', [], 'en'));
        $this->assertSame('شِلف', trans('lang.shelf', [], 'ar'));
        $this->assertSame('بوكلا', trans('lang.bookla', [], 'ar'));
        $this->assertSame('ميزون', trans('lang.maison', [], 'ar'));
        // Regression: the pre-registered key had dropped the accent ("Lumiere").
        $this->assertSame('Lumière', trans('lang.lumiere', [], 'en'));
        $this->assertSame('لوميير', trans('lang.lumiere', [], 'ar'));
    }

    public function test_vertical_theme_descriptions_localize(): void
    {
        $en = 'A clean grid storefront for retail catalogs — product photos, brand, price, variant swatches and a one-tap add-to-cart. Category nav, search, RTL and dark mode. Vocab-aware: renders "Products", never "Dishes".';
        $slug = Str::slug($en, '_');

        $this->assertSame($en, trans('lang.'.$slug, [], 'en'), 'en description is the canonical source string');
        $ar = trans('lang.'.$slug, [], 'ar');
        $this->assertNotSame($en, $ar, 'the ar description must be translated, not the English fallback');
        $this->assertStringContainsString('متجر', $ar);
    }

    public function test_both_pickers_wrap_the_theme_name_in_translation(): void
    {
        $customizer = file_get_contents(base_path('resources/views/owner/design/customizer.blade.php'));
        $wizard = file_get_contents(base_path('resources/views/owner/stores/create.blade.php'));

        $this->assertStringContainsString("___(\$tpl['name'])", $customizer);
        $this->assertStringContainsString("___(\$template['name'])", $wizard);
        $this->assertStringContainsString("___(\$template['description']", $wizard);
    }
}
