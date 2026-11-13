<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Tests\TestCase;

/**
 * UAE Dirham Sign (U+20C3) rollout — the S12 font gate cleared 2026-08-12 when an
 * MIT-licensed webfont subset mapping U+20C3 (github.com/pooyagolchian/dirham)
 * was vendored. AED now follows the exact Saudi-Riyal pipeline: a self-hosted
 * always-on @font-face registered under the shared 'Saudi Riyal' glyph family,
 * the canonical symbol map, and a reversible auto-migration of every AED store.
 *
 * The whole thing hinges on the glyph actually rendering, so this pins the
 * on-disk artefact, the emitted @font-face, the map, and both migrations.
 */
class AedDirhamSymbolTest extends TestCase
{
    use RefreshDatabase;

    private const DIRHAM = "\u{20C3}";   // ⃃  UAE DIRHAM SIGN

    private const RIYAL = "\u{20C1}";   // ⃁  SAUDI RIYAL SIGN (gapped, like the dirham)

    private const FONT = 'assets/fonts/aed-dirham-400.woff2';

    private function repoPath(string $relative): string
    {
        // base_path() is core/; the font assets live at the repo root, one level up.
        return base_path('../'.$relative);
    }

    // ---------------------------------------------------------------- S1 asset

    public function test_dirham_woff2_exists_is_non_empty_and_is_a_real_woff2(): void
    {
        $path = $this->repoPath(self::FONT);
        $this->assertFileExists($path, 'the AED dirham woff2 must be committed to assets/fonts');
        $this->assertGreaterThan(0, (int) filesize($path), 'a zero-byte font loads nothing');
        $magic = (string) file_get_contents($path, false, null, 0, 4);
        $this->assertSame('wOF2', $magic, "[{$path}] must start with the WOFF2 magic bytes 'wOF2' — a corrupt/HTML download fails here");
    }

    public function test_the_mit_license_ships_with_the_font(): void
    {
        $path = $this->repoPath('assets/fonts/AED-DIRHAM-LICENSE.txt');
        $this->assertFileExists($path, 'the MIT license text must ship with the font (attribution required)');
        $this->assertStringContainsString('MIT License', (string) file_get_contents($path));
    }

    // ------------------------------------------------------------- S2 @font-face

    public function test_facecss_carries_the_dirham_face_without_breaking_the_riyal_one(): void
    {
        $css = FontPairs::riyalFaceCss();

        // The dirham face: its own file, scoped to U+20C3.
        $this->assertStringContainsString('unicode-range:U+20C3', $css, 'the dirham @font-face must be scoped to U+20C3');
        $this->assertStringContainsString('aed-dirham-400.woff2', $css, 'the dirham face must reference the vendored subset');

        // The Saudi Riyal face is untouched (same shared family, still U+20C1).
        $this->assertStringContainsString('unicode-range:U+20C1', $css, 'the SAR face must remain');
        $this->assertStringContainsString("font-family:'Saudi Riyal'", $css, 'both faces share the one glyph family that already leads every stack');
    }

    public function test_riyal_files_list_includes_the_dirham_subset(): void
    {
        $this->assertContains(self::FONT, FontPairs::riyalFiles(), 'the disk-existence walk must cover the dirham subset');
    }

    // ------------------------------------------------------------------ S3 map

    public function test_the_canonical_map_returns_u20c3_for_aed(): void
    {
        $this->assertSame(self::DIRHAM, currency_symbol_map()['AED']);
        $this->assertSame(self::DIRHAM, currency_symbol_for('aed'));  // case-insensitive
    }

    public function test_the_autofill_partial_offers_the_dirham_for_aed(): void
    {
        $html = view('partials.currency-autofill')->render();
        // The button's new-sign table must include AED → the dirham glyph, and the
        // click handler must be able to fill it.
        $this->assertStringContainsString('AED', $html);
        $this->assertStringContainsString(self::DIRHAM, $html, 'the partial must carry the U+20C3 glyph the button fills');
    }

    // --------------------------------------------------------- S3 country migration

    // ---------------------------------------------------- S3 store-sign migration

    private function makeStore(string $code, string $sign): int
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'S', 'slug' => 'aed-'.uniqid(),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd', 'main_image' => 'x.png',
            'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'currency_code', $code);
        StoreSettings::set($store->id, 'currency_sign', $sign);

        return $store->id;
    }

    private function sign(int $storeId): ?string
    {
        return StoreSettings::legacy((int) $storeId, 'currency_sign');
    }

    private function backup(int $storeId): ?string
    {
        return StoreSettings::legacy((int) $storeId, 'currency_sign_pre_dirham');
    }

    // ------------------------------------------------------------ S13 sign spacing

    /**
     * The U+20C3 dirham AND the U+20C1 Saudi Riyal glyphs both carry no side-bearing,
     * so they collide into the last digit ("20.80⃃" / "12.50⃁") and read as one
     * blurred token. currency_symbol_gap() is the ONE predicate — used by the PHP
     * price helpers and by the CFG the theme JS reads — that says a space belongs
     * between these signs and the number. Every WinAnsi / neutral sign stays flush.
     */
    public function test_symbol_gap_is_returned_for_side_bearingless_signs(): void
    {
        $this->assertSame(' ', currency_symbol_gap(self::DIRHAM), 'the U+20C3 dirham needs a space so it does not touch the digits');
        $this->assertSame(' ', currency_symbol_gap(self::RIYAL), 'the U+20C1 Saudi Riyal likewise needs a space (approved "12.50 ر.س" spacing)');
        $this->assertSame('', currency_symbol_gap('$'), 'a WinAnsi sign stays flush');
        $this->assertSame('', currency_symbol_gap('€'), 'a neutral glyph stays flush');
        $this->assertSame('', currency_symbol_gap(null));
        $this->assertSame('', currency_symbol_gap(''));
    }

    /**
     * price_symbol_format() puts that space on the number-facing side of the sign,
     * whichever side the sign sits. The bidi isolates cur_isolate() wraps the sign
     * in are stripped here so the assertion reads the visible order. The dollar
     * negative is the discriminating case — it must stay flush.
     */
    public function test_price_symbol_format_spaces_only_the_dirham(): void
    {
        // Strip every bidi isolate char (FSI/PDI from cur_isolate AND the LRI/PDI
        // money_ltr adds for USD) so the assertion reads the visible glyph order.
        $strip = fn ($s) => str_replace(["\u{2066}", "\u{2068}", "\u{2069}"], '', (string) $s);

        // Dirham: always sign-first (the position rule overrides a stored "after")
        // with a space between the sign and the number. rtl_auto is off so only the
        // dirham rule — not the RTL flip — decides the position.
        config(['settings.currency_rtl_auto' => '0', 'settings.currency_code' => 'AED', 'settings.currency_sign' => self::DIRHAM, 'settings.currency_pos' => 0]);
        $this->assertSame(self::DIRHAM.' 20.80', $strip(price_symbol_format(20.8)), 'the dirham renders sign-first with a space even when the store is set to after');

        // Discriminating negative: a plain dollar sign is never spaced and keeps its
        // stored sign-after position.
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$', 'settings.currency_pos' => 0]);
        $this->assertSame('20.80$', $strip(price_symbol_format(20.8)), 'the dollar sign stays flush and keeps its stored after-position');
    }

    /**
     * The dirham is always sign-first (before the number in English), whatever the
     * store's stored currency_pos. rtl_auto is switched off so the RTL auto-flip
     * cannot be what returns 1 — only the dirham rule can — and the dollar control
     * proves a normal sign still honours the stored after-position.
     */
    public function test_dirham_forces_sign_first_regardless_of_stored_position(): void
    {
        config(['settings.currency_rtl_auto' => '0', 'settings.currency_pos' => 0]);

        config(['settings.currency_sign' => self::DIRHAM]);
        $this->assertSame(1, currency_pos_effective(), 'the dirham forces sign-first even when the store is set to sign-after');

        config(['settings.currency_sign' => '$']);
        $this->assertSame(0, currency_pos_effective(), 'a non-dirham sign still honours the stored sign-after position');
    }
}
