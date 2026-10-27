<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faq::localized() — per-locale set with English fallback, never the old
 * `lang OR NULL` union (which duplicated the English rows on translated locales).
 */
class FaqLocalizedTest extends TestCase
{
    use RefreshDatabase;

    private function seedFaqs(): void
    {
        // English base: one tagged 'en', one left NULL — both count as English.
        Faq::create(['faq_title' => 'EN one', 'faq_content' => 'a', 'translation_lang' => 'en', 'active' => 1]);
        Faq::create(['faq_title' => 'EN two', 'faq_content' => 'b', 'translation_lang' => null, 'active' => 1]);
        // German set.
        Faq::create(['faq_title' => 'DE eins', 'faq_content' => 'c', 'translation_lang' => 'de', 'active' => 1]);
        // Inactive German row must never surface.
        Faq::create(['faq_title' => 'DE aus', 'faq_content' => 'd', 'translation_lang' => 'de', 'active' => 0]);
    }

    public function test_locale_with_its_own_set_shows_only_that_set(): void
    {
        $this->seedFaqs();
        $titles = Faq::localized('de')->get()->pluck('faq_title')->all();

        $this->assertSame(['DE eins'], $titles, 'German shows only active German rows, no English union');
    }

    public function test_english_shows_base_rows_tagged_en_or_null(): void
    {
        $this->seedFaqs();
        $titles = Faq::localized('en')->get()->pluck('faq_title')->sort()->values()->all();

        $this->assertSame(['EN one', 'EN two'], $titles);
    }

    public function test_untranslated_locale_falls_back_to_english_not_blank(): void
    {
        $this->seedFaqs();
        // Italian has no set → English base, not an empty page.
        $titles = Faq::localized('it')->get()->pluck('faq_title')->sort()->values()->all();

        $this->assertSame(['EN one', 'EN two'], $titles);
    }
}
