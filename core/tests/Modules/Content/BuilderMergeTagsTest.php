<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\Builder\MergeTags;
use PHPUnit\Framework\TestCase;

/**
 * Merge tags interpolate live data into Builder.js html-mode pages at render time.
 * They run BEFORE Html::cleanPage(), so their only job is safe substitution: values
 * are HTML-escaped, unknown tokens are left alone, and the URL-attribute (percent-
 * encoded) form is handled. XSS in the surrounding HTML is cleanPage's job.
 */
class BuilderMergeTagsTest extends TestCase
{
    public function test_known_token_is_substituted(): void
    {
        $out = MergeTags::apply('<h1>{{store.name}}</h1>', ['store.name' => 'Nour']);
        $this->assertSame('<h1>Nour</h1>', $out);
    }

    public function test_whitespace_inside_braces_is_tolerated(): void
    {
        $out = MergeTags::apply('© {{  year  }}', ['year' => '2026']);
        $this->assertSame('© 2026', $out);
    }

    public function test_values_are_html_escaped(): void
    {
        $out = MergeTags::apply('<h1>{{store.name}}</h1>', ['store.name' => 'Café <b>x</b> & Co']);
        $this->assertStringContainsString('Café &lt;b&gt;x&lt;/b&gt; &amp; Co', $out);
        $this->assertStringNotContainsString('<b>x</b>', $out);
    }

    public function test_percent_encoded_url_attribute_form(): void
    {
        // DOMDocument percent-encodes braces inside href on save.
        $out = MergeTags::apply('<a href="%7B%7Bstore.url%7D%7D">go</a>', ['store.url' => 'https://x.example/a']);
        $this->assertStringContainsString('href="https://x.example/a"', $out);
    }

    public function test_unknown_token_is_left_literal(): void
    {
        $out = MergeTags::apply('{{store.name}} {{nope.token}}', ['store.name' => 'A']);
        $this->assertStringContainsString('{{nope.token}}', $out);
    }

    public function test_empty_inputs_are_noops(): void
    {
        $this->assertSame('', MergeTags::apply('', ['a' => 'b']));
        $this->assertSame('<p>x</p>', MergeTags::apply('<p>x</p>', []));
    }

    public function test_store_context_supplies_year_and_date_defaults(): void
    {
        $ctx = MergeTags::storeContext(['store.name' => 'Nour']);
        $this->assertSame('Nour', $ctx['store.name']);
        $this->assertArrayHasKey('year', $ctx);
        $this->assertArrayHasKey('date', $ctx);
        $this->assertSame(date('Y'), $ctx['year']);
    }

    public function test_available_vocabulary_is_published(): void
    {
        $keys = array_keys(MergeTags::available());
        $this->assertContains('store.name', $keys);
        $this->assertContains('store.currency', $keys);
        $this->assertContains('page.title', $keys);
    }
}
