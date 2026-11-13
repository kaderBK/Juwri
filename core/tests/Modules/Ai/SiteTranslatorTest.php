<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use App\Models\Site;
use App\Models\SitePage;
use App\Services\SitePages\StringMapTranslator;
use App\Services\SitePages\SiteTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * HOME-PAGES HP-44: SiteTranslator walks SiteBlockRegistry defs, skips filled
 * targets in 'missing' mode, purifies richtext results, and delegates to the
 * shared StringMapTranslator::translateMap (mocked here — no AI provider in CI).
 */
class SiteTranslatorTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $blocks): SitePage
    {
        $site = Site::create(['key' => 't-'.uniqid(), 'name' => 'T', 'active' => true]);

        return SitePage::create([
            'site_id' => $site->id, 'type' => 'home', 'status' => 'draft',
            'blocks' => ['version' => 1, 'blocks' => $blocks],
        ]);
    }

    public function test_translates_missing_fields_and_respects_manual_edits(): void
    {
        $page = $this->page([
            ['id' => 'blk_a1', 'type' => 'hero', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => [
                'headline' => ['en' => 'Hello'],                       // missing ar → translate
                'subline' => ['en' => 'Sub', 'ar' => 'يدوي'],          // ar filled → keep (missing mode)
                'cta_url' => 'https://x.test',                          // not localized → never sent
            ]],
            ['id' => 'blk_a2', 'type' => 'richtext', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => [
                'body' => ['en' => '<p>Body</p>'],
            ]],
        ]);

        $mock = Mockery::mock(StringMapTranslator::class);
        $mock->shouldReceive('translateMap')->once()
            ->withArgs(function (array $strings, string $source, string $target) {
                // Only headline + body sent; manual ar subline and flat cta_url excluded.
                return array_keys($strings) === ['b0.headline', 'b1.body'] && $source === 'en' && $target === 'ar';
            })
            ->andReturn(['b0.headline' => 'مرحبا', 'b1.body' => '<p>نص</p><script>x()</script>']);

        $doc = new SiteTranslator($mock)->translatePage($page, 'en', 'ar', 'missing');

        $this->assertSame('مرحبا', $doc['blocks'][0]['content']['headline']['ar']);
        $this->assertSame('يدوي', $doc['blocks'][0]['content']['subline']['ar']);
        $this->assertStringNotContainsString('<script>', $doc['blocks'][1]['content']['body']['ar'], 'richtext purified');
        $this->assertStringContainsString('نص', $doc['blocks'][1]['content']['body']['ar']);
    }
}
