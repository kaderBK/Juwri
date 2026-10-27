<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Tests\TestCase;

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S2: the storefront webfont wiring.
 *
 * Unlike the opt-in font pairs, the Saudi Riyal face must ship on EVERY
 * storefront and its family must lead every font stack, or the price glyph
 * U+20C1 renders as a tofu box. This pins:
 *  1. the always-on @font-face declares both weights, scoped by unicode-range
 *     to exactly U+20C1 (so it never touches any other character);
 *  2. every font-pair stack now leads with 'Saudi Riyal';
 *  3. the always-on partial emits the face independent of any selected pair;
 *  4. both storefront layouts include the partial and lead their body font
 *     stack with 'Saudi Riyal'.
 */
class SaudiRiyalStorefrontFontTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FontPairs::flush();
    }

    public function test_riyal_face_css_declares_both_weights_scoped_to_u20c1(): void
    {
        $css = FontPairs::riyalFaceCss();

        // Four faces now: SAR 400 + SAR 700 (U+20C1) + dirham 400 + dirham 700
        // (U+20C3), all under the one shared 'Saudi Riyal' glyph family. The dirham
        // MUST be static-weight like its siblings, never a font-weight range, or
        // Chrome mis-selects the face and paints tofu for U+20C3 (verified live).
        $this->assertSame(4, substr_count($css, '@font-face'), 'SAR 400/700 + dirham 400/700 faces must be declared');
        $this->assertStringNotContainsString('font-weight:100 900', $css, 'a weight RANGE beside static siblings breaks U+20C3 selection in Chrome');
        $this->assertStringContainsString("font-family:'Saudi Riyal'", $css);
        $this->assertStringContainsString('unicode-range:U+20C1;', $css);
        $this->assertStringContainsString('unicode-range:U+20C3;', $css);
        $this->assertStringContainsString('font-weight:400;', $css);
        $this->assertStringContainsString('font-weight:700;', $css);
        $this->assertStringContainsString('font-display:swap;', $css);

        foreach (FontPairs::riyalFiles() as $file) {
            $this->assertStringContainsString(asset($file), $css, "the [{$file}] face must be emitted");
        }

        // The whole point of unicode-range: the face must NOT claim any range
        // beyond the single riyal codepoint, or it would repaint other text.
        $this->assertStringNotContainsString('U+0600', $css);
        $this->assertStringNotContainsString('U+0000', $css);
    }

    public function test_every_pair_stack_leads_with_saudi_riyal(): void
    {
        $this->assertNotEmpty(FontPairs::all());

        foreach (FontPairs::ids() as $id) {
            $stacks = FontPairs::stacks($id);
            $this->assertNotSame([], $stacks, "[{$id}] must yield both stacks");
            foreach (['heading', 'body'] as $slot) {
                $this->assertStringStartsWith(
                    "'Saudi Riyal', ",
                    $stacks[$slot],
                    "[{$id}] --tf-{$slot} must lead with 'Saudi Riyal' so U+20C1 renders"
                );
                // …and it must still keep the theme's own type after it.
                $this->assertStringContainsString(FontPairs::arabicFamily(), $stacks[$slot]);
            }
        }
    }

    public function test_the_always_on_partial_emits_the_face_regardless_of_pair(): void
    {
        // No post, no selected pair — the always-on partial must still emit the face.
        $html = view('store_templates._partials.riyal-font')->render();

        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString("font-family:'Saudi Riyal'", $html);
        $this->assertStringContainsString('unicode-range:U+20C1;', $html);
        $this->assertStringContainsString('nonce=', $html, 'the style tag must carry a CSP nonce');
    }

    public function test_both_storefront_layouts_include_the_riyal_partial(): void
    {
        foreach (['layout.blade.php', 'pageview/layout.blade.php'] as $file) {
            $source = (string) file_get_contents(resource_path('views/store_templates/'.$file));
            $this->assertStringContainsString(
                "@include('store_templates._partials.riyal-font')",
                $source,
                "[{$file}] must include the always-on Saudi Riyal @font-face partial"
            );
        }
    }

    /**
     * The 12 modern themes whose price cells inherit font-family from body are
     * covered by the body/--menu-font prepend. These 5 route their price/total
     * font through a theme-specific display/heading var (distinct from body and
     * --menu-font), so U+20C1 in a price would fall back to tofu unless the var
     * itself leads with 'Saudi Riyal'. This pins that prepend in each style.css.
     */
    public function test_themes_that_hardcode_a_price_font_var_lead_it_with_saudi_riyal(): void
    {
        $vars = [
            'aurora/css/style.css' => '--au-display:',
            'vivid/css/style.css' => '--vd-display:',
            'feast/css/style.css' => '--ff-font-headings:',
            'velvet/css/style.css' => '--v-font-headings:',
            'plate/css/style.css' => '--font-display:',
        ];

        foreach ($vars as $file => $var) {
            $css = (string) file_get_contents(base_path('../assets/store_templates/'.$file));
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($var, '/').'\s*\x27Saudi Riyal\x27,/',
                $css,
                "[{$file}] {$var} must lead with 'Saudi Riyal' so U+20C1 renders in prices/totals"
            );
        }

        // aurora's account page uses a literal font on the container that wraps
        // the order totals — the one non-var override.
        $account = (string) file_get_contents(resource_path('views/store_templates/aurora/account.blade.php'));
        $this->assertStringContainsString("font-family: 'Saudi Riyal', 'Inter', sans-serif;", $account);
    }

    /**
     * Every theme routes text through `--font-*: var(--tf-body|heading, <fallback>)`.
     * `--tf-*` is set only when the store picked a font pair (and S2 prefixes it),
     * but a store with NO pair (the default for many Arabic/SAR stores) falls to the
     * hardcoded fallback — which must ALSO lead with 'Saudi Riyal' or U+20C1 renders
     * as tofu. Regression for the live "tofu on crave prices" bug: the fallback, not
     * just the pair token, has to carry the riyal face.
     */
    public function test_every_theme_font_var_fallback_leads_with_saudi_riyal(): void
    {
        $dir = base_path('../assets/store_templates');
        $themes = glob($dir.'/*/css/style.css') ?: [];
        $this->assertNotEmpty($themes);

        foreach ($themes as $file) {
            $css = (string) file_get_contents($file);
            // Match each base font-var definition whose VALUE starts with var(--tf-body|heading, ...).
            preg_match_all('/--[\w-]*font[\w-]*\s*:\s*var\(--tf-(?:body|heading),\s*([^;]+);/', $css, $m);
            foreach ($m[1] as $i => $fallback) {
                $this->assertStringContainsString(
                    "'Saudi Riyal'",
                    $m[0][$i],
                    basename(dirname(dirname($file)))." font-var fallback must lead with 'Saudi Riyal' (U+20C1 else tofu when no font pair set): {$m[0][$i]}"
                );
            }
        }
    }

    public function test_the_shared_layout_leads_the_body_and_menu_font_stacks_with_saudi_riyal(): void
    {
        $layout = (string) file_get_contents(resource_path('views/store_templates/layout.blade.php'));

        // Body face, both language branches.
        $this->assertStringContainsString("font-family: 'Saudi Riyal', tajawal", $layout, 'Arabic body stack must lead with Saudi Riyal');
        $this->assertStringContainsString("font-family: 'Saudi Riyal', 'Roboto', sans-serif;", $layout, 'default body stack must lead with Saudi Riyal');

        // The --menu-font variable (what price-bearing elements read).
        $this->assertStringContainsString("--menu-font: 'Saudi Riyal', 'StoreFont'", $layout);
    }
}
