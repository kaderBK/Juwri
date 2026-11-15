<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Juwri\Modules\Storefront\Services\Theme\DarkTokens;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\ShellStyles;
use Tests\TestCase;

/**
 * The platform's DARK TOKEN SET — the colours every Liquid storefront paints
 * when the diner picks dark mode.
 */
class StorefrontDarkTokensTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'app/marketplace-themes/darktokens';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        foreach (['plain' => [], 'own' => ['dark_tokens' => false]] as $slug => $extra) {
            $dir = storage_path($this->base.'-'.$slug.'/1');
            File::ensureDirectoryExists($dir.'/templates');
            File::put($dir.'/theme.json', json_encode([
                'name' => 'Dark '.ucfirst($slug), 'slug' => 'darktokens-'.$slug, 'version' => '1',
                'library' => '1',
                'tokens' => ['colors.background' => '#f3e9dd', 'colors.text' => '#272a32', 'colors.primary' => '#6d272b'],
            ] + $extra));
            File::put($dir.'/templates/index.json', json_encode(['sections' => [], 'order' => []]));
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path($this->base.'-plain'));
        File::deleteDirectory(storage_path($this->base.'-own'));
        parent::tearDown();
    }

    /** Pull a var's value out of a CSS block. */
    private function var(string $css, string $name): ?string
    {
        if (preg_match('/'.preg_quote($name, '/').':([^;]+);/', $css, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    public function test_the_dark_block_is_scoped_and_carries_the_colour_tokens(): void
    {
        $css = DarkTokens::css([]);

        $this->assertStringStartsWith(':root[data-theme="dark"]{', $css);
        $this->assertStringEndsWith('}', $css);
        foreach (['--tc-background', '--tc-surface', '--tc-text', '--tc-text-muted', '--tc-primary', '--tc-accent', '--tc-on-primary', '--tc-primary-rgb', '--tc-text-rgb'] as $name) {
            $this->assertNotNull($this->var($css, $name), $name.' is missing from the dark block');
        }
    }

    public function test_a_theme_with_no_palette_still_gets_a_dark_mode(): void
    {
        $css = DarkTokens::css([]);

        $background = $this->var($css, '--tc-background');
        $text = $this->var($css, '--tc-text');

        $this->assertNotNull($background);
        $this->assertNotNull($text);
        $this->assertLessThan(0.2, Tokens::relativeLuminance((string) $background));
        $this->assertGreaterThan(0.5, Tokens::relativeLuminance((string) $text));
    }

    public function test_the_dark_ground_keeps_the_theme_hue(): void
    {
        $warm = DarkTokens::css(['colors.background' => '#f3e9dd']);
        $cool = DarkTokens::css(['colors.background' => '#e6ecf5']);

        $warmBg = (string) $this->var($warm, '--tc-background');
        $coolBg = (string) $this->var($cool, '--tc-background');

        [$wr, $wg, $wb] = Tokens::rgb($warmBg);
        [$cr, $cg, $cb] = Tokens::rgb($coolBg);

        $this->assertGreaterThan($wb, $wr, 'the warm theme lost its hue');
        $this->assertGreaterThan($cr, $cb, 'the cool theme lost its hue');
        $this->assertNotSame($warmBg, $coolBg);
    }

    public function test_a_brand_colour_that_would_vanish_is_lifted(): void
    {
        $css = DarkTokens::css(['colors.primary' => '#6d272b']);
        $primary = (string) $this->var($css, '--tc-primary');

        $this->assertGreaterThan(Tokens::relativeLuminance('#6d272b'), Tokens::relativeLuminance($primary));
        $this->assertSame('#111111', $this->var($css, '--tc-on-primary'));
    }

    public function test_a_brand_colour_that_already_reads_is_kept(): void
    {
        $css = DarkTokens::css(['colors.primary' => '#3fd463']);

        $this->assertSame('#3fd463', $this->var($css, '--tc-primary'));
    }

    public function test_the_ramps_invert_with_the_dark_ground(): void
    {
        $css = DarkTokens::css(['colors.primary' => '#6d272b']);
        $primary = (string) $this->var($css, '--tc-primary');
        $stop600 = (string) $this->var($css, '--tc-primary-600');
        $stop700 = (string) $this->var($css, '--tc-primary-700');

        $this->assertGreaterThan(Tokens::relativeLuminance($primary), Tokens::relativeLuminance($stop600));
        $this->assertGreaterThan(Tokens::relativeLuminance($stop600), Tokens::relativeLuminance($stop700));
    }

    public function test_an_unsafe_value_never_reaches_the_stylesheet(): void
    {
        $css = DarkTokens::css([
            'colors.background' => '#fff;}body{display:none',
            'colors.text' => 'red',
        ]);

        $this->assertStringNotContainsString('display:none', $css);
        $this->assertStringNotContainsString(';}', substr($css, 0, 40));
        $this->assertNotNull($this->var($css, '--tc-background'));
    }

    public function test_the_shell_emits_the_dark_block_for_a_theme(): void
    {
        $theme = MarketplaceThemeResolver::resolve('mp:darktokens-plain@1');
        $this->assertNotNull($theme);

        $styles = ShellStyles::for($theme);

        $this->assertStringStartsWith(':root[data-theme="dark"]{', $styles['dark_css']);
        $this->assertStringContainsString('--tc-background', $styles['dark_css']);
    }

    public function test_a_theme_that_paints_its_own_dark_palette_keeps_it(): void
    {
        $theme = MarketplaceThemeResolver::resolve('mp:darktokens-own@1');
        $this->assertNotNull($theme);

        $styles = ShellStyles::for($theme);

        $this->assertSame('', $styles['dark_css']);
    }
}
