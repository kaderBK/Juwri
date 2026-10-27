<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Search;

use App\Support\SearchTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-295 — unit tests for the Arabic search-term normaliser.
 *
 * The spec names "alef forms, ya/alef-maqsura, ta-marbuta, strip tashkeel/tatweel, digits" as
 * the rules the normaliser MUST apply uniformly — once on the stored column, once on every term —
 * so the search engine can compare them apples-to-apples. This file pins those rules at the
 * function level; the integration test (`MenuArabicSearchIBTest`) verifies they reach SQL.
 *
 * The normaliser is public, callable on any input (an empty string, Latin text, mixed
 * scripts) and idempotent — `normalize(normalize(x)) === normalize(x)`. Both of those make
 * it cheap to apply on both the term and the stored column without surprising the operator.
 *
 * The spec gives the builder freedom to pick the API surface — `SearchTerm::normalize()` is
 * the natural home (SearchTerm already owns search-term sanitation) but a sibling class or
 * helper function is acceptable. The test detects whichever shape exists and invokes it,
 * so it stays valid if the builder chooses a slightly different name.
 */
class SearchTermArabicNormalizeIBTest extends TestCase
{
    /**
     * The builder's choice of API name. The test resolves whichever exists on the
     * SearchTerm class; if none are present, the test skips with an actionable hint.
     *
     * Allowed candidates:
     *  - SearchTerm::normalize($s)             (most likely)
     *  - SearchTerm::arabicNormalize($s)       (sibling method on the same class)
     *  - SearchTerm::normalized($s)             (return-side naming)
     *
     * @return callable(string): string
     */
    private function normalizer(): callable
    {
        foreach (['normalize', 'arabicNormalize', 'normalized', 'applyNormalisation'] as $method) {
            if (method_exists(SearchTerm::class, $method)) {
                $ref = new \ReflectionMethod(SearchTerm::class, $method);
                if ($ref->isStatic()) {
                    return static fn (string $s): string => (string) $ref->invoke(null, $s);
                }
            }
        }

        $this->markTestSkipped(
            'P6-295: SearchTerm::normalize() (or equivalent) is missing — the builder must add the '
            .'search-term normaliser before these tests can run.'
        );
    }

    /**
     * Each row pairs an `input` with the `expected` skeleton every search candidate
     * MUST map to. The rules are tight; a partial normaliser (e.g. one that only
     * handles alef) makes these rows fail individually, so a regression reads as a
     * short list instead of a vague "Arabic search is broken".
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function samples(): iterable
    {
        // Alef forms (ا / أ / إ / آ / ٱ) all collapse to the bare alef (ا).
        yield 'alef-with-hamza-above' => ['أ', 'ا'];
        yield 'alef-with-hamza-below' => ['إ', 'ا'];
        yield 'alef-with-madda' => ['آ', 'ا'];
        yield 'bare-alef' => ['ا', 'ا'];
        yield 'alef-with-khanjariya-0671' => ['ٱ', 'ا'];
        yield 'alef-inside-word-preserved' => ['باسم', 'باسم'];
        yield 'alef-dominated-word' => ['أكل', 'اكل'];

        // Ya / alef-maqsura.
        yield 'bare-ya' => ['ي', 'ي'];
        yield 'alef-maqsura-0649' => ['ى', 'ي'];
        yield 'yeh-inside-word' => ['موسى', 'موسي'];

        // Ta-marbuta / ha. The spec only normalises the END-OF-WORD ة (a
        // mid-word ة is a different letter and should not move).
        yield 'ta-marbuta-end-of-word' => ['قهوة', 'قهوه'];
        yield 'ta-marbuta-with-space' => ['صلاة باهية', 'صلاه باهيه'];

        // Tashkeel (combining marks 064B-065F + 0670) are dropped wholesale,
        // everywhere they appear — at the start, the middle, the end.
        yield 'one-fatha' => ['بَ', 'ب'];
        yield 'fatha-damma-kasra' => ['بَُِ', 'ب'];
        yield 'shadda' => ['بَّ', 'ب'];
        yield 'sukun' => ['بْ', 'ب'];
        yield 'superscript-alef-0670' => ['بٰ', 'ب'];
        yield 'maddah-0653' => ['آ', 'ا'];     // overlaps alef case
        yield 'tashkeel-on-typed-word' => ['أَكَّل', 'اكل'];

        // Tatweel (0640) is a non-letter joining glyph used for stretching; it
        // carries no identity of its own.
        yield 'single-tatweel' => ['ـ', ''];
        yield 'tatweel-only-padded-word' => ['ــــقهوةــــ', 'قهوه'];
        yield 'tatweel-with-other-diacritics' => ['ــــأَكَّلــــ', 'اكل'];

        // Digits: Arabic-Indic (0660-0669) + Extended Arabic-Indic (06F0-06F9) +
        // Persian (06F0-06F9 are the same codepoints as Extended Arabic-Indic) →
        // Latin 0-9. A digit belongs to no script family, so a search for "3"
        // and "٣" must end up identical.
        yield 'arabic-indic-zero' => ['٠', '0'];
        yield 'arabic-indic-five' => ['٥', '5'];
        yield 'arabic-indic-nine' => ['٩', '9'];
        yield 'persian-zero' => ['۰', '0'];
        yield 'persian-four' => ['۴', '4'];
        yield 'digits-in-arabic-name' => ['٣ عروض', '3 عروض'];
        yield 'digits-in-latin-name-untouched' => ['100% Beef', '100% Beef'];

        // Untouched categories — every string that already consists only of
        // "neutral" letters must be returned byte-identical (English is left
        // untouched by the spec; the case-insensitive LIKE does case folding).
        yield 'latin-name-unchanged' => ['Burger', 'Burger'];
        yield 'latin-mixed-case' => ['CHEESE BURGER', 'CHEESE BURGER'];
        yield 'latin-punctuation-literal' => ['100% beef', '100% beef'];
        yield 'whitespace-trim-not-applied' => ['  spaced  ', '  spaced  '];
        yield 'empty-string-empty' => ['', ''];

        // Mixed-script: the Arabic portion is normalised, the Latin portion is
        // byte-identical. The function must not crash on either transition.
        yield 'mixed-script-basic' => ['برغر Burger', 'برغر Burger'];

        // Trimming of diacritics at the BRACKETS — combining marks at the start
        // of a word, written before the first letter, must also disappear.
        yield 'tashkeel-before-letter' => ['َقلم', 'قلم'];
    }

    /**
     * The canonical assertion. The normaliser is the spec's reduce function; the
     * integration test in MenuArabicSearchIBTest then verifies those reduced
     * strings actually find what they should in the database.
     */
    #[DataProvider('samples')]
    public function test_normaliser_follows_the_spec(string $input, string $expected): void
    {
        $normalize = $this->normalizer();
        $this->assertSame($expected, $normalize($input));
    }

    /**
     * Idempotent by construction: normalise once, normalise again, get the same
     * result. This is the cheapest possible smoke test against a normaliser
     * that, when fed its own output, drifts the second time.
     */
    public function test_normaliser_is_idempotent(): void
    {
        $normalize = $this->normalizer();
        $samples = [
            'أكل', 'قهوة', 'موسى', '٠ عروض', 'أَكَّل', 'ــــقهوةــــ',
            'بُكْريّة', 'Burger', 'Falafel + فلافل',
        ];
        foreach ($samples as $s) {
            $once = $normalize($s);
            $twice = $normalize($once);
            $this->assertSame($once, $twice, 'idempotence violated for '.$s);
        }
    }

    /**
     * Pure function contract: no exceptions on edge inputs (null-coerced to ''
     * by the type signature, an actual empty string, whitespace-only). The
     * store dashboard sends every conceivable term through here.
     */
    public function test_normaliser_handles_edge_inputs_without_throwing(): void
    {
        $normalize = $this->normalizer();
        foreach (['', ' ', "\t\n", '   ', "\u{0640}", "\u{064B}"] as $edge) {
            $normalize($edge); // must not throw
        }

        $this->assertSame('', $normalize(''));
    }
}
