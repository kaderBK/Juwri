<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DOCS-PLATFORM D6-4: the changelog surface — HTML, JSON, RSS; product filter;
 * breaking-change flag.
 */
class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_html_lists_entries_and_flags_breaking(): void
    {
        // A real entry from the feed renders. Asserted via a locale-independent
        // field (the version in each entry's meta line) rather than the title or
        // page heading, which are localized — the changelog uses the marketing
        // landing chrome and the test locale is not English.
        $version = json_decode((string) file_get_contents(resource_path('docs/changelog.json')), true)['entries'][0]['version'];

        $res = $this->get('/changelog');
        $res->assertOk()->assertSee($version);
        // Assert the breaking FLAG via its locale-independent badge class, not the
        // localized "BREAKING" text — the test locale is not English, so the label
        // is translated (the changelog uses the marketing landing chrome).
        $res->assertSeeHtml('cl-badge');
        $res->assertHeader('Cache-Control', 'max-age=300, public');
    }

    public function test_product_filter_narrows_entries(): void
    {
        $all = $this->getJson('/changelog.json')->assertOk()->json('entries');
        $pos = $this->getJson('/changelog.json?product=pos')->assertOk()->json('entries');

        $this->assertNotEmpty($all);
        $this->assertNotEmpty($pos);
        $this->assertLessThan(count($all), count($pos));
        foreach ($pos as $e) {
            $this->assertSame('pos', $e['product']);
        }
    }

    public function test_rss_is_well_formed_and_carries_categories(): void
    {
        $res = $this->get('/changelog.rss');
        $res->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=utf-8');
        $xml = simplexml_load_string($res->getContent());
        $this->assertNotFalse($xml, 'RSS parses');
        $this->assertGreaterThan(0, $xml->channel->item->count());
    }
}
