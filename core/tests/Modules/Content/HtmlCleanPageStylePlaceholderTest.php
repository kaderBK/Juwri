<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Support\Html;
use PHPUnit\Framework\TestCase;

/**
 * Security review #9: cleanPage() parks each kept <style> behind a placeholder
 * token and splices the CSS back with a string replace after serialising. An
 * author who writes that token into an attribute (as &#65532;mbstyle0&#65532;)
 * must not get the raw <style> markup spliced into the attribute, where a CSS
 * string can close the quote and add attributes the allow-list removed.
 */
class HtmlCleanPageStylePlaceholderTest extends TestCase
{
    private function firstDiv(string $html, string $id): \DOMElement
    {
        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $el = $doc->getElementById($id);
        $this->assertInstanceOf(\DOMElement::class, $el);

        return $el;
    }

    public function test_placeholder_token_in_an_attribute_does_not_break_out(): void
    {
        $out = Html::cleanPage(
            '<style>.a div{--x:" autofocus tabindex=1 onfocus=alert(1) x="}</style>'
            .'<div id="t" title="&#65532;mbstyle0&#65532;">hi</div>'
        );

        $div = $this->firstDiv($out, 't');
        $this->assertFalse($div->hasAttribute('onfocus'), $out);
        $this->assertFalse($div->hasAttribute('autofocus'), $out);
        $this->assertStringNotContainsString('<style', $div->getAttribute('title'));
    }

    public function test_placeholder_token_in_text_is_not_replaced_by_css(): void
    {
        $out = Html::cleanPage('<style>.a{color:red}</style><p id="p">&#65532;mbstyle0&#65532;</p>');

        $this->assertSame(1, substr_count($out, '<style'), $out);
        $this->assertStringNotContainsString('<style', $this->firstDiv($out, 'p')->textContent);
    }

    public function test_real_style_blocks_still_round_trip(): void
    {
        $out = Html::cleanPage('<style>.a{color:red}</style><p class="a">x</p><style>.b{color:blue}</style>');

        $this->assertSame(2, substr_count($out, '<style'));
        $this->assertStringContainsString('.mb-doc .a{color:red}', $out);
        $this->assertStringContainsString('.mb-doc .b{color:blue}', $out);
        $this->assertStringNotContainsString("\u{FFFC}", $out);
    }
}
