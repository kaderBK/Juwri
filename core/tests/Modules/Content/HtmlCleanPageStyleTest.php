<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Support\Html;
use PHPUnit\Framework\TestCase;

/**
 * Builder.js html-mode pages (reseller / CMS / biolink / email) run their HTML
 * through Html::cleanPage() on save AND at echo. Inline style="" alone cannot do
 * :hover / @media / @keyframes, so cleanPage keeps <style> blocks — but only after
 * sanitising the CSS and SCOPING every selector under a `.mb-doc` wrapper it adds,
 * so one page's CSS can never restyle the surrounding dashboard/storefront chrome
 * and no execution vector (expression/behavior/js-url/@import) survives.
 *
 * These are the security-critical invariants of that scoping; they must not regress.
 */
class HtmlCleanPageStyleTest extends TestCase
{
    public function test_style_block_is_kept_and_wrapped_in_scope(): void
    {
        $out = Html::cleanPage('<style>.hero{display:flex}</style><div class="hero">x</div>');

        $this->assertStringStartsWith('<div class="mb-doc">', $out);
        $this->assertStringEndsWith('</div>', $out);
        $this->assertStringContainsString('.mb-doc .hero{display:flex}', $out);
    }

    public function test_style_less_page_is_not_wrapped(): void
    {
        // Existing published pages (no <style>) must serialise byte-identically.
        $out = Html::cleanPage('<div class="a" style="color:teal">Hi</div>');

        $this->assertStringNotContainsString('mb-doc', $out);
        $this->assertSame('<div class="a" style="color:teal">Hi</div>', $out);
    }

    public function test_body_html_root_selectors_map_onto_scope(): void
    {
        $out = Html::cleanPage('<style>body{background:#111} html{margin:0} :root{--x:1}</style><p>x</p>');

        $this->assertStringContainsString('.mb-doc{background:#111}', $out);
        $this->assertStringContainsString('.mb-doc{margin:0}', $out);
        $this->assertStringContainsString('.mb-doc{--x:1}', $out);
    }

    public function test_hover_and_media_and_keyframes_survive(): void
    {
        $css = '.h:hover{opacity:.5} @media (max-width:600px){.h{display:block}} @keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
        $out = Html::cleanPage("<style>{$css}</style><div class=\"h\">x</div>");

        $this->assertStringContainsString('.mb-doc .h:hover{opacity:.5}', $out);
        $this->assertStringContainsString('@media (max-width:600px){.mb-doc .h{display:block}}', $out);
        // keyframe selectors (from/to) are NOT scoped; declarations survive.
        $this->assertStringContainsString('@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}', $out);
    }

    public function test_comma_selector_list_each_scoped(): void
    {
        $out = Html::cleanPage('<style>.a, .b .c{color:red}</style><i>x</i>');

        $this->assertStringContainsString('.mb-doc .a,.mb-doc .b .c{color:red}', $out);
    }

    public function test_data_image_url_and_calc_preserved(): void
    {
        $out = Html::cleanPage('<style>.x{background:url(data:image/png;base64,AAAA);width:calc(100% - 10px)}</style><b>x</b>');

        $this->assertStringContainsString('data:image/png;base64,AAAA', $out);
        // top-level ; split must not choke on the data-uri ; or the calc() space.
        $this->assertStringContainsString('width:calc(100% - 10px)', $out);
    }

    public function test_dangerous_css_is_stripped(): void
    {
        $css = '@import url(evil.css);'
            .'.a{width:expression(alert(1))}'
            .'.b{behavior:url(x.htc)}'
            .'.c{background:url(javascript:alert(1))}'
            .'.d{background:url(data:text/html,evil)}';
        $out = Html::cleanPage("<style>{$css}</style><span>x</span>");

        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('expression', $out);
        $this->assertStringNotContainsString('behavior', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('data:text/html', $out);
    }

    public function test_unknown_at_rule_is_dropped(): void
    {
        $out = Html::cleanPage('<style>@namespace svg url(http://www.w3.org/2000/svg); .a{color:red}</style><u>x</u>');

        $this->assertStringNotContainsString('@namespace', $out);
        $this->assertStringContainsString('.mb-doc .a{color:red}', $out);
    }

    public function test_selector_cannot_break_out_of_scope(): void
    {
        // A `}` mid-selector must not let an unscoped rule escape the wrapper.
        $out = Html::cleanPage('<style>.a}position:fixed;top:0}.evil{position:fixed}</style><p>x</p>');

        // Every emitted rule stays under .mb-doc; nothing escapes to a bare selector.
        $this->assertStringNotContainsString('}.evil{', $out);
        if (str_contains($out, '.evil')) {
            $this->assertStringContainsString('.mb-doc .evil', $out);
        }
    }

    public function test_script_and_handlers_still_stripped_with_style_present(): void
    {
        $out = Html::cleanPage('<style>.a{color:red}</style><div onclick="steal()"><script>evil()</script><a href="javascript:x()">l</a></div>');

        $this->assertStringNotContainsString('evil()', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('href="javascript', $out);
    }

    public function test_scoping_is_idempotent(): void
    {
        // cleanPage runs at save AND echo; a second pass must be a no-op (nonce aside).
        $in = '<style>body{margin:0}.h:hover{color:red}@media(max-width:600px){.h{display:block}}</style><div class="h">x</div>';
        $once = Html::cleanPage($in);
        $twice = Html::cleanPage($once);

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'class="mb-doc"'));
        $this->assertStringNotContainsString('.mb-doc .mb-doc', $twice);
    }

    /**
     * Security (F1): inside <svg> a browser parses <style> content as markup,
     * while the server-side parser treats it as text — so an <img onerror> in
     * CSS text used to come out as a live element.
     */
    public function test_svg_style_cannot_smuggle_markup(): void
    {
        $out = Html::cleanPage('<svg><style>.x{--a:"<img src=x onerror=alert(document.domain)>"}</style></svg><p>ok</p>');

        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('<p>ok</p>', $out);
    }

    /** CSS containing '<' is dropped (it can only be an attempt to break out). */
    public function test_style_with_angle_bracket_is_dropped(): void
    {
        $out = Html::cleanPage('<style>.x{content:"</style><img src=x onerror=alert(1)>"}</style><p>ok</p>');

        $this->assertStringNotContainsString('onerror', $out);
    }

    /** The child combinator '>' is still allowed. */
    public function test_child_combinator_survives(): void
    {
        $out = Html::cleanPage('<style>.a > .b{color:red}</style><div class="a"><p class="b">x</p></div>');

        $this->assertStringContainsString('.mb-doc .a > .b{color:red}', $out);
    }
}
