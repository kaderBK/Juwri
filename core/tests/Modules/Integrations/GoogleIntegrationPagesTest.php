<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\GuideDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth I2 — Google integration pages (Reserve with Google + Google Wallet
 * loyalty), served through the shared guides/{slug} template.
 *
 * Rendered HTTP-through-controller. Asserts 200, the H1 keyword, valid
 * BreadcrumbList + FAQPage JSON-LD, canonical, and the no-dead-utility guard.
 */
class GoogleIntegrationPagesTest extends TestCase
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
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    public function test_reserve_with_google_page_renders(): void
    {
        $response = $this->get(route('guides.show', 'reserve-with-google'));

        $response->assertOk();
        $response->assertSee('Reserve with Google for Restaurants — Setup Guide');
        $response->assertSee('waitlist');

        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('guides.show', 'reserve-with-google').'"', $html);
    }

    public function test_google_wallet_loyalty_page_renders(): void
    {
        $response = $this->get(route('guides.show', 'google-wallet-loyalty'));

        $response->assertOk();
        $response->assertSee('Google Wallet Loyalty Cards for Restaurants');
        $response->assertSee('Save to Google Wallet');
    }

    public function test_both_pages_are_whitelisted_and_emit_faq_schema(): void
    {
        $slugs = GuideDefs::slugs();
        foreach (['reserve-with-google', 'google-wallet-loyalty'] as $slug) {
            $this->assertContains($slug, $slugs);

            $html = $this->get(route('guides.show', $slug))->getContent();
            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
            $types = [];
            foreach ($m[1] as $json) {
                $decoded = json_decode(trim($json), true);
                $this->assertSame(JSON_ERROR_NONE, json_last_error(), "Invalid JSON-LD on {$slug}");
                if (isset($decoded['@type'])) {
                    $types[] = $decoded['@type'];
                }
            }
            $this->assertContains('BreadcrumbList', $types, "{$slug} missing BreadcrumbList");
            $this->assertContains('FAQPage', $types, "{$slug} missing FAQPage");
        }
    }
}
