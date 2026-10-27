<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature icon grid (home) + the /features page it links to.
 *
 * Rendered HTTP-through-controller (blade-direct render() misses the
 * controller contract); layout globals via shareLayoutGlobals().
 */
class FeatureGridPageTest extends TestCase
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

    public function test_home_shows_feature_grid_linking_to_features_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(___('Your digital menu, online ordering and POS in one place'));
        $response->assertSee(___('See all features'));
        $response->assertSee(route('features'));
        // A tile that exists nowhere else on the home page.
        $response->assertSee(___('Ordering dashboard'));
    }

    public function test_features_page_renders_through_controller(): void
    {
        $response = $this->get(route('features'));

        $response->assertOk();
        $response->assertSee(___('Everything you need for your restaurant in one platform'));
        // Panel-backed rows and their deep-link anchors.
        $response->assertSee(___('QR menu builder'));
        $response->assertSee(___('A full register with shifts and drawers, plus kitchen displays that route every ticket to the right station.'));
        $response->assertSeeHtml('id="f-qr-menu-builder"');
        // Product screenshots were replaced by kit mock panels.
        $response->assertDontSeeHtml('features/1.webp');
        $response->assertSeeHtml('mk-panel');
        // Chapter anchors + widget copy reused from the home sections.
        $response->assertSeeHtml('id="operations"');
        $response->assertSee(___('Grill station'));
        $response->assertSee(___('Get paid your way. Stay legal without thinking.'));

        // Regression guard: no Bootstrap spacing utilities — the rhythm comes
        // from the kit (.mk-split rows with a .fp-copy column).
        $html = $response->getContent();
        $this->assertStringNotContainsString('mt-5', $html, 'Dead mt-5 utility reintroduced on /features');
        $this->assertStringNotContainsString('mt-4', $html, 'Dead mt-4 utility reintroduced on /features');
        $this->assertStringContainsString('fp-copy', $html);
    }

    public function test_features_link_present_in_navbar_and_footer(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($html, route('features')),
            'Expected the features URL in navbar, footer and the grid CTA'
        );
    }
}
