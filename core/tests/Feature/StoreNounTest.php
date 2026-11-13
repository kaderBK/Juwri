<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Models\Store;
use Tests\TestCase;

/**
 * store_noun() + the ":noun" placeholder give dashboard chrome a store-type-aware
 * entity noun (Vertical-Neutral Copy Audit). A food owner still reads
 * "Restaurant"; a pharmacy owner reads "Store"; a hotel "Hotel"; no store in
 * context falls back to the neutral "Business"/متجر. Arabic varies by
 * grammatical role (definite / possessive), English by case.
 */
class StoreNounTest extends TestCase
{
    private function mkPost(?string $type): Store
    {
        $p = new Store();
        $p->store_type = $type;

        return $p;
    }

    public function test_english_noun_forms_per_store_type(): void
    {
        app()->setLocale('en');

        $this->assertSame('Restaurant', store_noun($this->mkPost('restaurant'), 'title'));
        $this->assertSame('Store', store_noun($this->mkPost('pharmacy'), 'title'));
        $this->assertSame('Business', store_noun($this->mkPost('laundry'), 'title'));
        $this->assertSame('Hotel', store_noun($this->mkPost('hotel'), 'title'));
        // lower / plural forms
        $this->assertSame('restaurant', store_noun($this->mkPost('restaurant'), 'lower'));
        $this->assertSame('stores', store_noun($this->mkPost('pharmacy'), 'plural'));
        // unknown / null → neutral umbrella
        $this->assertSame('Business', store_noun($this->mkPost(null), 'title'));
        $this->assertSame('Business', store_noun($this->mkPost('made_up_type'), 'title'));
    }

    public function test_arabic_noun_forms_per_store_type(): void
    {
        app()->setLocale('ar');

        // food keeps مطعم; hotels فندق; everything else the house-neutral متجر
        $this->assertSame('المطعم', store_noun($this->mkPost('restaurant'), 'title'));
        $this->assertSame('مطعمك', store_noun($this->mkPost('restaurant'), 'poss'));
        $this->assertSame('المتجر', store_noun($this->mkPost('pharmacy'), 'title'));
        $this->assertSame('متجرك', store_noun($this->mkPost('pharmacy'), 'poss'));
        $this->assertSame('الفندق', store_noun($this->mkPost('hotel'), 'title'));
        $this->assertSame('المتجر', store_noun($this->mkPost(null), 'title'));
    }

    public function test_placeholder_resolves_in_a_full_string_both_locales(): void
    {
        app()->setLocale('en');
        $this->assertSame(
            'Store cloned successfully.',
            ___(':noun cloned successfully.', ['noun' => store_noun($this->mkPost('pharmacy'), 'title')])
        );
        // restaurant path unchanged — no regression for food owners
        $this->assertSame(
            'Restaurant cloned successfully.',
            ___(':noun cloned successfully.', ['noun' => store_noun($this->mkPost('restaurant'), 'title')])
        );

        app()->setLocale('ar');
        // Arabic proclitic ل fuses onto the possessive form (ل + مطعمك = لمطعمك)
        $this->assertSame(
            'اعثر على قالب مصمّم لمطعمك',
            ___('Find a theme built for your :noun', ['noun' => store_noun($this->mkPost('restaurant'), 'poss')])
        );
        $this->assertSame(
            'اعثر على قالب مصمّم لمتجرك',
            ___('Find a theme built for your :noun', ['noun' => store_noun($this->mkPost('pharmacy'), 'poss')])
        );
    }

    public function test_tail_batch_strings_resolve_both_locales(): void
    {
        $food = $this->mkPost('restaurant');
        $goods = $this->mkPost('pharmacy');

        app()->setLocale('en');
        $this->assertSame(
            'No menu items for this restaurant yet — add items in the menu manager.',
            ___('No menu items for this :noun yet — add items in the menu manager.', ['noun' => store_noun($food, 'lower')])
        );
        $this->assertSame(
            'No menu items for this store yet — add items in the menu manager.',
            ___('No menu items for this :noun yet — add items in the menu manager.', ['noun' => store_noun($goods, 'lower')])
        );
        $this->assertSame(
            'Store default (no branch)',
            ___(':noun default (no branch)', ['noun' => store_noun($goods, 'title')])
        );

        app()->setLocale('ar');
        // possessive after the بـ proclitic (بتوقيت + مطعمك)
        $this->assertSame(
            'الأوقات بتوقيت مطعمك',
            ___('Times are your :noun clock', ['noun' => store_noun($food, 'poss')])
        );
        // definite in mid-sentence (هذا + المطعم), with the em-dash preserved
        $this->assertSame(
            'لا توجد عناصر قائمة لهذا المطعم بعد — أضف العناصر في مدير القائمة.',
            ___('No menu items for this :noun yet — add items in the menu manager.', ['noun' => store_noun($food, 'lower')])
        );
    }
}
