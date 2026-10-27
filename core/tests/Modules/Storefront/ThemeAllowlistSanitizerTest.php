<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\ThemeOutputSanitizer;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-266: the allowlist theme-output sanitiser. Every XSS corpus vector (OWASP cheat sheet, mXSS, SVG, CSS,
 * phishing/redirect) is neutralised; every first-party theme renders byte-identical to its browser-normalised
 * raw output (nothing a first-party theme uses is stripped); CSS keeps allow-listed properties only.
 */
class ThemeAllowlistSanitizerTest extends TestCase
{
    /** @return array<string, array{0:string, 1:list<string>}> */
    public static function corpus(): array
    {
        $out = [];
        foreach (require dirname(__DIR__, 2).'/fixtures/xss-corpus.php' as $name => $case) {
            $out[(string) $name] = [$case['html'], $case['forbid']];
        }

        return $out;
    }

    /** @param list<string> $forbid */
    #[DataProvider('corpus')]
    public function test_corpus_vector_is_neutralised(string $html, array $forbid): void
    {
        $clean = ThemeOutputSanitizer::sanitize($html);
        // Re-parse what the browser would see: the output must also be stable (no mutation on a second pass).
        $this->assertSame($clean, ThemeOutputSanitizer::sanitize($clean), 'not idempotent: '.$html);
        foreach ($forbid as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean, $html.' => '.$clean);
        }
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $clean, $clean);
        $this->assertDoesNotMatchRegularExpression('/(java|vb)script:/i', ThemeOutputSanitizer::normalize($clean), $clean);
    }

    public function test_corpus_is_big_enough(): void
    {
        $this->assertGreaterThanOrEqual(120, count(self::corpus()));
    }

    public function test_css_allowlist_in_inline_style_and_style_sheet(): void
    {
        $inline = ThemeOutputSanitizer::sanitize('<p style="color:red; position:fixed; behavior:url(x.htc); --brand:#123; background:url(/a.png); background-image:url(javascript:alert(1)); width:expression(alert(1)); zoom:2">x</p>');
        $this->assertStringContainsString('color: red', $inline);
        $this->assertStringContainsString('--brand: #123', $inline);
        $this->assertStringContainsString('background: url(/a.png)', $inline);
        $this->assertStringContainsString('position: fixed', $inline);
        foreach (['behavior', 'javascript', 'expression', 'zoom'] as $bad) {
            $this->assertStringNotContainsString($bad, $inline);
        }

        $sheet = ThemeOutputSanitizer::sanitize('<style>@import url(https://evil.test/x.css); .a{color:blue;-moz-binding:url(x)} @media (min-width:600px){.b{margin:0;filter:blur(2px)}} @font-face{font-family:F;src:url(/f.woff2)} @page{margin:0} .c{background:url("java\73 script:alert(1)")}</style>');
        $this->assertStringContainsString('.a{color: blue}', $sheet);
        $this->assertStringContainsString('@media (min-width:600px){.b{margin: 0; filter: blur(2px)}}', $sheet);
        $this->assertStringContainsString('@font-face{font-family: F; src: url(/f.woff2)}', $sheet);
        foreach (['@import', 'evil.test', 'binding', '@page', 'java'] as $bad) {
            $this->assertStringNotContainsString($bad, $sheet);
        }
    }

    public function test_unknown_elements_unwrap_and_allowed_markup_is_kept(): void
    {
        $out = ThemeOutputSanitizer::sanitize('<blink><b data-mb-add="7" aria-label="Add">Buy</b></blink><svg viewBox="0 0 2 2" aria-hidden="true"><use href="#i"></use><path d="M0 0"></path></svg><a href="tel:+1" target="_blank" rel="noopener">c</a>');
        $this->assertStringContainsString('<b data-mb-add="7" aria-label="Add">Buy</b>', $out);
        $this->assertStringNotContainsString('blink', $out);
        $this->assertStringContainsString('<svg viewBox="0 0 2 2" aria-hidden="true"><use href="#i"></use><path d="M0 0"></path></svg>', $out);
        $this->assertStringContainsString('<a href="tel:+1" target="_blank" rel="noopener">c</a>', $out);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function firstPartyThemes(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 3).'/resources/liquid-themes/*/*/theme.json') ?: [] as $manifest) {
            $dir = dirname($manifest);
            foreach (['restaurant', 'retail'] as $vertical) {
                $out[basename(dirname($dir)).'@'.basename($dir).' '.$vertical] = [$dir, $vertical];
            }
        }

        return $out;
    }

    #[DataProvider('firstPartyThemes')]
    public function test_first_party_theme_renders_unchanged(string $dir, string $vertical): void
    {
        $theme = new MarketplaceTheme(basename(dirname($dir)), basename($dir), $dir);
        $vars = DemoStorefront::vars($vertical);
        $globals = (new ThemeContext)->build($vars);
        $registers = ['store_id' => 0, 'theme_locale_map' => $theme->locales('en')];

        $raw = new class(new LiquidEnvironmentFactory, new RenderClient) extends LiquidRenderer
        {
            protected function finishOutput(string $html): string
            {
                ThemeOutputSanitizer::abort();

                return $html; // the unsanitised render, for the snapshot
            }
        };
        $clean = new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient);

        if ($theme->isSectioned()) {
            $rawHtml = (new SectionedTemplate($raw))->render($theme, $globals, $registers, [], 'live');
            $cleanHtml = (new SectionedTemplate($clean))->render($theme, $globals, $registers, [], 'live');
        } else {
            $rawHtml = $raw->render($theme->templateSource(), $globals, 'live', $registers, $theme->snippets());
            $cleanHtml = $clean->render($theme->templateSource(), $globals, 'live', $registers, $theme->snippets());
        }

        $this->assertNotSame('', trim($rawHtml));
        $this->assertSame(ThemeOutputSanitizer::normalize($rawHtml), ThemeOutputSanitizer::normalize($cleanHtml));
    }
}
