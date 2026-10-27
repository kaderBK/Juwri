<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Footer links fix: legal links resolve to real published CMS pages (no
 * javascript:void(0) dead ends), product links point at real routes.
 */
class FooterLinksTest extends TestCase
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
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
    }

    private function page(array $attrs): Page
    {
        return Page::create(array_merge([
            'title' => 'T', 'slug' => 'p-'.uniqid(), 'description' => 'x',
            'active' => 1, 'type' => '0', 'translation_lang' => 'en',
        ], $attrs));
    }

    public function test_resolves_active_public_page_for_current_locale(): void
    {
        $this->page(['slug' => 'terms-of-service', 'translation_lang' => 'en']);
        $this->page(['slug' => 'terms-of-service', 'translation_lang' => 'ar']);

        app()->setLocale('en');
        $this->assertSame('en', active_cms_page('terms-of-service')->translation_lang);

        app()->setLocale('ar');
        $this->assertSame('ar', active_cms_page('terms-of-service')->translation_lang, 'prefers exact-locale row');
    }

    public function test_ignores_inactive_and_login_only_pages(): void
    {
        $this->page(['slug' => 'cookie-policy', 'active' => 0]);          // inactive
        $this->page(['slug' => 'careers-x', 'type' => '1']);             // login-only

        app()->setLocale('en');
        $this->assertNull(active_cms_page('cookie-policy'), 'inactive page not linked');
        $this->assertNull(active_cms_page('careers-x'), 'login-only page not linked');
    }

    public function test_missing_page_returns_null_so_no_dead_link_renders(): void
    {
        app()->setLocale('en');
        $this->assertNull(active_cms_page('does-not-exist'));
    }

    public function test_locale_agnostic_page_is_a_fallback(): void
    {
        $this->page(['slug' => 'the-privacy-policy', 'translation_lang' => null]);

        app()->setLocale('ar');
        $this->assertNotNull(active_cms_page('the-privacy-policy'), 'null-locale page shows on any locale');
    }
}
