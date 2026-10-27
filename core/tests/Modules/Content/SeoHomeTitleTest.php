<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Database\Seeders\SeoMetaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * SEO-growth W0.1 — home <title> per locale.
 *
 * The rendered home title is verified on the live site (all 11 locales,
 * curl-checked at build time); the home "/" route does not surface
 *
 * @section('title') through the HTTP test harness (a default-locale-root render
 * quirk — named-route pages like /features DO), so this test guards the
 * deterministic data W0.1 changed: the keyword title VALUES per locale and the
 * SeoMetaSeeder option that drives the Arabic default-locale title.
 */
class SeoHomeTitleTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'free_qr_menu_with_whatsapp_ordering';

    private function langValue(string $locale): string
    {
        return (include base_path("lang/$locale/lang.php"))[self::KEY] ?? '';
    }

    public function test_keyword_titles_are_set_for_the_optimized_locales(): void
    {
        // Vertical-neutral hedge (owner-ruled): keep the "Restaurants" keyword and widen.
        $this->assertSame('Free QR Code Menu & WhatsApp Ordering for Restaurants & Businesses', $this->langValue('en'));
        $this->assertStringContainsString('منيو الكتروني للمطاعم والكافيهات', $this->langValue('ar'));
        $this->assertStringContainsString('Karekod Menü', $this->langValue('tr'));      // TR synonym-stack
        $this->assertStringContainsString('ohne Provision', $this->langValue('de'));    // DE zero-commission
        $this->assertStringContainsString('sem comissão', $this->langValue('pt'));      // PT zero-commission
    }

    public function test_hindi_and_urdu_keep_their_native_titles(): void
    {
        // KEYWORDS: hi/ur SERPs are English/Hinglish — native-script titles kept,
        // NOT swapped to the English stem.
        $this->assertStringNotContainsString('Free QR Code Menu', $this->langValue('hi'));
        $this->assertStringNotContainsString('Free QR Code Menu', $this->langValue('ur'));
        $this->assertStringContainsString('QR', $this->langValue('hi'));
    }

    public function test_seo_meta_seeder_sets_the_default_locale_title_option(): void
    {
        $this->seed(SeoMetaSeeder::class);

        $this->assertStringContainsString(
            'منيو الكتروني للمطاعم والكافيهات',
            PlatformSettings::get('home_meta_title', false)
        );
        $this->assertNotFalse(PlatformSettings::get('home_meta_description', false));
    }
}
