<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\Faq;
use App\Models\Site;
use App\Models\SitePage;
use Juwri\Modules\Content\Models\Testimonial;
use Juwri\Core\Support\LocalizedContent;
use Juwri\Modules\Content\Services\SitePages\SitePageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOME-PAGES HP-13/HP-17: SitePageRenderer renders a published block document
 * against the live kit marketing layout; defensive skips (disabled, unknown
 * type, future version) must never 500; SEO yields are verbatim when supplied
 * and fall back to layout $settings defaults when absent.
 */
class SitePageRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // APP_INSTALLED=false in phpunit.xml skips the ViewServiceProvider
        // composers — build $settings/$activeTheme through the same expression
        // (WI-M12 house pattern).
        $this->shareLayoutGlobals();
    }

    private function makePage(array $overrides = []): SitePage
    {
        $site = Site::create(['key' => 'test-site-'.uniqid(), 'name' => 'Test Site', 'active' => true]);

        return SitePage::create(array_merge([
            'site_id' => $site->id,
            'type' => 'home',
            'status' => 'published',
            'published_blocks' => ['version' => 1, 'blocks' => []],
        ], $overrides));
    }

    public function test_renders_published_document_with_defensive_skips(): void
    {
        $page = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aaaa', 'type' => 'hero', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => ['headline' => ['en' => 'Custom EN']]],
                ['id' => 'blk_bbbb', 'type' => 'hero', 'enabled' => false, 'version' => 1, 'settings' => [], 'content' => []],
                ['id' => 'blk_cccc', 'type' => 'ghost_type', 'enabled' => true, 'version' => 1],
                ['id' => 'blk_dddd', 'type' => 'hero', 'enabled' => true, 'version' => 99],
            ]],
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        // Only the enabled, known-type, current-version hero renders — the
        // disabled and future-version heroes must NOT add more sections.
        $this->assertSame(1, substr_count($html, 'data-site-block="hero"'), 'exactly one hero rendered');

        // Renders against the real layout without 500ing.
        $this->assertStringContainsString('og:site_name', $html);
    }

    public function test_seo_json_served_verbatim_and_robots_override(): void
    {
        $page = $this->makePage([
            'seo' => [
                'meta_title' => ['en' => 'Verbatim Title'],
                'meta_description' => ['en' => 'Verbatim description.'],
                'robots' => 'noindex,nofollow',
            ],
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        $this->assertStringContainsString('<title>Verbatim Title</title>', $html);
        $this->assertStringContainsString('content="Verbatim description."', $html);
        $this->assertStringContainsString('content="noindex,nofollow"', $html);
    }

    public function test_missing_seo_falls_back_to_layout_defaults(): void
    {
        $page = $this->makePage();

        $html = app(SitePageRenderer::class)->render($page)->render();

        // No seo JSON → layout defaults from $settings apply; robots default.
        $this->assertStringContainsString('content="index,follow"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('home').'"', $html);
    }

    public function test_locale_content_resolution_follows_localized_content_fallback(): void
    {
        // The renderer delegates to LocalizedContent — pin the fallback chain the
        // wrappers rely on (exact → default → first non-empty). HTML-level
        // override assertions arrive with HP-20 when the hero partial consumes
        // \$block['content'] props.
        $field = ['ar' => 'عنوان عربي'];

        $this->assertSame('عنوان عربي', LocalizedContent::resolve($field, 'en'));
        $this->assertSame('عنوان عربي', LocalizedContent::resolve($field, 'ar'));
        $this->assertSame('', LocalizedContent::resolve([], 'en'));
    }

    public function test_blocks_html_fragment_cache_hits_and_versions_on_updated_at(): void
    {
        $page = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aaaa', 'type' => 'hero', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => []],
            ]],
        ]);

        $renderer = app(SitePageRenderer::class);
        $renderer->render($page)->render(); // warm

        // Poison the exact cache key — a cache HIT must serve the sentinel.
        $stamp = $page->updated_at->getTimestamp();
        $key = "sitepage:html:{$page->id}:".get_lang().":{$stamp}";
        cache()->put($key, '<!--sentinel-->', 300);
        $this->assertStringContainsString('<!--sentinel-->', $renderer->render($page)->render());

        // Bumping updated_at starts a fresh key — real blocks HTML again.
        $page->forceFill(['updated_at' => $page->updated_at->addSecond()])->saveQuietly();
        $html = $renderer->render($page->fresh())->render();
        $this->assertStringNotContainsString('<!--sentinel-->', $html);
        $this->assertStringContainsString('data-site-block="hero"', $html);

        // Draft path (renderDocument) must BYPASS the cache entirely.
        cache()->put("sitepage:html:{$page->id}:".get_lang().':'.$page->fresh()->updated_at->getTimestamp(), '<!--sentinel2-->', 300);
        $draftHtml = $renderer->renderDocument($page->fresh(), $page->published_blocks)->render();
        $this->assertStringNotContainsString('<!--sentinel2-->', $draftHtml);
    }

    public function test_hero_content_overrides_replace_lang_defaults(): void
    {
        $page = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aaaa', 'type' => 'hero', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => [
                    'headline' => ['en' => 'Override Headline'],
                    'cta_label' => ['en' => 'Go Now'],
                    'cta_url' => 'https://example.com/start',
                ]],
            ]],
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        $this->assertStringContainsString('Override Headline', $html);
        $this->assertStringContainsString('Go Now', $html);
        $this->assertStringContainsString('https://example.com/start', $html);
        // Default headline replaced, subline default kept (no override given).
        $this->assertStringNotContainsString('QR menu & WhatsApp ordering — your restaurant, live in minutes.', $html);
    }

    public function test_cta_banner_renders_and_rejects_unsafe_urls(): void
    {
        $page = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aaaa', 'type' => 'cta_banner', 'enabled' => true, 'version' => 1,
                    'settings' => ['style' => 'gradient'],
                    'content' => ['headline' => ['en' => 'Banner Head'], 'cta_label' => ['en' => 'Evil'], 'cta_url' => 'javascript:alert(1)']],
            ]],
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        $this->assertStringContainsString('Banner Head', $html);
        // SafeUrl rejects javascript: — no CTA anchor rendered at all.
        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('>Evil<', $html);
    }

    public function test_richtext_body_is_purified_on_output(): void
    {
        $page = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aaaa', 'type' => 'richtext', 'enabled' => true, 'version' => 1, 'settings' => [],
                    'content' => ['body' => ['en' => '<p>Safe para</p><script>alert(1)</script>']]],
            ]],
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        $this->assertStringContainsString('Safe para', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_data_bound_blocks_render_with_own_queries(): void
    {
        Testimonial::create(['name' => 'T Person', 'designation' => 'Owner', 'content' => 'Great platform!', 'image' => 'x.png']);
        Faq::create(['faq_title' => 'How fast is setup?', 'faq_content' => 'Minutes.', 'active' => 1]);

        $page = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aaa1', 'type' => 'testimonials_wall', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => []],
                ['id' => 'blk_aaa2', 'type' => 'faq_accordion', 'enabled' => true, 'version' => 1, 'settings' => ['limit' => '10'], 'content' => []],
                ['id' => 'blk_aaa3', 'type' => 'pricing_grid', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => []],
                ['id' => 'blk_aaa4', 'type' => 'blog_teasers', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => []],
                ['id' => 'blk_aaa5', 'type' => 'logo_marquee', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => []],
            ]],
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        $this->assertStringContainsString('Great platform!', $html);
        $this->assertStringContainsString('How fast is setup?', $html);
        // Empty plans/blog/marquee sections self-guard — page still renders.
        $this->assertStringContainsString('og:site_name', $html);
    }

    public function test_structured_data_follows_block_presence(): void
    {
        Faq::create(['faq_title' => 'LD Question?', 'faq_content' => 'LD answer.', 'active' => 1]);

        // Hero + FAQ present → both JSON-LD scripts.
        $page = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aaa1', 'type' => 'hero', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => []],
                ['id' => 'blk_aaa2', 'type' => 'faq_accordion', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => []],
            ]],
        ]);
        $html = app(SitePageRenderer::class)->render($page)->render();
        $this->assertStringContainsString('"SoftwareApplication"', $html);
        $this->assertStringContainsString('"FAQPage"', $html);
        $this->assertStringContainsString('LD Question?', $html);

        // No hero, faq disabled → neither script.
        $page2 = $this->makePage([
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_bbb1', 'type' => 'richtext', 'enabled' => true, 'version' => 1, 'settings' => [], 'content' => ['body' => ['en' => '<p>x</p>']]],
                ['id' => 'blk_bbb2', 'type' => 'faq_accordion', 'enabled' => false, 'version' => 1, 'settings' => [], 'content' => []],
            ]],
        ]);
        $html2 = app(SitePageRenderer::class)->render($page2)->render();
        $this->assertStringNotContainsString('"SoftwareApplication"', $html2);
        $this->assertStringNotContainsString('"FAQPage"', $html2);
    }

    public function test_empty_and_null_documents_render_without_error(): void
    {
        $page = $this->makePage(['published_blocks' => null]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        $this->assertStringContainsString('og:site_name', $html);
        $this->assertStringNotContainsString('data-site-block="hero"', $html);
    }
}
