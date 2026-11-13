<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3-00: the kit storefront strings (tools/themes/kit/lang, 573 keys x 11 locales)
 * are merged add-only into core/lang/<locale>/lang.php.
 */
class KitLangMergeTest extends TestCase
{
    private const LOCALES = ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    private function kit(string $l): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3)."/tools/themes/kit/lang/$l.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private function core(string $l): array
    {
        return include dirname(__DIR__, 2)."/lang/$l/lang.php";
    }

    public function test_every_kit_key_exists_in_every_locale_and_key_sets_are_aligned(): void
    {
        $keys = array_keys($this->kit('en'));
        $this->assertCount(573, $keys);
        foreach (self::LOCALES as $l) {
            $kit = $this->kit($l);
            $this->assertSame($keys, array_keys($kit), "$l kit key set differs from en");
            $core = $this->core($l);
            foreach ($keys as $k) {
                $this->assertArrayHasKey($k, $core, "$l core lang misses kit key $k");
                $this->assertNotSame('', trim((string) $core[$k]), "$l.$k is empty");
            }
        }
    }

    public function test_kit_placeholders_are_kept_in_every_locale(): void
    {
        $en = $this->kit('en');
        foreach (self::LOCALES as $l) {
            $kit = $this->kit($l);
            foreach ($en as $k => $source) {
                preg_match_all('/:[a-z_]+/i', $source, $m);
                foreach (array_unique($m[0]) as $ph) {
                    $this->assertStringContainsString($ph, $kit[$k], "$l.$k lost placeholder $ph");
                }
            }
        }
    }

    public function test_kit_values_are_not_english_copies_except_the_allow_listed_loanwords(): void
    {
        $en = $this->kit('en');
        foreach (array_diff(self::LOCALES, ['en']) as $l) {
            $same = [];
            foreach ($this->kit($l) as $k => $v) {
                if ($v === $en[$k]) {
                    $same[] = $k;
                }
            }
            // Brand names, units and loanwords only (lang_check.py keeps the full allow-list).
            $this->assertLessThanOrEqual(25, count($same), "$l has too many strings identical to English: ".implode(', ', $same));
        }
    }

    public function test_merge_never_overwrites_an_existing_core_value(): void
    {
        // `saved` already exists in core ("Saved.") with a different kit value ("Saved"): core wins.
        $this->assertSame('Saved.', $this->core('en')['saved']);
        // `min` (minutes in core) vs kit "Min": core meaning kept.
        $this->assertSame('min', $this->core('en')['min']);
    }

    public function test_arabic_cart_term_is_alsalla_everywhere_never_alaraba_or_alhaqiba(): void
    {
        // Owner rule 2026-10-03: customer- and staff-facing Arabic for cart/bag is السلة.
        $forbidden = '/العربة|عربة|عربتك|الحقيبة|حقيبة|حقيبتي|حقيبتك/u';
        foreach (['core lang' => $this->core('ar'), 'kit lang' => $this->kit('ar')] as $where => $lines) {
            foreach ($lines as $key => $value) {
                $this->assertDoesNotMatchRegularExpression($forbidden, (string) $value, "$where ar.$key still uses the old cart/bag word: $value");
            }
        }

        $core = $this->core('ar');
        $this->assertSame('السلة', $core['cart']);
        $this->assertSame('السلة', $core['bag']);
        $this->assertSame('سلتي', $core['my_bag']);
        $this->assertSame('أضف إلى السلة', $core['add_to_cart']);
        $this->assertSame('تمت إضافته إلى السلة', $core['added_to_cart']);
        $this->assertSame('تمت إضافته إلى السلة', $core['added_to_bag']);
        $this->assertSame('سلتك فارغة.', $core['your_cart_is_empty']);
        $this->assertSame('سلتك', $this->kit('ar')['your_bag']);
    }

    /** @return array<string, array{key: string, values: array<string, string>}> */
    private function collisions(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/tools/themes/kit/lang/collisions.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_kit_strings_that_collide_with_a_different_core_meaning_have_their_own_key_in_every_locale(): void
    {
        $collisions = $this->collisions();
        $this->assertSame(['min', 'per_unit', 'your_name', 'items', 'shop', 'sale'], array_keys($collisions));
        foreach ($collisions as $source => $c) {
            $this->assertSame('kit_'.$source, $c['key']);
            foreach (self::LOCALES as $l) {
                $core = $this->core($l);
                $this->assertSame($this->kit($l)[$source], $c['values'][$l], "$l collision value of $source is not the kit's");
                $this->assertSame($c['values'][$l], $core[$c['key']] ?? null, "$l core lang misses {$c['key']}");
            }
        }
        // The core meaning of the plain key stays: minutes, "per :unit", bare Name.
        $this->assertSame('min', $this->core('en')['min']);
        $this->assertSame('per :unit', $this->core('en')['per_unit']);
        $this->assertSame('Name', $this->core('en')['your_name']);
        // And the kit_* values are real translations, not English copies (units/loanwords aside).
        foreach (array_diff(self::LOCALES, ['en']) as $l) {
            foreach ($collisions as $c) {
                if ($l !== 'fr' && $l !== 'de' && $l !== 'es' && $l !== 'pt') {
                    $this->assertNotSame($c['values']['en'], $c['values'][$l], "$l {$c['key']} is identical to English");
                }
            }
        }
    }

    public function test_kit_themes_use_the_collision_keys_not_the_plain_english_words(): void
    {
        // P3-05+ themes: ___('kit_min'), never ___('Min') (that slug is core's minutes). Vacuous until a kit theme has views.
        $root = dirname(__DIR__, 2).'/resources/views/store_templates';
        $plain = ['Min', 'Per unit', 'Your name', 'Items', 'Shop', 'Sale', 'min', 'per_unit', 'your_name', 'items', 'shop', 'sale'];
        foreach (['table', 'brew', 'aisle', 'basket', 'book'] as $slug) {
            if (! is_dir("$root/$slug")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$slug", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! str_ends_with((string) $file, '.php')) {
                    continue;
                }
                $src = (string) file_get_contents((string) $file);
                foreach ($plain as $word) {
                    $this->assertDoesNotMatchRegularExpression('/___\(\s*[\'"]'.preg_quote($word, '/').'[\'"]\s*[,)]/', $src, "$file calls ___('$word'); use the kit_ key from tools/themes/KIT-KEY-MAP.md");
                }
            }
        }
        $this->assertTrue(true);
    }

    public function test_core_sale_means_discounts_not_selling_in_every_locale(): void
    {
        // The only use of ___('Sale') is the "Sale only" filter of a storefront theme (discounted goods).
        $this->assertSame('تخفيضات', $this->core('ar')['sale']);
        $this->assertNotSame('بيع', $this->core('ar')['sale']);
        // es Venta, hi बिक्री, pt Venda, tr Satış and ur بکری all mean "selling/sales".
        $sellingWords = ['es' => 'Venta', 'hi' => 'बिक्री', 'pt' => 'Venda', 'tr' => 'Satış', 'ur' => 'بکری'];
        foreach ($sellingWords as $l => $word) {
            $this->assertNotSame($word, $this->core($l)['sale'], "$l core `sale` still means selling");
        }
    }
}
