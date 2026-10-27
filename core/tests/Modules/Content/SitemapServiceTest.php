<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\Blog;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use App\Services\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P9-6a Option 2b: self-built sitemap (no crawler chain).
 * rev/seo R6: now a dynamic index (marketing/blog/restaurants sections) with
 * xhtml:link locale alternates in URL-prefix mode.
 */
class SitemapServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_xml_is_well_formed_and_escapes(): void
    {
        $xml = SitemapService::buildXml([
            ['loc' => 'https://ex.test/'],
            ['loc' => 'https://ex.test/a?b=1&c=2', 'image' => 'https://ex.test/i.png', 'lastmod' => '2026-07-07'],
        ]);

        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'sitemap XML is well-formed');
        $this->assertStringContainsString('<loc>https://ex.test/</loc>', $xml);
        $this->assertStringContainsString('b=1&amp;c=2', $xml, 'ampersand is escaped');
        $this->assertStringContainsString('<image:loc>https://ex.test/i.png</image:loc>', $xml);
        $this->assertStringContainsString('<lastmod>2026-07-07</lastmod>', $xml);
        $this->assertStringNotContainsString('b=1&c=2', $xml, 'no raw unescaped ampersand');
    }

    public function test_alternates_render_as_xhtml_links(): void
    {
        $xml = SitemapService::buildXml([
            ['loc' => 'https://ex.test/', 'alternates' => ['ar' => 'https://ex.test', 'en' => 'https://ex.test/en']],
        ]);

        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="ar" href="https://ex.test"/>', $xml);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="https://ex.test/en"/>', $xml);
        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
    }

    public function test_blog_entries_cover_published_only_with_slugged_urls(): void
    {
        $author = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $pub = Blog::create(['user_id' => $author->id, 'title' => 'Live Post '.Str::random(4), 'slug' => 'live-'.Str::random(5), 'description' => 'x', 'status' => 'publish']);
        Blog::create(['user_id' => $author->id, 'title' => 'Pending '.Str::random(4), 'slug' => 'pending-'.Str::random(5), 'description' => 'x', 'status' => 'pending']);

        $locs = array_column(SitemapService::blogEntries(), 'loc');

        $this->assertContains(route('blog.single', [$pub->id, $pub->slug]), $locs, 'published blog is present');
        foreach ($locs as $loc) {
            $this->assertStringNotContainsString('/pending-', $loc, 'unpublished blog must be excluded');
        }
    }

    public function test_marketing_entries_carry_locale_alternates_in_prefix_mode(): void
    {
        Language::query()->update(['active' => 0]);
        foreach (['ar', 'en', 'tr'] as $code) {
            Language::updateOrCreate(['code' => $code], ['name' => strtoupper($code), 'direction' => $code === 'ar' ? 'rtl' : 'ltr', 'active' => 1]);
        }
        config(['settings.include_language_code' => 1]);
        config(['laravellocalization.supportedLocales' => get_active_languages()]);

        $entries = SitemapService::marketingEntries();
        $home = collect($entries)->first(fn ($e) => rtrim($e['loc'], '/') === rtrim(url('/'), '/'));

        $this->assertNotNull($home, 'unprefixed home entry present');
        $this->assertCount(3, $home['alternates'], 'one alternate per active language');
        $this->assertStringEndsWith('/en', $home['alternates']['en']);
        $this->assertStringNotContainsString('/ar', $home['alternates']['ar'], 'default locale unprefixed');

        // every locale variant is its own <url> entry
        $homeVariants = collect($entries)->filter(fn ($e) => in_array($e['loc'], $home['alternates'], true));
        $this->assertCount(3, $homeVariants);
    }

    public function test_marketing_entries_have_no_alternates_in_session_mode(): void
    {
        config(['settings.include_language_code' => 0]);

        foreach (SitemapService::marketingEntries() as $entry) {
            $this->assertArrayNotHasKey('alternates', $entry);
        }
    }

    public function test_sitemap_index_route_serves_sections(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);

        $index = $this->get('/sitemap.xml');
        $index->assertOk()->assertHeader('Content-Type', 'application/xml');
        $index->assertSeeHtml('sitemap-marketing.xml');
        $index->assertSeeHtml('sitemap-blog.xml');
        $index->assertSeeHtml('sitemap-restaurants.xml');

        $this->get('/sitemap-marketing.xml')->assertOk()->assertSeeHtml('<urlset');
        $this->get('/sitemap-blog.xml')->assertOk();
        $this->get('/sitemap-restaurants.xml')->assertOk();
    }

    public function test_no_lang_switch_urls_anywhere(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);

        foreach (['/sitemap.xml', '/sitemap-marketing.xml', '/sitemap-blog.xml', '/sitemap-restaurants.xml'] as $path) {
            $this->get($path)->assertDontSeeHtml('/lang/');
        }
    }

    public function test_standalone_pages_are_single_entries_without_alternates(): void
    {
        \Juwri\Core\Models\Language::query()->update(['active' => 0]);
        foreach (['ar', 'en'] as $code) {
            \Juwri\Core\Models\Language::updateOrCreate(['code' => $code], ['name' => strtoupper($code), 'direction' => $code === 'ar' ? 'rtl' : 'ltr', 'active' => 1]);
        }
        config(['settings.include_language_code' => 1]);
        config(['laravellocalization.supportedLocales' => get_active_languages()]);

        $entries = SitemapService::marketingEntries();

        // /marketplace, /apps/store, /developers* are registered outside the
        // locale groups — they must appear ONCE, with no alternate set (a
        // locale-prefixed variant would 404).
        foreach (['marketplace.index', 'apps.store.index', 'developers.apps'] as $name) {
            if (! \Illuminate\Support\Facades\Route::has($name)) {
                continue;
            }
            $url = rtrim(route($name), '/');
            $matches = collect($entries)->filter(fn ($e) => $e['loc'] === $url);
            $this->assertCount(1, $matches, "$name appears exactly once");
            $this->assertArrayNotHasKey('alternates', $matches->first(), "$name has no locale alternates");
        }
    }

    /** SEO-growth W0.3: the new marketing surfaces are discoverable in the sitemap. */
    public function test_marketing_entries_include_the_new_seo_pages(): void
    {
        config(['settings.include_language_code' => 0]);
        $locs = collect(SitemapService::marketingEntries())->pluck('loc')->map(fn ($u) => rtrim($u, '/'))->all();

        foreach (['features', 'themes', 'whatsapp.landing', 'no-commission', 'tools.commission-calculator'] as $name) {
            $this->assertContains(rtrim(route($name), '/'), $locs, "$name in sitemap");
        }
        $this->assertContains(rtrim(route('country.landing', \App\Services\CountryDefs::slugs()[0]), '/'), $locs, 'a country page in sitemap');
        $this->assertContains(rtrim(route('compare.show', \Juwri\Modules\Content\Services\CompareDefs::slugs()[0]), '/'), $locs, 'a compare page in sitemap');
        $this->assertContains(rtrim(route('guides.show', \Juwri\Modules\Content\Services\GuideDefs::slugs()[0]), '/'), $locs, 'a guide page in sitemap');
    }
}
