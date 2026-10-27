<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * I1 — de/es/fr/pt/tr lang files backfilled with real translations.
 *
 * Guards the two things the backfill must guarantee: a known storefront key
 * resolves to a genuinely non-English string in each Latin locale, and no
 * placeholder token was dropped in translation.
 */
class LatinLocaleBackfillTest extends TestCase
{
    /** Known, high-traffic storefront keys → the expected non-English value. */
    public static function knownKeys(): array
    {
        return [
            // locale, English source, must-NOT-equal (English), expected substring
            ['de', 'Add to cart',  'Warenkorb'],
            ['fr', 'Add to cart',  'panier'],
            ['es', 'Add to cart',  'carrito'],
            ['pt', 'Add to cart',  'carrinho'],
            ['tr', 'Add to cart',  'Sepete'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('knownKeys')]
    public function test_a_known_key_resolves_to_a_translated_value(string $locale, string $english, string $expectSubstr): void
    {
        App::setLocale($locale);
        $out = ___($english);

        $this->assertNotSame($english, $out, "[$locale] '$english' still renders English");
        $this->assertStringContainsStringIgnoringCase($expectSubstr, $out, "[$locale] '$english' => '$out'");
    }

    public function test_the_five_latin_locales_are_mostly_translated(): void
    {
        $en = include base_path('lang/en/lang.php');
        foreach (['de', 'es', 'fr', 'pt', 'tr'] as $loc) {
            $t = include base_path("lang/$loc/lang.php");
            $total = 0; $identical = 0;
            foreach ($t as $slug => $val) {
                if (!array_key_exists($slug, $en) || $en[$slug] === '') continue;
                $total++;
                if ($val === $en[$slug]) $identical++;
            }
            $ratio = $identical / max(1, $total);
            $this->assertLessThan(0.05, $ratio, "[$loc] still {$identical}/{$total} identical to English");
        }
    }

    public function test_placeholders_survive_translation(): void
    {
        // A key whose English value carries a :placeholder must keep it verbatim
        // in every Latin locale.
        $en = include base_path('lang/en/lang.php');
        $key = 'features_of_plan_name'; // en: "Features of :plan_name"
        $this->assertArrayHasKey($key, $en);
        foreach (['de', 'es', 'fr', 'pt', 'tr'] as $loc) {
            $t = include base_path("lang/$loc/lang.php");
            $this->assertArrayHasKey($key, $t);
            $this->assertStringContainsString(':plan_name', $t[$key], "[$loc] dropped :plan_name");
        }
    }
}
