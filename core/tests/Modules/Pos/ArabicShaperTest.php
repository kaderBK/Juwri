<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Juwri\Modules\Pos\Services\Printing\ArabicShaper;
use PHPUnit\Framework\TestCase;

/**
 * PARITY-RUN T1-2. The shaper is the difference between "the printer emitted
 * Arabic bytes" and "the customer can read the receipt", so every joining case
 * gets an assertion rather than a smoke test.
 */
class ArabicShaperTest extends TestCase
{
    public function test_latin_text_is_untouched(): void
    {
        $this->assertSame('Order #12 — Total 40.00', ArabicShaper::shape('Order #12 — Total 40.00'));
        $this->assertFalse(ArabicShaper::isRtl('Order #12'));
    }

    public function test_detects_arabic_and_hebrew(): void
    {
        $this->assertTrue(ArabicShaper::isRtl('طلب'));
        $this->assertTrue(ArabicShaper::isRtl('חשבון'));
        $this->assertTrue(ArabicShaper::isRtl('Total: مجموع'));
    }

    /**
     * ب (dual-joining) + ي (dual) + ت (dual): initial + medial + final. Getting
     * this wrong is exactly what makes raw passthrough unreadable.
     */
    public function test_contextual_forms_join_a_three_letter_word(): void
    {
        $shaped = ArabicShaper::contextualize('بيت'); // "house"

        $this->assertSame(
            "\u{FE91}\u{FEF4}\u{FE96}",  // beh-initial, yeh-medial, teh-final
            $shaped
        );
    }

    /** A right-joining letter (ر) breaks the group: the letter after it is initial. */
    public function test_right_joining_letter_breaks_the_joining_group(): void
    {
        $shaped = ArabicShaper::contextualize('برب');

        // beh-initial, reh-final, beh-ISOLATED (reh cannot join forward)
        $this->assertSame("\u{FE91}\u{FEAE}\u{FE8F}", $shaped);
    }

    /** A single letter with no neighbours stays isolated. */
    public function test_single_letter_is_isolated(): void
    {
        $this->assertSame("\u{FE8F}", ArabicShaper::contextualize('ب'));
    }

    /** LAM + ALEF is one glyph, not two — every Arabic reader notices the difference. */
    public function test_lam_alef_becomes_a_ligature(): void
    {
        $this->assertSame("\u{FEFB}", ArabicShaper::contextualize('لا'));       // isolated ligature
        $this->assertStringContainsString("\u{FEFC}", ArabicShaper::contextualize('بلا')); // final ligature after beh
    }

    public function test_tashkeel_is_stripped(): void
    {
        // A thermal head at 203 dpi smears combining marks into the letter.
        $this->assertSame('سلام', ArabicShaper::stripTashkeel("سَلاَم"));
    }

    /** Arabic runs flip; Latin/numeric islands keep their reading order. */
    public function test_reorder_reverses_rtl_runs_but_not_numbers(): void
    {
        $out = ArabicShaper::reorder('اب 12');

        // The digits survive as "12", not "21".
        $this->assertStringContainsString('12', $out);
        // …and the Arabic run is reversed relative to its logical order.
        $this->assertSame('12 با', $out);
    }

    /** shape() = strip + contextualize + reorder, and it is idempotent on Latin. */
    public function test_shape_is_the_full_pipeline(): void
    {
        $shaped = ArabicShaper::shape('بيت');
        $this->assertSame("\u{FE96}\u{FEF4}\u{FE91}", $shaped); // reversed for display
        $this->assertNotSame('بيت', $shaped);
    }

    public function test_empty_and_whitespace_are_safe(): void
    {
        $this->assertSame('', ArabicShaper::shape(''));
        $this->assertSame('   ', ArabicShaper::shape('   '));
    }
}
