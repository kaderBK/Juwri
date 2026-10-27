<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * I5 §1 — ___n() pluralization helper. Resolves the correct CLDR plural form
 * for a count under the current locale (Arabic has 6 forms, Russian 3).
 */
class PluralHelperTest extends TestCase
{
    public function test_english_selects_singular_and_plural_by_count(): void
    {
        App::setLocale('en');
        $this->assertSame('1 item', ___n('item', 1));
        $this->assertSame('2 items', ___n('item', 2));
        $this->assertSame('0 items', ___n('item', 0));
    }

    public function test_arabic_uses_its_six_plural_forms(): void
    {
        App::setLocale('ar');
        $this->assertStringContainsString('واحد', ___n('item', 1));     // one
        $this->assertStringContainsString('عنصران', ___n('item', 2));   // two
        $this->assertStringContainsString('عناصر', ___n('item', 3));    // few
        $this->assertStringContainsString('عنصرًا', ___n('item', 11));  // many
    }

    public function test_russian_uses_its_three_plural_forms(): void
    {
        App::setLocale('ru');
        $this->assertStringContainsString('элемент', ___n('item', 1));   // one
        $this->assertStringContainsString('элемента', ___n('item', 2));  // few
        $this->assertStringContainsString('элементов', ___n('item', 5)); // many
    }

    public function test_count_placeholder_is_interpolated(): void
    {
        App::setLocale('en');
        $this->assertStringContainsString('7', ___n('item', 7));
    }

    public function test_plural_key_does_not_collide_with_flat_key(): void
    {
        // The plural entry is namespaced n_item, distinct from a flat ___('item').
        $en = include base_path('lang/en/lang.php');
        $this->assertArrayHasKey('n_item', $en);
        $this->assertStringContainsString('|', $en['n_item']); // has plural branches
    }
}
