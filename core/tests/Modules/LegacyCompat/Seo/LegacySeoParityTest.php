<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Seo;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use App\Models\Theme;
use App\Services\SitemapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Language;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Support\SitemapHostCache;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\Faq;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacySeo;
use Tests\TestCase;

/**
 * BL-031/033/034/035 on the migrated install (module on): language set command, content URLs (blog, CMS page,
 * marketplace and app detail), the old titles, the canonical-origin redirect, per-host sitemap caches and the one
 * indexing switch. Module off: nothing changes. Synthetic data only.
 */
class LegacySeoParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Cache::flush();
    }

    private function on(array $seo = []): void
    {
        config(['legacy_compat.enabled' => true, 'legacy_compat.seo' => array_replace(config('legacy_compat.seo'), $seo)]);
        LegacyCompatServiceProvider::activate($this->app);
    }

    /** @return list<string> */
    private function languageCodes(bool $active): array
    {
        return Language::query()->where('active', $active ? 1 : 0)->orderBy('code')->pluck('code')->all();
    }

    private function seedLanguages(): void
    {
        foreach (['ar' => 'rtl', 'de' => 'ltr', 'es' => 'ltr', 'fr' => 'ltr', 'hi' => 'ltr', 'pt' => 'ltr', 'ru' => 'ltr', 'tr' => 'ltr', 'ur' => 'rtl', 'zh' => 'ltr'] as $code => $dir) {
            Language::updateOrCreate(['code' => $code], ['name' => strtoupper($code), 'direction' => $dir, 'active' => 0]);
        }
        Language::query()->where('code', 'fr')->update(['active' => 1]);
    }

    public function test_locale_command_applies_the_configured_set_idempotently(): void
    {
        $codes = ['ar', 'en', 'fr', 'de', 'es', 'zh', 'ru', 'hi', 'tr', 'ur', 'pt'];
        $this->seedLanguages();
        $this->on(['locale_default' => 'ar', 'locales' => $codes, 'locale_prefix' => true]);
        PlatformSettings::set('lang', 'en');
        PlatformSettings::set('include_language_code', '0');

        $this->artisan('legacy:seo-locales', ['--check' => true])->assertExitCode(1);
        $this->assertSame(['fr'], array_values(array_diff($this->languageCodes(true), ['en'])), 'check changes nothing');

        $this->artisan('legacy:seo-locales')->assertExitCode(0);
        $this->assertSame('ar', PlatformSettings::string('lang'));
        $this->assertTrue(PlatformSettings::bool('include_language_code'));
        $expected = $codes;
        sort($expected);
        $this->assertSame($expected, $this->languageCodes(true), 'all 11 enabled');

        $this->artisan('legacy:seo-locales', ['--check' => true])->assertExitCode(0);
        $this->assertSame(12, LegacySeo::reconcile(LegacySeo::plan(), false)['hreflang'], '11 languages + x-default');
    }

    public function test_locale_command_deactivates_unlisted_languages_and_reports_missing_rows(): void
    {
        $this->seedLanguages();
        $this->on(['locale_default' => 'ar', 'locales' => ['ar', 'en', 'xx']]);

        $this->artisan('legacy:seo-locales')->assertExitCode(1); // xx has no language row
        $this->assertSame(['ar', 'en'], $this->languageCodes(true));
    }

    public function test_locale_command_refuses_when_the_module_is_off_or_nothing_is_configured(): void
    {
        $this->seedLanguages();
        config(['legacy_compat.enabled' => false, 'legacy_compat.seo.locale_default' => 'ar']);
        $this->artisan('legacy:seo-locales')->assertExitCode(1);
        $this->assertSame(['en', 'fr'], $this->languageCodes(true), 'module off: nothing written');

        $this->on(['locale_default' => '', 'locales' => []]);
        $this->artisan('legacy:seo-locales')->assertExitCode(1);
        $this->assertSame(['en', 'fr'], $this->languageCodes(true));
    }

    public function test_blog_post_keeps_its_id_and_slug_urls_and_unknown_ids_404(): void
    {
        $this->on();
        config(['settings.blog_enable' => 1]);
        $blog = new Blog(['title' => 'Post two', 'slug' => 'post-two', 'description' => 'd', 'image' => 'x.png', 'status' => 'publish']);
        $blog->id = 2;
        $blog->save();

        $this->get('/blog/2')->assertOk();
        $this->get('/blog/2/post-two')->assertOk();
        $this->get('/blog/999')->assertNotFound();
        $this->assertStringContainsString('/blog/2/post-two', SitemapService::buildXml(SitemapService::blogEntries()));
    }

    public function test_cms_legal_pages_open_at_page_slug_and_unknown_slug_404s(): void
    {
        $this->on();
        Page::create(['title' => 'Terms', 'slug' => 'terms-of-service', 'content' => 'c', 'active' => 1, 'translation_lang' => null]);
        Page::create(['title' => 'Privacy', 'slug' => 'the-privacy-policy', 'content' => 'c', 'active' => 1, 'translation_lang' => null]);

        $this->get('/page/terms-of-service')->assertOk();
        $this->get('/page/the-privacy-policy')->assertOk();
        $this->get('/page/no-such-page')->assertNotFound();
    }

    public function test_marketplace_and_app_store_detail_pages_200_for_valid_slugs_404_for_unknown(): void
    {
        $this->on();
        $theme = Theme::create(['name' => 'Crave', 'slug' => 'crave', 'status' => 'published', 'price' => 0, 'category' => 'cafe', 'tagline' => 'T']);
        $theme->versions()->create(['semver' => '1.0.0', 'manifest' => ['t' => 1], 'checksum' => hash('sha256', 'x'), 'status' => 'published']);
        App::create(['name' => 'Kiosk', 'slug' => 'kiosk', 'scopes' => ['menu:read'], 'status' => 'approved', 'category' => 'ops',
            'tagline' => 'T', 'billing_type' => 'free', 'price' => 0, 'billing_period_days' => 30]);

        $this->get('/marketplace/crave')->assertOk();
        $this->get('/apps/store/kiosk')->assertOk();
        $this->get('/marketplace/no-such-theme')->assertNotFound();
        $this->get('/apps/store/no-such-app')->assertNotFound();
    }

    public function test_home_in_the_root_language_carries_a_valid_faq_page_schema(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        $this->on();
        app()->setLocale('ar');
        Faq::create(['faq_title' => 'كم يستغرق الإعداد؟', 'faq_content' => '<p>دقائق.</p>', 'translation_lang' => 'ar', 'active' => 1]);
        Faq::create(['faq_title' => 'English only', 'faq_content' => 'x', 'translation_lang' => 'en', 'active' => 1]);

        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $faq = null;
        foreach ($m[1] as $json) {
            $d = json_decode($json, true);
            $this->assertNotNull($d, 'every JSON-LD block parses');
            if (($d['@type'] ?? '') === 'FAQPage') {
                $faq = $d;
            }
        }
        $this->assertNotNull($faq, 'FAQPage present on the root-language home');
        $this->assertSame('https://schema.org', $faq['@context']);
        $this->assertSame('Question', $faq['mainEntity'][0]['@type']);
        $this->assertSame('Answer', $faq['mainEntity'][0]['acceptedAnswer']['@type']);
        $this->assertSame('كم يستغرق الإعداد؟', $faq['mainEntity'][0]['name']);
        $this->assertCount(1, $faq['mainEntity'], 'only this language\'s questions');
    }

    public function test_old_titles_apply_on_contact_and_foodics_and_only_when_on(): void
    {
        $this->on();
        $this->assertStringContainsString('<title>Contact Us - ', $this->get('/contact')->assertOk()->getContent());
        $this->assertStringContainsString('Free Foodics Alternative: QR Menu &amp; WhatsApp Ordering - ', $this->get('/compare/foodics')->assertOk()->getContent());

        config(['seo.title_overrides' => []]);
        $this->assertStringNotContainsString('<title>Contact Us - ', $this->get('/contact')->assertOk()->getContent(), 'module off: the shipped copy');
    }

    public function test_title_parity_switch_off_leaves_the_shipped_copy(): void
    {
        config(['seo.title_overrides' => []]);
        $this->on(['title_parity' => false]);
        $this->assertSame([], config('seo.title_overrides'));
    }

    public function test_canonical_redirect_sends_www_and_http_to_https_apex_only_on_the_app_host(): void
    {
        config(['app.url' => 'https://platform.test']);
        $this->on(['canonical_redirect' => true]);

        $this->get('https://www.platform.test/pricing?x=1')->assertStatus(301)->assertRedirect('https://platform.test/pricing?x=1');
        $this->get('http://platform.test/pricing')->assertStatus(301)->assertRedirect('https://platform.test/pricing');
        $this->get('https://platform.test/pricing')->assertOk();
        $this->get('http://platform.test/.well-known/acme-challenge/x')->assertNotFound(); // not redirected
        $this->get('http://shop.other.test/pricing')->assertOk(); // another host on this install: untouched
    }

    public function test_canonical_redirect_is_off_by_default(): void
    {
        config(['app.url' => 'https://platform.test']);
        $this->on();
        $this->get('http://platform.test/pricing')->assertOk();
        $this->get('https://www.platform.test/pricing')->assertOk();
    }

    public function test_sitemap_sections_are_cached_per_host(): void
    {
        config(['app.url' => 'https://platform.test']);
        $this->on();

        $other = $this->get('https://other-host.test/sitemap-marketing.xml')->assertOk();
        $this->assertStringContainsString('https://other-host.test', $other->getContent());
        $platform = $this->get('https://platform.test/sitemap-marketing.xml')->assertOk();
        $this->assertStringNotContainsString('other-host.test', $platform->getContent());
        $this->assertStringContainsString('https://platform.test', $platform->getContent());
        $this->assertTrue(Cache::has('sitemap.marketing'), 'the platform host keeps the plain key');

        $before = SitemapHostCache::generation();
        SitemapHostCache::forgetVariants();
        $this->assertSame($before + 1, SitemapHostCache::generation());
    }

    public function test_one_switch_decides_indexing(): void
    {
        $this->on();
        $this->app['env'] = 'production';
        config(['seo.noindex' => false]);
        $this->get('http://shop.localhost/robots.txt')->assertOk()->assertHeaderMissing('X-Robots-Tag');

        config(['seo.noindex' => true]);
        $this->get('http://shop.localhost/robots.txt')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->app['env'] = 'staging';
        config(['seo.noindex' => false]);
        $this->get('http://shop.localhost/robots.txt')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_brand_and_sitemap_host_are_configured_values(): void
    {
        $this->on();
        config(['settings.site_title' => 'Acme Menus', 'app.url' => 'https://platform.test']);

        $llms = $this->get('https://platform.test/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Acme Menus', $llms);
        $this->assertStringContainsString('https://platform.test', $llms);
        $this->assertStringNotContainsString('juwri.com', $this->get('https://platform.test/sitemap.xml')->assertOk()->getContent());
    }
}
