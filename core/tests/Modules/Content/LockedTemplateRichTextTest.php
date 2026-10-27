<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\LockedTemplates\Manifest;
use Juwri\Modules\Content\Services\LockedTemplates\RichText;
use PHPUnit\Framework\TestCase;
use Tests\Support\LockedTemplateFixtures;

/**
 * Inline formatting is user-supplied HTML, so RichText::clean() is a security
 * boundary: it must rebuild markup from an allow-list, never pass anything through.
 */
class LockedTemplateRichTextTest extends TestCase
{
    use LockedTemplateFixtures;

    public function test_allowed_marks_are_kept_in_canonical_form(): void
    {
        $this->assertSame(
            '<strong>Bold</strong> <em>it</em> <u>under</u> <s>gone</s>',
            RichText::clean('<b>Bold</b> <i>it</i> <u>under</u> <strike>gone</strike>')
        );
        // execCommand(styleWithCSS) output maps onto the same marks.
        $this->assertSame('<strong>B</strong> <em>I</em>', RichText::clean('<span style="font-weight: bold;">B</span> <span style="font-style: italic">I</span>'));
        $this->assertSame('<span style="color:#ff0000">red</span>', RichText::clean('<span style="color: rgb(255, 0, 0);">red</span>'));
        $this->assertSame('<span style="color:#112233">c</span>', RichText::clean('<font color="#123">c</font>'));
        $this->assertSame('<mark style="background-color:#ffff00">hi</mark>', RichText::clean('<span style="background-color:#ff0">hi</span>'));
        // Chrome's hiliteColor on already-bold text styles the <strong> itself.
        $this->assertSame('<strong><mark style="background-color:#c6f56f">reliable</mark></strong>', RichText::clean('<strong style="background-color: rgb(198, 245, 111);">reliable</strong>'));
        $this->assertSame('<em><span style="color:#ff0000">x</span></em>', RichText::clean('<i style="color:#f00">x</i>'));
        $this->assertSame('<a href="https://example.com/x" target="_blank" rel="noopener noreferrer">link</a>', RichText::clean('<a href="https://example.com/x" target="_blank" onclick="x()">link</a>'));
    }

    public function test_hostile_markup_is_dropped(): void
    {
        $out = RichText::clean('<img src=x onerror=alert(1)>hi<script>alert(1)</script><a href="javascript:alert(1)">x</a><svg onload=alert(1)><text>s</text></svg><iframe src="//evil"></iframe>');

        $this->assertSame('hix', $out);
        foreach (['onerror', 'script', 'javascript', 'onload', '<svg', 'iframe', '<img'] as $bad) {
            $this->assertStringNotContainsString($bad, $out);
        }
    }

    public function test_style_values_cannot_break_out(): void
    {
        $this->assertSame('a', RichText::clean('<span style="color:red;background:url(https://evil/x)">a</span>'));
        $this->assertSame('b', RichText::clean('<span style="color:#123456&quot; onmouseover=&quot;x">b</span>'));
        $this->assertSame('c', RichText::clean('<span style="color:expression(alert(1))">c</span>'));
        $this->assertSame('d', RichText::clean('<span class="x" data-lt-t="k" id="y">d</span>'));
    }

    public function test_output_is_canonical_and_idempotent(): void
    {
        $messy = '<b>One <i>two</i></b> &amp; <span style="color:#ABC">three</span> <a href="/page">four</a>&nbsp;<u><s>five</s></u>';
        $once = RichText::clean($messy);
        $this->assertSame($once, RichText::clean($once));
        $this->assertSame('One two & three four five', RichText::plain($once));
    }

    public function test_marks_can_be_denied_and_text_is_capped(): void
    {
        $this->assertSame('Hi &amp; bye', RichText::clean('<b>Hi</b> &amp; bye', 300, false));
        $this->assertSame('<strong>Hello</strong>', RichText::clean('<b>Hello world</b>', 5));
        // The editor always sends escaped text; literal characters stay literal.
        $this->assertSame('Tom &amp; "Jerry" &lt;3', RichText::clean('Tom &amp; "Jerry" &lt;3'));
        $this->assertSame('Tom &amp; "Jerry"', RichText::clean('Tom & "Jerry"'));
        $this->assertSame('a b', RichText::clean('<div>a</div><div>b</div>'));
        $this->assertNull(RichText::clean(['x']));
        $this->assertNull(RichText::clean(str_repeat('a', 40000)));
    }

    public function test_only_plain_text_contexts_allow_formatting(): void
    {
        $rich = Manifest::richTextKeys($this->ltManifest());

        $this->assertArrayHasKey('hero.t1', $rich);       // <h1>
        $this->assertArrayHasKey('header.t1', $rich);     // <span> beside (not inside) the link
        $this->assertArrayHasKey('footer.t1', $rich);
        $this->assertArrayNotHasKey('hero.t2', $rich);    // input placeholder attribute
        $this->assertArrayNotHasKey('hero.t3', $rich);    // inside <a>: no nested links
        $this->assertArrayNotHasKey('hero.link1', $rich); // not a text slot

        $m = $this->ltManifest();
        $m['sections'][1]['slots'][0]['plain'] = true;    // e.g. an animated counter
        $this->assertArrayNotHasKey('hero.t1', Manifest::richTextKeys($m));
    }
}
