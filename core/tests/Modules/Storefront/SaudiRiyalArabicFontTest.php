<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Tests\TestCase;

/**
 * Saudi Riyal Symbol rollout — S11: Arabic currency signs on the dashboard,
 * admin, and POS/KDS.
 *
 * Those surfaces render body text (prices) in a Latin-only font — "Instrument
 * Sans" (Bootstrap) on the shells, "Inter" on the POS screens. Arabic currency
 * signs (د.إ AED, ر.ق QAR, and the ﷼ ligature IRR/YER use) therefore fell to a
 * system Arabic fallback. This pins the fix: an always-on IBM Plex Sans Arabic
 * face scoped to the Arabic blocks, plus that family in each surface's stack.
 */
class SaudiRiyalArabicFontTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FontPairs::flush();
    }

    private function viewSrc(string $file): string
    {
        return (string) file_get_contents(resource_path('views/'.$file));
    }

    public function test_arabic_face_css_covers_the_arabic_currency_ranges(): void
    {
        $css = FontPairs::arabicFaceCss();

        $this->assertSame(2, substr_count($css, '@font-face'));
        $this->assertStringContainsString("font-family:'IBM Plex Sans Arabic'", $css);
        $this->assertStringContainsString('unicode-range:', $css);
        // The Arabic block (letter-signs like د إ ر) and the presentation-forms
        // block that holds the ﷼ ligature (U+FDFC) must both be covered.
        $this->assertStringContainsString('U+0600-06FF', $css);
        $this->assertStringContainsString('U+FB50-FDFF', $css);
        foreach (['assets/fonts/plex-arabic-400.woff2', 'assets/fonts/plex-arabic-700.woff2'] as $file) {
            $this->assertStringContainsString(asset($file), $css);
        }
    }

    public function test_the_shared_arabic_partial_emits_the_face(): void
    {
        $html = view('partials.arabic-font')->render();
        $this->assertStringContainsString("font-family:'IBM Plex Sans Arabic'", $html);
        $this->assertStringContainsString('@font-face', $html);
    }

    public function test_dashboard_and_admin_shells_load_the_arabic_face_and_stack_it(): void
    {
        foreach ([
            'components/ui/shell/head.blade.php',
            'admin/includes/styles.blade.php',
        ] as $file) {
            $src = $this->viewSrc($file);
            $this->assertStringContainsString("@include('partials.arabic-font')", $src, "[{$file}] must load the Arabic face");
            if ($file === 'admin/includes/styles.blade.php') {
                // WI-10: the admin is on the kit; its font is the kit stack (tokens.css
                // --ui-font), which carries the Arabic family.
                $this->assertStringContainsString('assets/owner-ui/tokens.css', $src);
                $this->assertMatchesRegularExpression("/--ui-font:[^;]*'IBM Plex Sans Arabic'/", (string) file_get_contents(public_path('assets/owner-ui/tokens.css')));

                continue;
            }
            if ($file === 'components/ui/shell/head.blade.php') {
                // WI-16a/16f: the owner shell maps the Bootstrap body font to the kit
                // stack, which carries the Arabic family (tokens.css --ui-font).
                $this->assertStringContainsString('--bs-body-font-family:var(--ui-font)', $src);
                $this->assertMatchesRegularExpression("/--ui-font:[^;]*'IBM Plex Sans Arabic'/", (string) file_get_contents(public_path('assets/owner-ui/tokens.css')));

                continue;
            }
            $this->assertStringContainsString('"IBM Plex Sans Arabic"', $src, "[{$file}] must add the Arabic family to --bs-body-font-family");
        }
    }

    public function test_pos_screens_load_the_arabic_face_and_stack_it(): void
    {
        foreach (['register', 'display', 'floor'] as $screen) {
            $src = $this->viewSrc('staff/pos/'.$screen.'.blade.php');
            $this->assertStringContainsString("@include('partials.arabic-font')", $src, "[staff/pos/{$screen}] must load the Arabic face");
        }

        // register reads its body stack from the stylesheet; display/floor inline.
        $regCss = (string) file_get_contents(base_path('../assets/pos/pos-register.css'));
        $this->assertStringContainsString("'IBM Plex Sans Arabic'", $regCss);
        foreach (['display', 'floor'] as $screen) {
            $this->assertStringContainsString(
                "'IBM Plex Sans Arabic'",
                $this->viewSrc('staff/pos/'.$screen.'.blade.php'),
                "[staff/pos/{$screen}] body stack must include the Arabic family"
            );
        }
    }
}
