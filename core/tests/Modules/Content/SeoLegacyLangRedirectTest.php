<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Controllers\HomeController;
use Juwri\Core\Models\Language;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/seo R3. Tests boot in session mode (routes for prefix mode are not
 * registered under sqlite), so the redirect controller is exercised directly;
 * the live route wiring is browser-verified (BUILD-LOG R3).
 */
class SeoLegacyLangRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'tr'], ['name' => 'Türkçe', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'de'], ['name' => 'Deutsch', 'direction' => 'ltr', 'active' => 0]);

        config(['settings.include_language_code' => 1]);
        // mcamara validates config('app.locale') (ar via .env DEFAULT_LANGUAGE)
        // against this registry on first use — ar must be present.
        config(['laravellocalization.supportedLocales' => get_active_languages()]);
    }

    public function test_legacy_lang_url_permanently_redirects_to_localized_home(): void
    {
        $response = app(HomeController::class)->localizeRedirect('tr');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringEndsWith('/tr', $response->getTargetUrl());
    }

    public function test_default_locale_redirects_to_unprefixed_home(): void
    {
        $response = app(HomeController::class)->localizeRedirect('ar');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(url('/'), rtrim($response->getTargetUrl(), '/'));
    }

    public function test_inactive_language_404s(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(HomeController::class)->localizeRedirect('de');
    }

    public function test_unknown_language_404s(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(HomeController::class)->localizeRedirect('xx');
    }
}
