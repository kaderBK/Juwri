<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Juwri\Modules\Media\Services\StorageService;
use Tests\TestCase;

/**
 * S11 — the SVG sanitizer must strip executable/fetching nodes the old regex
 * chain missed (<style>@import, external <use>, CDATA-encoded onload) while
 * leaving benign SVG intact.
 */
class SvgSanitizerTest extends TestCase
{
    public function test_strips_script(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect/></svg>'
        );
        $this->assertStringNotContainsStringIgnoringCase('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
    }

    public function test_strips_event_handlers(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect onload="alert(1)" onclick="x()"/></svg>'
        );
        $this->assertStringNotContainsStringIgnoringCase('onload', $out);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $out);
    }

    public function test_strips_style_import_and_external_use(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            .'<style>@import url(https://evil.test/x.css);</style>'
            .'<use xlink:href="https://evil.test/x.svg#a"/>'
            .'<rect/></svg>'
        );
        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function test_strips_cdata_encoded_payload(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><script><![CDATA[alert(document.cookie)]]></script></svg>'
        );
        $this->assertStringNotContainsString('document.cookie', $out);
        $this->assertStringNotContainsStringIgnoringCase('<script', $out);
    }

    public function test_benign_logo_survives(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#f00"/></svg>'
        );
        $this->assertStringContainsString('<circle', $out);
        $this->assertStringContainsString('r="4"', $out);
    }

    // ---- P6-37: svg-sanitize 1.0.0 (CVE-2026-107379/107380/107381) ----

    public function test_strips_javascript_href_and_xlink_href(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            .'<a href="javascript:alert(1)"><rect/></a>'
            .'<a xlink:href="JaVaScRiPt:alert(2)"><rect/></a>'
            .'<image xlink:href="https://evil.test/p.png"/>'
            .'</svg>'
        );
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $out);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function test_strips_mixed_case_external_use_href(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:XLink="http://www.w3.org/1999/xlink">'
            .'<use XLink:HREF="https://evil.test/x.svg#a"/><use href="https://evil.test/y.svg#b"/><rect/></svg>'
        );
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function test_strips_foreign_object_html(): void
    {
        $out = StorageService::sanitizeSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="10" height="10">'
            .'<div xmlns="http://www.w3.org/1999/xhtml"><iframe src="javascript:alert(1)"></iframe><img src="x" onerror="alert(1)"/></div>'
            .'</foreignObject><rect/></svg>'
        );
        $this->assertStringNotContainsStringIgnoringCase('foreignObject', $out);
        $this->assertStringNotContainsStringIgnoringCase('iframe', $out);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $out);
    }

    public function test_dtd_entity_html5_collision_payload_is_rejected(): void
    {
        // CVE-2026-107380: <!ENTITY Tab "#"> → &Tab; collision. 1.0 strips the
        // DTD, so the entity is undefined and the file is rejected outright.
        $out = StorageService::sanitizeSvg(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY Tab "javascript:alert(1)">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><a href="&Tab;"><rect/></a></svg>'
        );
        $this->assertSame('', $out);
    }

    public function test_billion_laughs_is_bounded(): void
    {
        $dtd = '<!ENTITY a0 "lol">';
        for ($i = 1; $i <= 10; $i++) {
            $p = 'a'.($i - 1);
            $dtd .= '<!ENTITY a'.$i.' "'.str_repeat('&'.$p.';', 10).'">';
        }
        $start = microtime(true);
        $out = StorageService::sanitizeSvg(
            '<?xml version="1.0"?><!DOCTYPE svg ['.$dtd.']><svg xmlns="http://www.w3.org/2000/svg"><text>&a10;</text></svg>'
        );
        $this->assertSame('', $out);
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function test_dtd_attribute_declaration_does_not_crash(): void
    {
        // CVE-2026-107379: DTD ATTLIST used to crash the sanitizer.
        $out = StorageService::sanitizeSvg(
            '<!DOCTYPE svg [<!ATTLIST rect onload CDATA "alert(1)">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'
        );
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringNotContainsStringIgnoringCase('onload', $out);
        $this->assertStringNotContainsStringIgnoringCase('DOCTYPE', $out);
    }

    public function test_deep_nesting_is_bounded(): void
    {
        $depth = 5000;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg">'.str_repeat('<g>', $depth).str_repeat('</g>', $depth).'</svg>';
        $start = microtime(true);
        $out = StorageService::sanitizeSvg($svg);
        $this->assertLessThan(5.0, microtime(true) - $start);
        // libxml's default depth limit (no PARSEHUGE) rejects it.
        $this->assertSame('', $out);
    }

    public function test_use_reference_fanout_is_bounded(): void
    {
        // Nested <use> chains (lol-style) must not expand; the sanitizer caps them.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><g id="a0"><rect/></g>';
        for ($i = 1; $i <= 20; $i++) {
            $svg .= '<g id="a'.$i.'">'.str_repeat('<use xlink:href="#a'.($i - 1).'"/>', 10).'</g>';
        }
        $svg .= '</svg>';
        $start = microtime(true);
        $out = StorageService::sanitizeSvg($svg);
        $this->assertLessThan(5.0, microtime(true) - $start);
        $this->assertLessThan(substr_count($svg, '<use'), substr_count($out, '<use'));
    }

    public function test_benign_illustrator_export_with_public_doctype_survives(): void
    {
        $out = StorageService::sanitizeSvg(
            '<?xml version="1.0" encoding="utf-8"?>'
            .'<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
            .'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 20 20">'
            .'<defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs>'
            .'<path d="M0 0h20v20H0z" fill="url(#g)"/><use xlink:href="#g"/></svg>'
        );
        $this->assertStringContainsString('<path', $out);
        $this->assertStringContainsString('d="M0 0h20v20H0z"', $out);
        $this->assertStringContainsString('url(#g)', $out);
        $this->assertStringContainsString('<linearGradient', $out);
    }
}
