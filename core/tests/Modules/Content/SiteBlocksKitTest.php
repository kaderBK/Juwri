<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Site;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\Faq;
use Juwri\Modules\Content\Models\Testimonial;
use Juwri\Modules\Content\Services\SitePages\SiteBlockRegistry;
use Juwri\Modules\Content\Services\SitePages\SiteContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-16g: the site-page block library renders on the owner-ui kit frame. One
 * published home page carries EVERY registered block type (enumerated from the
 * registry) and must render with no bought-theme markup or assets, each block
 * showing its heading; admin copy overrides reach the page and the block
 * structured data (SoftwareApplication, FAQPage) is still emitted.
 */
class SiteBlocksKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['app.installed' => true]);
        app()->setLocale('en');
    }

    /** Heading each block must show (overrides where the builder offers one). */
    private function expectedHeadings(): array
    {
        return [
            'hero' => 'Kit Hero Headline',
            'cta_banner' => 'Kit Banner Headline',
            'pricing_grid' => 'Kit Pricing Heading',
            'faq_accordion' => 'Kit FAQ Heading',
            'testimonials_wall' => 'Kit Testimonials Heading',
            'blog_teasers' => 'Kit Blog Heading',
            'logo_marquee' => e(___('Trusted by restaurants everywhere.')),
            'menu_studio' => e(___('full ordering app')),
            'section_order_journey' => e(___('The honest comparison.')),
            'section_product_fields' => e(___('Front of house to back office. Nothing bolted on.')),
            'section_product_tour' => e(___('Five surfaces. Swipe through.')),
            'section_ai_suite' => e(___('AI does the boring work. You pick the brain.')),
            'section_payments_tax' => e(___('Get paid your way, with tax-compliant e-invoicing built in.')),
            'section_integrations' => e(___('Plays well with your stack.')),
            'bio_pages' => e(___('Your link-in-bio, but it takes orders.')),
            'section_audiences' => e(___('Bigger than one restaurant? Good.')),
            'section_features_tabbed' => e(___('One platform. Every tool your restaurant runs on.')),
            'section_app_screens' => e(___('A menu that feels like an app, without the download.')),
            'feature_icon_grid' => e(___('Your digital menu, online ordering and POS in one place')),
            'features_overview' => e(___('From menu to loyalty, one login.')),
            'showcase' => e(___('From the page on your counter to the menu on their phone')),
            'features_timed' => e(___('More orders. Zero commission.')),
            'three_steps' => e(___('Go live in three simple steps.')),
            'for_developers' => e(___('Point your AI editor at us. It just works.')),
            'richtext' => 'Kit Rich Heading',
        ];
    }

    private function content(string $type): array
    {
        return match ($type) {
            'hero' => ['headline' => ['en' => 'Kit Hero Headline'], 'cta_label' => ['en' => 'Kit Go'], 'cta_url' => 'https://example.com/kit-start'],
            'cta_banner' => ['headline' => ['en' => 'Kit Banner Headline'], 'subline' => ['en' => 'Kit banner copy'], 'cta_label' => ['en' => 'Kit Banner Go'], 'cta_url' => 'https://example.com/kit-banner'],
            'pricing_grid' => ['heading' => ['en' => 'Kit Pricing Heading']],
            'faq_accordion' => ['heading' => ['en' => 'Kit FAQ Heading']],
            'testimonials_wall' => ['heading' => ['en' => 'Kit Testimonials Heading']],
            'blog_teasers' => ['heading' => ['en' => 'Kit Blog Heading']],
            'richtext' => ['body' => ['en' => '<h2>Kit Rich Heading</h2><p>Kit rich body</p>']],
            default => [],
        };
    }

    private function seedData(): void
    {
        TestPlans::create([
            'name' => 'Kit Block Plan', 'description' => 'Kit plan', 'monthly_price' => 19, 'annual_price' => 190,
            'lifetime_price' => 0, 'recommended' => 'yes', 'status' => 1, 'settings' => [],
        ]);
        Faq::create(['faq_title' => 'Kit FAQ question?', 'faq_content' => 'Kit answer.', 'active' => 1]);
        Testimonial::create(['name' => 'Kit Person', 'designation' => 'Owner', 'content' => 'Kit testimonial quote.', 'image' => 'x.png']);
        Blog::create(['title' => 'Kit Blog Post', 'slug' => 'kit-blog-article', 'description' => '<p>Body</p>', 'image' => 'default.png', 'status' => 'publish']);

        $owner = User::factory()->create(['status' => 1]);
        Store::factory()->create(['user_id' => $owner->id, 'title' => 'Kit Logo Store', 'main_image' => 'kit-logo.png']);
        Cache::forget('landing:marquee_stores:14');
        Cache::forget('landing:active_store_count');
    }

    private function publishAllBlocks(): void
    {
        $blocks = [];
        foreach (SiteBlockRegistry::all() as $type => $def) {
            $settings = [];
            foreach ($def['settings'] as $field) {
                $settings[$field['name']] = (string) ($field['default'] ?? ($field['options'][0] ?? ''));
            }
            $blocks[] = [
                'id' => 'blk_'.substr(md5($type), 0, 6),
                'type' => $type,
                'enabled' => true,
                'version' => $def['version'],
                'settings' => $settings,
                'content' => $this->content($type),
            ];
        }
        $doc = SiteBlockRegistry::validateDocument(['blocks' => $blocks]);
        $this->assertCount(count(SiteBlockRegistry::types()), $doc['blocks']);

        $site = Site::create(['key' => 'kit-blocks', 'name' => 'Kit Blocks', 'active' => true]);
        SitePage::create([
            'site_id' => $site->id,
            'type' => 'home',
            'status' => 'published',
            'published_blocks' => $doc,
        ]);
        SiteContext::flushMemo();
        Cache::forget('site:default');
    }

    public function test_every_block_type_renders_on_the_kit_frame(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $this->assertEqualsCanonicalizing(SiteBlockRegistry::types(), array_keys($this->expectedHeadings()), 'a new block type needs a heading expectation here');

        $this->seedData();
        $this->publishAllBlocks();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('mk-body', $html);
        $this->assertStringNotContainsString('mk-body--legacy', $html);
        // The page body may not point at the old theme (the shared frame head,
        // e.g. the brand-tile helper, is covered by the frame tests).
        $main = preg_match('#<main id="main"[^>]*>(.*)</main>#s', $html, $mm) ? $mm[1] : '';
        $this->assertNotSame('', trim($main));
        $this->assertStringNotContainsString('assets/templates/lamar', $main);
        $this->assertStringNotContainsStringIgnoringCase('bootstrap', $html);
        $this->assertStringNotContainsString('data-bs-', $main);
        $this->assertStringContainsString('assets/owner-ui/site-blocks.css', $html);

        foreach ($this->expectedHeadings() as $type => $heading) {
            $this->assertSame(1, substr_count($html, 'data-site-block="'.$type.'"'), "block $type renders once");
            $this->assertStringContainsString($heading, $html, "block $type heading");
        }

        // Admin copy overrides and live data reach the page.
        $this->assertStringContainsString('href="https://example.com/kit-start"', $html);
        $this->assertStringContainsString('Kit Go', $html);
        $this->assertStringContainsString('href="https://example.com/kit-banner"', $html);
        $this->assertStringContainsString('Kit Block Plan', $html);
        $this->assertStringContainsString('Kit FAQ question?', $html);
        $this->assertStringContainsString('Kit testimonial quote.', $html);
        $this->assertStringContainsString('Kit Blog Post', $html);
        $this->assertStringContainsString('Kit Logo Store', $html);
        $this->assertStringContainsString('Kit rich body', $html);

        // Block structured data, hex-escaped through the shared JSON-LD partial.
        $this->assertStringContainsString('"SoftwareApplication"', $html);
        $this->assertStringContainsString('"FAQPage"', $html);
        $this->assertStringContainsString('Kit FAQ question?', $html);
    }

    public function test_block_links_reject_unsafe_urls(): void
    {
        $site = Site::create(['key' => 'kit-unsafe', 'name' => 'Kit Unsafe', 'active' => true]);
        SitePage::create([
            'site_id' => $site->id,
            'type' => 'home',
            'status' => 'published',
            'published_blocks' => ['version' => 1, 'blocks' => [
                ['id' => 'blk_aa11', 'type' => 'hero', 'enabled' => true, 'version' => 1, 'settings' => [],
                    'content' => ['cta_label' => ['en' => 'Kit Evil Hero'], 'cta_url' => 'javascript:alert(1)']],
                ['id' => 'blk_aa22', 'type' => 'cta_banner', 'enabled' => true, 'version' => 1, 'settings' => ['style' => 'gradient'],
                    'content' => ['headline' => ['en' => 'Kit Gradient'], 'cta_label' => ['en' => 'Kit Evil Banner'], 'cta_url' => 'javascript:alert(2)']],
            ]],
        ]);
        SiteContext::flushMemo();
        Cache::forget('site:default');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:alert', $html);
        // The hero keeps its label but links to sign-up; the banner drops the button.
        $this->assertStringContainsString('href="'.route('register').'" class="ui-btn">Kit Evil Hero', $html);
        $this->assertStringNotContainsString('Kit Evil Banner', $html);
        $this->assertStringContainsString('sb-cta--gradient', $html);
    }
}
