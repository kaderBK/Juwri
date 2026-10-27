<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S1: the font asset is committed and real.
 *
 * The whole rollout hinges on one thing: a self-hosted webfont whose glyph at
 * U+20C1 actually renders. If the woff2 is missing, empty, or not a woff2 at all,
 * every SAR storefront paints a tofu box and nothing else in the stack notices.
 *
 * This test pins:
 *  1. both weights (400 + 700) exist on disk and are non-empty;
 *  2. they carry the WOFF2 magic bytes `wOF2` (a truncated/HTML-error download fails here);
 *  3. the SIL OFL 1.1 license text ships alongside them (attribution is required).
 *
 * The cmap-maps-U+20C1 verification is recorded in PROGRESS.md (needs fontTools,
 * not available in the CI image) — this test guards the on-disk artefact.
 */
class SaudiRiyalFontAssetTest extends TestCase
{
    private const FONT_DIR = 'assets/fonts';

    /** repo-root relative -> the two shipped weights. */
    private const WEIGHTS = [
        'saudi-riyal-400.woff2',
        'saudi-riyal-700.woff2',
    ];

    private function repoPath(string $relative): string
    {
        // base_path() is core/; the font assets live at the repo root, one level up.
        return base_path('../'.$relative);
    }

    public function test_both_riyal_woff2_weights_exist_and_are_non_empty(): void
    {
        foreach (self::WEIGHTS as $file) {
            $path = $this->repoPath(self::FONT_DIR.'/'.$file);
            $this->assertFileExists($path, "[{$file}] must be committed to assets/fonts");
            $this->assertGreaterThan(
                0,
                (int) filesize($path),
                "[{$file}] is empty — a zero-byte font loads nothing"
            );
        }
    }

    public function test_both_weights_carry_the_woff2_magic_bytes(): void
    {
        foreach (self::WEIGHTS as $file) {
            $path = $this->repoPath(self::FONT_DIR.'/'.$file);
            $magic = (string) file_get_contents($path, false, null, 0, 4);
            $this->assertSame(
                'wOF2',
                $magic,
                "[{$file}] does not start with the WOFF2 magic bytes 'wOF2' — "
                .'the download is corrupt or is an HTML error page, not a font.'
            );
        }
    }

    public function test_the_ofl_license_ships_with_the_font(): void
    {
        $path = $this->repoPath(self::FONT_DIR.'/SAUDI-RIYAL-OFL.txt');
        $this->assertFileExists($path, 'the SIL OFL 1.1 license text must ship with the font (attribution required)');
        $this->assertStringContainsString(
            'SIL OPEN FONT LICENSE',
            (string) file_get_contents($path),
            'the committed license must be the SIL Open Font License'
        );
    }
}
