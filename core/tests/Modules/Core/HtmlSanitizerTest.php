<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Support\Html;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_strips_script_tags(): void
    {
        $out = Html::clean('<b>ok</b><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<b>ok</b>', $out);
    }

    public function test_strips_event_handler_attributes(): void
    {
        $this->assertStringNotContainsString('onerror', Html::clean('<img src=x onerror=alert(1)>'));
        $this->assertStringNotContainsString('onclick', Html::clean('<span onclick="x()">s</span>'));
        $this->assertStringNotContainsString('onmouseover', Html::clean('<b onmouseover="x()">s</b>'));
    }

    public function test_strips_javascript_urls(): void
    {
        $out = Html::clean('<a href="javascript:alert(1)">x</a>');
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_keeps_safe_inline_formatting(): void
    {
        $out = Html::clean('<b>bold</b> <em>em</em> <a href="https://ok.test">link</a>');
        $this->assertStringContainsString('<b>bold</b>', $out);
        $this->assertStringContainsString('<em>em</em>', $out);
        $this->assertStringContainsString('href="https://ok.test"', $out);
    }

    public function test_default_profile_strips_headings_but_richtext_keeps_them(): void
    {
        $this->assertStringNotContainsString('<h1', Html::clean('<h1>t</h1>', 'default'));
        $this->assertStringContainsString('<h1', Html::clean('<h1>t</h1>', 'richtext'));
    }

    public function test_default_profile_strips_images_but_richtext_keeps_them(): void
    {
        $this->assertStringNotContainsString('<img', Html::clean('<img src="https://ok.test/a.png" alt="a">', 'default'));
        $this->assertStringContainsString('<img', Html::clean('<img src="https://ok.test/a.png" alt="a">', 'richtext'));
    }

    public function test_null_and_empty_return_empty_string(): void
    {
        $this->assertSame('', Html::clean(null));
        $this->assertSame('', Html::clean('   '));
    }

    public function test_clean_blade_directive_sanitizes(): void
    {
        $rendered = Blade::render('@clean($v)', ['v' => '<b>x</b><script>alert(1)</script>']);
        $this->assertStringContainsString('<b>x</b>', $rendered);
        $this->assertStringNotContainsString('<script', $rendered);
    }
}
