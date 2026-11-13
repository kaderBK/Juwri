<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Currency-symbol font gate — S12.
 *
 * A currency may ONLY adopt its new dedicated codepoint in the SAME commit that
 * ships a cmap-verified, license-clean webfont for it (HARD GATE, locked §1) —
 * otherwise every storefront on that currency renders a tofu box.
 *
 * AED's gate LIFTED 2026-08-12 when an MIT-licensed U+20C3 webfont was vendored
 * (see AedDirhamSymbolTest). OMR (U+20C4) is still blocked: no redistributable
 * font maps it yet, so U+20C4 must appear NOWHERE. This test is the standing
 * guard keeping OMR on its look-alike sign until that font lands.
 */
class SaudiRiyalFontGateTest extends TestCase
{
    public function test_omr_stays_gated_and_aed_now_ships_its_own_sign(): void
    {
        $map = currency_symbol_map();
        $this->assertSame('ر.ع.', $map['OMR'], 'OMR must stay on ر.ع. until a U+20C4 font ships');
        $this->assertSame("\u{20C3}", $map['AED'], 'AED adopted its U+20C3 sign once the font shipped');
    }

    public function test_the_still_gated_omr_codepoint_appears_nowhere_in_the_map(): void
    {
        $blob = implode('', array_keys(currency_symbol_map())).implode('', currency_symbol_map());

        $this->assertStringNotContainsString("\u{20C4}", $blob, 'U+20C4 (OMR) is font-gated and must not be shipped');
    }

    public function test_no_omr_webfont_was_committed(): void
    {
        // Lifts to a real asset check when a U+20C4 font is finally sourced.
        $this->assertFileDoesNotExist(base_path('../assets/fonts/omani-rial-400.woff2'));
    }
}
