<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Support\Csp\ApiDocsPolicy;
use Tests\TestCase;

/**
 * P6-132: /docs/api (Scramble + the Stoplight Elements web component) is served with an ENFORCED policy of its own:
 * the inline bootstrap runs by nonce, code and styles load only from 'self' and the pinned Elements package path, and the
 * "Try it" console may only call this origin.
 */
class ApiDocsCspTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['csp.enabled' => true, 'api.docs_public' => true]);
    }

    /** @return array<string, string> directive => value */
    private function directives(string $csp): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $part) {
            [$name, $value] = array_pad(explode(' ', $part, 2), 2, '');
            $out[$name] = $value;
        }

        return $out;
    }

    public function test_the_docs_page_carries_its_own_enforced_policy(): void
    {
        $res = $this->get('/docs/api')->assertOk();
        $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'));
        $d = $this->directives((string) $res->headers->get('Content-Security-Policy'));

        $this->assertMatchesRegularExpression("/^'self' ".preg_quote(ApiDocsPolicy::ELEMENTS, '/')." 'nonce-[A-Za-z0-9+\\/=]+'$/", $d['script-src']);
        $this->assertSame("'self'", $d['connect-src'], 'Try it calls this origin only');
        $this->assertSame("'none'", $d['default-src']);
        $this->assertSame("'none'", $d['object-src']);
        $this->assertSame("'none'", $d['script-src-attr']);
        $this->assertSame("'none'", $d['base-uri']);
        $this->assertStringContainsString("'nonce-", $d['style-src']);
        $this->assertStringNotContainsString("'unsafe-inline'", $d['style-src']);
        $this->assertStringContainsString("'".ApiDocsPolicy::ICON_FONT_STYLE."'", $d['style-src'], 'the bundle-injected icon sheet, by hash');
        foreach ($d as $name => $value) {
            $this->assertStringNotContainsString("'unsafe-eval'", $value, $name);
            $this->assertDoesNotMatchRegularExpression("/'sha256-(?!MBVp6JYx)/", $value, $name.' allows no other hash');
            $this->assertDoesNotMatchRegularExpression('#https?://(?!unpkg\.com/@stoplight/elements@8\.4\.2/)#', $value, $name.' names no other host');
        }
    }

    public function test_every_inline_script_and_style_carries_the_header_nonce(): void
    {
        $res = $this->get('/docs/api')->assertOk();
        preg_match("/'nonce-([^']+)'/", (string) $res->headers->get('Content-Security-Policy'), $m);
        $html = (string) $res->getContent();

        preg_match_all('/<(script|style)\b([^>]*)>/i', $html, $tags, PREG_SET_ORDER);
        $this->assertNotEmpty($tags);
        foreach ($tags as [$tag, $name, $attrs]) {
            if (preg_match('/\bsrc="([^"]+)"/', $attrs, $src)) {
                // the pinned Elements bundle, or Scramble's own dev-tools module (same origin, only when dev tools are on)
                $this->assertTrue(str_starts_with($src[1], ApiDocsPolicy::ELEMENTS) || str_starts_with($src[1], url('/').'/'), $src[1]);

                continue;
            }
            if (preg_match('/type="application\/json"/', $attrs)) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$m[1].'"', $attrs, $tag);
        }
        $this->assertStringContainsString('<link rel="stylesheet" href="'.ApiDocsPolicy::ELEMENTS.'styles.min.css">', $html);
        $this->assertDoesNotMatchRegularExpression('/<body[^>]*\sstyle=/', $html);
    }

    public function test_the_route_is_still_gated(): void
    {
        config(['api.docs_public' => false]);
        $this->get('/docs/api')->assertForbidden();
    }
}
