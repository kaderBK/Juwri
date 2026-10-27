<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Juwri\Core\Http\Middleware\AddSecurityHeaders;
use Juwri\Core\Http\Middleware\BlockBadBots;
use Juwri\Core\Http\Middleware\ServePublicSvg;
use Tests\TestCase;

/**
 * P6-147: OpenLiteSpeed honours only the rewrite rules of .htaccess, so every security header, the
 * UA blocklist and the uploaded-SVG sandbox CSP must also come from the app, with the same values.
 */
class AppLevelSecurityHeadersTest extends TestCase
{
    private string $svgDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->svgDir = public_path('storage/p6147-test-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->svgDir);
        parent::tearDown();
    }

    private function htaccess(): string
    {
        return (string) file_get_contents(dirname(base_path()).'/.htaccess');
    }

    private function assertBaseline($resp): void
    {
        foreach (AddSecurityHeaders::BASELINE as $name => $value) {
            $resp->assertHeader($name, $value);
        }
    }

    public function test_login_page_carries_every_app_level_header(): void
    {
        $resp = $this->get('/login');
        $this->assertBaseline($resp);
        $resp->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $resp->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_only_on_https(): void
    {
        $mw = new AddSecurityHeaders;
        $http = $mw->handle(Request::create('http://shop.test/x'), fn () => response('x'));
        $this->assertFalse($http->headers->has('Strict-Transport-Security'), 'no HSTS over plain HTTP');
        $https = $mw->handle(Request::create('https://shop.test/x'), fn () => response('x'));
        $this->assertSame(AddSecurityHeaders::HSTS, $https->headers->get('Strict-Transport-Security'));
    }

    public function test_storefront_and_json_and_404_get_the_baseline(): void
    {
        $store = $this->get('/no-such-store-'.uniqid());
        $this->assertBaseline($store);
        $this->assertFalse($store->headers->has('X-Frame-Options'), 'storefront stays embeddable');

        $this->assertBaseline($this->getJson('/api/no-such-endpoint-'.uniqid()));
        $this->assertBaseline($this->get('/robots.txt'));
    }

    public function test_route_set_header_is_not_overwritten(): void
    {
        $resp = (new AddSecurityHeaders)->handle(
            Request::create('/x'),
            fn () => response('x')->header('Referrer-Policy', 'no-referrer')
        );
        $this->assertSame('no-referrer', $resp->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
    }

    public function test_values_match_the_htaccess(): void
    {
        $ht = $this->htaccess();
        foreach (AddSecurityHeaders::BASELINE as $name => $value) {
            $this->assertStringContainsString('Header always set '.$name.' "'.$value.'"', $ht, "$name differs from .htaccess");
        }
        $this->assertStringContainsString('Strict-Transport-Security "'.AddSecurityHeaders::HSTS.'"', $ht);
        $this->assertStringContainsString('Header set Content-Security-Policy "'.ServePublicSvg::CSP.'"', $ht);
        $this->assertStringContainsString('RewriteRule ^storage/.+\.svg$ index.php [NC,L]', $ht);
        // The storefront must stay embeddable: framing headers come from the app only.
        $this->assertDoesNotMatchRegularExpression('/^\s*Header\b.*X-Frame-Options/mi', $ht);
    }

    public function test_bot_list_matches_htaccess_and_blocks(): void
    {
        preg_match_all('/SetEnvIfNoCase User-Agent "([^"]+)" spammer=yes/', $this->htaccess(), $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $ua) {
            $this->assertContains($ua, BlockBadBots::USER_AGENTS, "$ua missing from BlockBadBots");
        }

        $this->get('/login', ['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0)'])->assertStatus(403);
        $this->post('/login', [], ['User-Agent' => 'mozilla/5.0 ahrefsbot/7.0'])->assertStatus(403);
        $this->get('/login', ['User-Agent' => 'Mozilla/5.0 (compatible; meta-externalads/1.1)'])->assertStatus(403);
        $this->assertNotSame(403, $this->get('/login', ['User-Agent' => 'facebookexternalhit/1.1'])->status());
        $this->assertNotSame(403, $this->get('/login', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130'])->status());
    }

    public function test_storage_svg_is_served_with_sandbox_csp(): void
    {
        File::ensureDirectoryExists($this->svgDir);
        file_put_contents($this->svgDir.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');
        $rel = 'storage/'.basename($this->svgDir).'/logo.svg';

        $resp = $this->get('/'.$rel);
        $resp->assertOk();
        $resp->assertHeader('Content-Security-Policy', ServePublicSvg::CSP);
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('image/svg+xml', (string) $resp->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', (string) $resp->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('frame-ancestors', (string) $resp->headers->get('Content-Security-Policy'));
    }

    public function test_svg_serving_stays_inside_public_storage(): void
    {
        $this->assertNull(ServePublicSvg::resolve('storage/../index.php'));
        $this->assertNull(ServePublicSvg::resolve('storage/no-such-'.uniqid().'.svg'));
        $this->assertNull(ServePublicSvg::resolve("storage/x.svg\0.png"));
        File::ensureDirectoryExists($this->svgDir);
        file_put_contents($this->svgDir.'/a.txt', 'x');
        $this->assertNull(ServePublicSvg::resolve('storage/'.basename($this->svgDir).'/a.txt'));
        // A path that escapes storage via .. is never served as an SVG by this middleware.
        $this->assertNotSame(ServePublicSvg::CSP, $this->get('/storage/%2e%2e/assets/brand/favicon.svg')->headers->get('Content-Security-Policy'));
    }
}
