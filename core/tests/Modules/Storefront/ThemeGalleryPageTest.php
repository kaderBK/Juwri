<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I1 (SEO run): the /themes gallery page — every modern storefront theme as an
 * iPhone-framed live capture with a live-demo link.
 *
 * Rendered HTTP-through-controller (blade-direct render() misses the controller
 * contract, e.g. the demo-store existence probe); layout globals via
 * shareLayoutGlobals().
 */
class ThemeGalleryPageTest extends TestCase
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
    }

    public function test_themes_page_renders_through_controller(): void
    {
        $response = $this->get(route('themes'));

        $response->assertOk();
        // H1 keyword (assertSee escapes the needle, matching the escaped &amp;).
        $response->assertSee(___('Restaurant menu themes & website templates'));
        // <title> carries the KEYWORDS §Themes value verbatim.
        $response->assertSee(___('Restaurant Menu Themes & Website Templates — 19 Designs, Live Demos'));
        // Theme names (product names — never translated) and their live captures.
        $response->assertSee('Crave');
        $response->assertSee('Velora');
        $response->assertSee('themes/en/crave.webp?v=');
        $response->assertSeeHtml('th-grid');

        // Regression guard: this theme ships no mt-4/mt-5 spacing utilities and no
        // .visually-hidden — they render as 0 / are absent and collapse layout.
        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-4', $html, 'Dead mt-4 utility on /themes');
        $this->assertStringNotContainsString('mt-5', $html, 'Dead mt-5 utility on /themes');
        $this->assertStringNotContainsString('visually-hidden', $html, 'No .visually-hidden utility in this theme');
    }

    public function test_breadcrumb_jsonld_is_valid(): void
    {
        $html = $this->get(route('themes'))->getContent();

        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);

        // Verify-to-falsify: the page may emit several JSON-LD blocks (the layout
        // pushes an Organization block globally); find OUR BreadcrumbList among
        // them, parse it, and confirm the Home > Themes trail.
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1] ?? [], 'No JSON-LD script block emitted');

        $breadcrumb = null;
        foreach ($m[1] as $json) {
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded, 'A JSON-LD block failed to parse');
            if (($decoded['@type'] ?? null) === 'BreadcrumbList') {
                $breadcrumb = $decoded;
            }
        }
        $this->assertNotNull($breadcrumb, 'No BreadcrumbList JSON-LD on /themes');
        $this->assertCount(2, $breadcrumb['itemListElement']);
        $this->assertSame(___('Themes'), $breadcrumb['itemListElement'][1]['name']);
    }

    public function test_live_demo_link_appears_only_when_the_demo_store_exists(): void
    {
        // No demo stores seeded → the page still renders, but no live-demo link.
        $before = $this->get(route('themes'))->getContent();
        $this->assertStringNotContainsString(url('/crave-demo'), $before);

        // Seed the crave demo storefront the way ThemeShowcaseSeeder does.
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        Store::create([
            'user_id' => $owner->id, 'slug' => 'crave-demo', 'color' => '#111',
            'title' => 'Crave House', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $after = $this->get(route('themes'))->getContent();
        $this->assertStringContainsString(url('/crave-demo'), $after);
        $this->assertStringContainsString(___('View live demo'), $after);
    }

    public function test_footer_links_to_the_themes_page(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString(route('themes'), $html, 'Expected the /themes URL in the footer Product column');
    }
}
