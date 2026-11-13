<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Controllers\HomeController;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GSC "Duplicate, Google chose different canonical than user" included CMS pages
 * such as /hi/page/the-privacy-policy: a language-neutral Page renders identical
 * content under every /{locale}/ prefix, yet the landing layout emitted the
 * sitewide 11-locale seo_hreflang_tags() + a self-referential canonical, so each
 * prefix advertised phantom alternates. HomeController@pageSeo now scopes this:
 *   - >=2 real per-locale rows  → the true translation set + self canonical
 *   - language-neutral (NULL)   → NO alternates + a default-locale canonical
 *   - single specific-locale    → NO alternates + self canonical
 * Prefix-mode routes can't register under the session-mode test boot (see
 * SeoHreflangTest), so the helper is exercised directly with faked config; live
 * output is curl-verified after deploy.
 */
class PageHreflangScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        Language::query()->update(['active' => 0]);
        foreach ([['ar', 'rtl'], ['en', 'ltr'], ['tr', 'ltr']] as [$code, $dir]) {
            Language::updateOrCreate(['code' => $code], ['name' => strtoupper($code), 'direction' => $dir, 'active' => 1]);
        }
    }

    private function enablePrefixMode(): void
    {
        config(['settings.include_language_code' => 1]);
        config(['laravellocalization.supportedLocales' => get_active_languages()]);
    }

    /** Invoke the private HomeController@pageSeo(Page): array. */
    private function pageSeo(Page $page): array
    {
        $m = new \ReflectionMethod(HomeController::class, 'pageSeo');
        $m->setAccessible(true);

        return $m->invoke(new HomeController(), $page);
    }

    public function test_neutral_page_suppresses_hreflang_and_consolidates_canonical(): void
    {
        $this->enablePrefixMode();

        $page = new Page(['slug' => 'privacy-policy', 'translation_lang' => null, 'active' => 1]);
        [$hreflang, $canonical] = $this->pageSeo($page);

        $this->assertSame('', $hreflang, 'a language-neutral CMS page must advertise no locale alternates');
        $this->assertNotEmpty($canonical);
        // default locale (ar) is hidden in URLs → the consolidated canonical is unprefixed.
        $this->assertStringNotContainsString('/en/', $canonical);
        $this->assertStringNotContainsString('/tr/', $canonical);
        $this->assertStringNotContainsString('/ar/', $canonical);
    }

    public function test_translated_page_emits_only_its_real_locales(): void
    {
        $this->enablePrefixMode();

        // Two genuine per-locale rows sharing a slug (query-builder insert bypasses
        // the Sluggable trait, which would otherwise disambiguate the second slug).
        $table = (new Page)->getTable();
        DB::table($table)->insert([
            ['title' => 'About EN', 'content' => 'x', 'slug' => 'about', 'type' => 0, 'translation_lang' => 'en', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'About TR', 'content' => 'x', 'slug' => 'about', 'type' => 0, 'translation_lang' => 'tr', 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $page = Page::where('slug', 'about')->where('translation_lang', 'en')->firstOrFail();

        [$hreflang, $canonical] = $this->pageSeo($page);

        $this->assertStringContainsString('hreflang="en"', $hreflang);
        $this->assertStringContainsString('hreflang="tr"', $hreflang);
        $this->assertStringContainsString('hreflang="x-default"', $hreflang);
        $this->assertStringNotContainsString('hreflang="ar"', $hreflang, 'ar has no translation row → must not be advertised');
        $this->assertNotEmpty($canonical);
    }

    public function test_single_locale_page_self_canonicals_without_alternates(): void
    {
        $this->enablePrefixMode();

        $page = new Page(['slug' => 'solo-en', 'translation_lang' => 'en', 'active' => 1]);
        [$hreflang, $canonical] = $this->pageSeo($page);

        $this->assertSame('', $hreflang, 'a single specific-locale page has no sibling translations to advertise');
        $this->assertNotEmpty($canonical);
    }

    public function test_session_mode_is_self_canonical_with_no_alternates(): void
    {
        config(['settings.include_language_code' => 0]);

        $page = new Page(['slug' => 'privacy-policy', 'translation_lang' => null, 'active' => 1]);
        [$hreflang, $canonical] = $this->pageSeo($page);

        $this->assertSame('', $hreflang);
        $this->assertNotEmpty($canonical);
    }
}
