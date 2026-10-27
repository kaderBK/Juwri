<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Site;
use App\Models\SitePage;
use App\Services\SitemapService;
use Juwri\Modules\Content\Services\SitePages\SiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOME-PAGES HP-50/51/52: per-site theme tokens on the landing layout
 * (validated, absent without a Site row, reseller remap still wins),
 * site_setting() overlay resolution, and published slug pages in the
 * marketing sitemap.
 */
class SiteThemeTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
        config(['app.installed' => true]);
    }

    public function test_valid_tokens_emit_and_invalid_are_dropped(): void
    {
        Site::create(['key' => 'tok-site', 'name' => 'T', 'active' => true, 'theme' => [
            'primary' => '#112233',
            'accent' => 'javascript:evil',   // invalid → dropped
            'radius' => '12px',
            'surface' => '#abcdef',
        ]]);
        SiteContext::flushMemo();
        cache()->forget('site:default');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('--ss-primary:#112233', $html);
        $this->assertStringContainsString('--ss-radius:12px', $html);
        $this->assertStringContainsString('--ss-surface:#abcdef', $html);
        $this->assertStringNotContainsString('javascript:evil', $html);
        $this->assertStringContainsString('data-site="tok-site"', $html);
        // rgb derived from the site primary
        $this->assertStringContainsString('--ss-primary-rgb:17, 34, 51', $html);
    }

    public function test_no_site_row_emits_no_token_block_or_data_site(): void
    {
        SiteContext::flushMemo();
        cache()->forget('site:default');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-site=', $html);
        $this->assertStringNotContainsString('--ss-accent', $html);
    }

    public function test_site_setting_overlay_resolution(): void
    {
        config(['settings.site_title' => 'FromOptions']);
        $this->assertSame('FromOptions', site_setting('site_title'));
        $this->assertSame('fallback', site_setting('missing_key_xyz', 'fallback'));

        Site::create(['key' => 'ov-site', 'name' => 'O', 'active' => true, 'settings' => ['site_title' => 'FromSite']]);
        SiteContext::flushMemo();
        cache()->forget('site:default');

        $this->assertSame('FromSite', site_setting('site_title'));
    }

    public function test_marketing_sitemap_includes_published_slug_pages_only(): void
    {
        $site = Site::create(['key' => 'sm-site', 'name' => 'S', 'active' => true]);
        SitePage::create(['site_id' => $site->id, 'type' => 'page', 'slug' => 'published-page', 'status' => 'published', 'published_blocks' => ['version' => 1, 'blocks' => []]]);
        SitePage::create(['site_id' => $site->id, 'type' => 'page', 'slug' => 'draft-page', 'status' => 'draft']);
        SiteContext::flushMemo();
        cache()->forget('site:default');

        $urls = array_column(SitemapService::marketingEntries(), 'loc');
        $joined = implode(' ', $urls);

        $this->assertStringContainsString('page/published-page', $joined);
        $this->assertStringNotContainsString('draft-page', $joined);
    }
}
