<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\LockedTemplates\Manifest;
use Juwri\Modules\Content\Services\LockedTemplates\Slots;
use PHPUnit\Framework\TestCase;
use Tests\Support\LockedTemplateFixtures;

/**
 * Locked-template slot values are the ONLY user input on a locked page, so their
 * validators are the security boundary: URL slots must never accept script-bearing
 * schemes or attribute break-outs, and a manifest must never place a placeholder
 * where escaping cannot protect it.
 */
class LockedTemplateSlotsTest extends TestCase
{
    use LockedTemplateFixtures;

    public function test_link_href_allowlist(): void
    {
        foreach (['https://example.com/x?a=1#b', 'http://example.com', 'mailto:a@b.co', 'tel:+1 (555) 010-2030', '#pricing', '/login', '/page/about?x=1'] as $ok) {
            $this->assertSame($ok, Slots::cleanHref($ok), $ok);
        }
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<x>', 'vbscript:x', '//evil.example/x', 'https://x.com/"onmouseover="a', "https://x.com/\nfoo", 'https://x.com/<script>', '', str_repeat('a', 600)] as $bad) {
            $this->assertNull(Slots::cleanHref($bad), $bad);
        }
    }

    public function test_image_url_allowlist(): void
    {
        $this->assertSame('https://cdn.example.com/a.png', Slots::cleanImageUrl('https://cdn.example.com/a.png'));
        $this->assertSame('/storage/reseller/1/builder/a.webp', Slots::cleanImageUrl('/storage/reseller/1/builder/a.webp'));
        $this->assertSame('/assets/locked-templates/x/v1/media/a.svg', Slots::cleanImageUrl('/assets/locked-templates/x/v1/media/a.svg'));
        foreach (['http://insecure.example/a.png', 'javascript:alert(1)', 'data:image/svg+xml,<svg onload=x>', '/storage/../core/.env', '/etc/passwd', '//evil/a.png', 'https://x.com/a.png" onerror="x'] as $bad) {
            $this->assertNull(Slots::cleanImageUrl($bad), $bad);
        }
    }

    public function test_video_url_allowlist_and_placement(): void
    {
        $this->assertSame('/storage/reseller/1/builder/a.mp4', Slots::clean(['type' => 'video'], '/storage/reseller/1/builder/a.mp4'));
        $this->assertSame('https://cdn.example.com/v/a.webm?x=1', Slots::clean(['type' => 'video'], 'https://cdn.example.com/v/a.webm?x=1'));
        foreach (['/storage/a.png', 'javascript:alert(1).mp4', 'data:video/mp4;base64,AAAA', '//evil/a.mp4', 'http://x.com/a.mp4', '/storage/../x.mp4', 'https://x.com/a.mp4" onerror="x'] as $bad) {
            $this->assertNull(Slots::clean(['type' => 'video'], $bad), $bad);
        }
        $this->assertSame('image', Slots::capFor('video'));

        // A video slot only ever fills an attribute (src), never text.
        $m = $this->ltManifest();
        $m['sections'][1]['slots'][] = ['key' => 'hero.video1', 'type' => 'video', 'label' => 'Video', 'default' => '/assets/x.mp4'];
        $m['sections'][1]['html'] .= '<video><source src="{{slot:hero.video1}}" data-lt-video="hero.video1"></video>';
        $this->assertSame([], Manifest::validate($m));
        $m['sections'][1]['html'] .= '<p>{{slot:hero.video1}}</p>';
        $this->assertContains("Video slot 'hero.video1' may only be used in an attribute (src).", Manifest::validate($m));
    }

    public function test_palette_rgb_token_is_allowed_and_still_guarded(): void
    {
        $this->assertTrue(Manifest::safePaletteVar('--accent-rgb', '{rgb}'));
        $this->assertFalse(Manifest::safePaletteVar('--accent-rgb', '{rgb}; background: url(x)'));
    }

    public function test_text_is_trimmed_capped_and_control_chars_stripped(): void
    {
        $this->assertSame('Hello world', Slots::cleanText("  Hello\x00 world\x07 "));
        $this->assertSame('abc', Slots::cleanText('abcdef', 3));
        $this->assertSame("a\nb", Slots::cleanText("a\r\nb", 50, true));
        $this->assertSame('ab', Slots::cleanText("a\nb", 50, false));
    }

    public function test_render_escapes_for_text_and_attribute_contexts(): void
    {
        $out = Slots::render(['type' => 'text'], '<script>"x"\'y\'</script>');
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringNotContainsString('"', $out);
        $this->assertStringNotContainsString("'", $out);
    }

    public function test_colour_validation(): void
    {
        $this->assertSame('#0e7c66', Slots::cleanColor('#0E7C66'));
        $this->assertSame('#abc', Slots::cleanColor('#abc'));
        foreach (['red', '#12345', '#1234567', 'url(x)', '#fff;background:url(x)', null, 12] as $bad) {
            $this->assertNull(Slots::cleanColor($bad));
        }
    }

    public function test_fixture_manifest_is_valid(): void
    {
        $this->assertSame([], Manifest::validate($this->ltManifest()));
    }

    public function test_manifest_rejects_unsafe_placeholder_contexts(): void
    {
        $cases = [
            'script block' => '<div><script>var a = "{{slot:s.t1}}";</script></div>',
            'style block' => '<style>.a{content:"{{slot:s.t1}}"}</style>',
            'unquoted attribute' => '<a title={{slot:s.t1}}>x</a>',
            'event handler' => '<a onclick="go(\'{{slot:s.t1}}\')">x</a>',
            'style attribute' => '<div style="color:{{slot:s.t1}}">x</div>',
            'text slot in href' => '<a href="{{slot:s.t1}}">x</a>',
            'text slot in src' => '<img src="{{slot:s.t1}}">',
        ];
        foreach ($cases as $label => $html) {
            $m = ['sections' => [['key' => 's', 'html' => $html, 'slots' => [['key' => 's.t1', 'type' => 'text', 'default' => 'x']]]]];
            $this->assertNotSame([], Manifest::validate($m), $label);
        }
    }

    public function test_manifest_rejects_undeclared_duplicate_and_unknown_slots(): void
    {
        $m = ['sections' => [
            ['key' => 's', 'html' => '<p>{{slot:s.nope}}</p>', 'slots' => [['key' => 's.t1', 'type' => 'text'], ['key' => 's.t1', 'type' => 'text'], ['key' => 's.x', 'type' => 'html']]],
        ]];
        $errors = implode("\n", Manifest::validate($m));
        $this->assertStringContainsString('undeclared', $errors);
        $this->assertStringContainsString('Duplicate slot', $errors);
        $this->assertStringContainsString('unknown type', $errors);
    }

    public function test_icon_slots_must_sit_in_text_and_default_markup_stays_inert(): void
    {
        $inAttr = ['sections' => [['key' => 's', 'html' => '<i title="{{slot:s.i1}}"></i>', 'slots' => [['key' => 's.i1', 'type' => 'icon', 'default' => '']]]]];
        $this->assertStringContainsString('text content', implode(' ', Manifest::validate($inAttr)));

        $evil = ['sections' => [['key' => 's', 'html' => '<i>{{slot:s.i1}}</i>', 'slots' => [['key' => 's.i1', 'type' => 'icon', 'default' => '', 'default_html' => '<svg onload="x()"></svg>']]]]];
        $this->assertStringContainsString('unsafe default markup', implode(' ', Manifest::validate($evil)));
    }

    public function test_palette_expressions_cannot_break_out(): void
    {
        $this->assertTrue(Manifest::safePaletteVar('--color-primary-500', '{c}'));
        $this->assertTrue(Manifest::safePaletteVar('--color-primary-50', 'color-mix(in oklab, {c} 6%, white)'));
        $this->assertFalse(Manifest::safePaletteVar('--x', '{c};background:url(x)'));
        $this->assertFalse(Manifest::safePaletteVar('--x', '{c}" onmouseover="x'));
        $this->assertFalse(Manifest::safePaletteVar('--x', 'url(https://evil/x)'));
        $this->assertFalse(Manifest::safePaletteVar('color', '{c}'));
    }
}
