<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\Theme;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stores P1-6: the theme detail page — screenshot gallery, demo preview CTA,
 * version/changelog block, seller + support block, and the guest/owner states
 * of the buy/apply panel.
 */
class MarketplaceDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
    }

    private function makeTheme(array $overrides = []): Theme
    {
        $theme = Theme::create(array_merge([
            'name' => 'Noir', 'slug' => 'noir', 'status' => 'published', 'price' => 49,
            'category' => 'fine_dining', 'tagline' => 'Dark elegant menu',
            'support_url' => 'https://help.test/noir',
        ], $overrides));
        $theme->versions()->create(['semver' => '1.0.0', 'manifest' => ['description' => 'A refined dark theme'], 'checksum' => hash('sha256', 'noir1'), 'status' => 'published']);

        return $theme;
    }

    public function test_detail_shows_gallery_version_seller_support(): void
    {
        $seller = User::factory()->create(['name' => 'Studio Ink']);
        $theme = $this->makeTheme(['seller_id' => $seller->id]);
        $theme->media()->create(['disk_path' => 'storage/marketplace/theme/1/a.webp', 'position' => 0, 'kind' => 'screenshot']);
        $theme->media()->create(['disk_path' => 'storage/marketplace/theme/1/b.webp', 'position' => 1, 'kind' => 'screenshot']);

        $res = $this->get(route('marketplace.show', 'noir'))->assertOk();
        $res->assertSee('Noir');
        $res->assertSee('Studio Ink');                          // seller block
        $res->assertSeeHtml('a.webp');                       // gallery screenshot
        $res->assertSeeHtml('thumbs');                       // thumb strip (2 shots)
        $res->assertSee('1.0.0');                               // version/changelog
        $res->assertSee('A refined dark theme');                // about (manifest description)
        $res->assertSeeHtml('https://help.test/noir');       // support link
        $res->assertSeeHtml(route('marketplace.preview', 'noir')); // demo CTA
    }

    public function test_platform_theme_shows_platform_seller(): void
    {
        $this->makeTheme(['seller_id' => null]);
        $this->withSession(['locale' => 'en'])
            ->get(route('marketplace.show', 'noir'))->assertOk()->assertSee('Platform');
    }

    public function test_guest_sees_sign_in_cta(): void
    {
        $this->makeTheme();
        $res = $this->get(route('marketplace.show', 'noir'))->assertOk();
        $res->assertSeeHtml(route('login'));
        $res->assertDontSeeHtml('id="buyBtn"');              // no buy button for guests
    }

    public function test_owner_without_entitlement_sees_buy_button(): void
    {
        $this->makeTheme(['price' => 49]);
        $this->actingAs(User::factory()->create(['status' => 1, 'email_verified_at' => now()]));

        $this->get(route('marketplace.show', 'noir'))->assertOk()->assertSeeHtml('id="buyBtn"');
    }

    public function test_free_theme_hides_buy_button(): void
    {
        $this->makeTheme(['price' => 0]);
        $this->actingAs(User::factory()->create(['status' => 1, 'email_verified_at' => now()]));

        $this->get(route('marketplace.show', 'noir'))->assertOk()->assertDontSeeHtml('id="buyBtn"');
    }

    public function test_draft_theme_is_404(): void
    {
        $this->makeTheme(['status' => 'draft', 'slug' => 'draft-noir']);
        $this->get(route('marketplace.show', 'draft-noir'))->assertNotFound();
    }
}
