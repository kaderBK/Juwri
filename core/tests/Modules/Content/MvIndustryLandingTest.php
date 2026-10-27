<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Verticals\Services\VerticalLandingDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/mv-marketing — the per-vertical (industry) landing pages at
 * industries/{slug}: every authored def renders, an unknown slug 404s (no
 * fabricated industry), and a page links its live demo when the store exists.
 */
class MvIndustryLandingTest extends TestCase
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
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_every_authored_vertical_page_renders(): void
    {
        $slugs = VerticalLandingDefs::slugs();
        $this->assertNotEmpty($slugs, 'no vertical defs authored');

        foreach ($slugs as $slug) {
            $def = VerticalLandingDefs::find($slug);
            $block = VerticalLandingDefs::localize($def, 'en');
            $html = $this->get(route('industries.show', $slug))->assertOk()->getContent();
            // The def's own title + canonical land on the page.
            $this->assertStringContainsString(e($block['title']), $html, "[$slug] title missing");
            $this->assertStringContainsString(route('industries.show', $slug), $html, "[$slug] canonical missing");
        }
    }

    public function test_unknown_vertical_404s(): void
    {
        $this->get(route('industries.show', 'not-a-vertical'))->assertNotFound();
    }

    public function test_page_links_its_live_demo_when_the_store_exists(): void
    {
        // retail's def demo_slug is retail-demo; seed that store so the CTA resolves.
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        Store::create([
            'user_id' => $owner->id, 'store_type' => 'retail', 'slug' => 'retail-demo', 'color' => '#111',
            'title' => 'Demo', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);

        $html = $this->get(route('industries.show', 'retail'))->assertOk()->getContent();
        $this->assertStringContainsString(url('/retail-demo'), $html);
    }

    public function test_arabic_locale_renders_the_ar_block(): void
    {
        app()->setLocale('ar');
        // Session-driven locale: hit the switch route the storefront uses.
        $ar = VerticalLandingDefs::localize(VerticalLandingDefs::find('retail'), 'ar');
        $html = $this->withSession(['locale' => 'ar'])->get(route('industries.show', 'retail'))->getContent();
        // At minimum the AR title appears when the app locale is ar.
        $this->assertStringContainsString(e($ar['title']), $html);
    }
}
