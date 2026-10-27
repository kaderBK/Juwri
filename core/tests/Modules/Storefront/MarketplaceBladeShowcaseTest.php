<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * Blade showcase: first-party store_templates listed in the Theme Store as
 * display-only cards. They must render in browse + detail like any listing, but
 * their preview links to a live demo store and their apply writes the PLAIN
 * template slug — never a `mp:` value, which would fall through to the Blade
 * fallback theme instead of the one the owner chose.
 */
class MarketplaceBladeShowcaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function makeBladeTheme(): Theme
    {
        // The live demo store this showcase previews.
        $demoOwner = User::factory()->create();
        Store::create(['user_id' => $demoOwner->id, 'title' => 'Matcha Demo', 'slug' => 'matcha-demo', 'color' => '#3f7d4f']);

        $theme = Theme::create([
            'name' => 'Matcha', 'slug' => 'matcha', 'status' => 'published',
            'engine' => 'blade', 'template_slug' => 'matcha', 'demo_slug' => 'matcha-demo',
            'price' => 0, 'category' => 'cafe', 'tagline' => 'A calm tea-room storefront.',
            'seller_id' => null,
            'translations' => ['ar' => ['tagline' => 'واجهة متجر هادئة لغرفة شاي.', 'description' => 'قالب هادئ ومريح لغرفة الشاي.']],
        ]);
        $theme->versions()->create([
            'semver' => '1.0', 'manifest' => ['description' => 'A calm tea-room storefront.'],
            'checksum' => hash('sha256', 'blade:matcha'), 'status' => 'published',
        ]);
        $theme->media()->create(['disk_path' => 'storage/marketplace/theme/1/shot-1.svg', 'position' => 1, 'kind' => 'screenshot']);

        return $theme;
    }

    public function test_blade_theme_is_listed_in_browse_with_a_builtin_badge(): void
    {
        $this->makeBladeTheme();

        $res = $this->withSession(['locale' => 'en'])->get(route('marketplace.index'))->assertOk();
        $res->assertSee('Matcha');
        $res->assertSeeHtml('badge-builtin');
    }

    public function test_blade_detail_renders_without_a_liquid_package(): void
    {
        $this->makeBladeTheme();

        $res = $this->withSession(['locale' => 'en'])->get(route('marketplace.show', 'matcha'))->assertOk();
        $res->assertSee('Matcha');
        $res->assertSeeHtml('badge-builtin');                          // "Built-in" marker
        $res->assertSeeHtml(route('marketplace.preview', 'matcha'));   // demo CTA
        $res->assertDontSeeHtml('id="buyBtn"');                        // free — never buyable
    }

    public function test_detail_shows_localized_copy_in_arabic_but_keeps_the_name(): void
    {
        $this->makeBladeTheme();

        $ar = $this->withSession(['locale' => 'ar'])->get(route('marketplace.show', 'matcha'))->assertOk();
        $ar->assertSee('واجهة متجر هادئة لغرفة شاي.');          // ar tagline
        $ar->assertSee('قالب هادئ ومريح لغرفة الشاي.');          // ar About
        $ar->assertSee('Matcha');                                // name stays verbatim

        // English falls back to the stored copy.
        $this->withSession(['locale' => 'en'])->get(route('marketplace.show', 'matcha'))
            ->assertOk()->assertSee('A calm tea-room storefront.');
    }

    public function test_blade_preview_redirects_to_the_live_demo_store(): void
    {
        $this->makeBladeTheme();

        $this->get(route('marketplace.preview', 'matcha'))
            ->assertRedirect(url('/matcha-demo'));
    }

    public function test_owner_apply_writes_the_plain_slug_not_an_mp_value(): void
    {
        $this->makeBladeTheme();
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'title' => 'My Cafe', 'slug' => 'my-cafe']);

        $this->actingAs($owner)
            ->postJson(route('marketplace.apply', 'matcha'), ['store_id' => $store->id])
            ->assertOk()->assertJson(['ok' => true, 'template' => 'matcha']);

        $this->assertSame('matcha', StoreSettings::legacy((int) $store->id, 'store_template'));
    }

    public function test_liquid_apply_still_writes_an_mp_value(): void
    {
        // Regression guard: the Blade branch must not change Liquid apply.
        $theme = Theme::create([
            'name' => 'Crave', 'slug' => 'crave-lq', 'status' => 'published',
            'engine' => 'liquid', 'price' => 0, 'category' => 'fast_food', 'seller_id' => null,
        ]);
        $theme->versions()->create(['semver' => '1', 'manifest' => ['description' => 'x'], 'checksum' => hash('sha256', 'x'), 'status' => 'published']);

        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'title' => 'Grill', 'slug' => 'grill']);

        $this->actingAs($owner)
            ->postJson(route('marketplace.apply', 'crave-lq'), ['store_id' => $store->id])
            ->assertOk()->assertJson(['ok' => true, 'template' => 'mp:crave-lq@1']);
    }
}
