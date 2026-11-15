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
use Juwri\Modules\Storefront\Database\Seeders\LiquidShowcaseSeeder;
use Tests\TestCase;

/**
 * The marketplace's LIVE DEMO.
 *
 * Every listing must lead to a real storefront — one owned by the showcase
 * account — that renders THAT theme. The Blade showcase has always had one
 * (the Blade seeder points themes.demo_slug at the demo store); the Liquid
 * listings had none, because the Blade seeder skips a Liquid twin's Blade
 * sibling (a slug collision) and the Studio starters never had a store at
 * all. So "live demo" on a Liquid theme led to a synthetic preview, or to the
 * fallback storefront — never to the theme the visitor was looking at.
 *
 * These tests pin the button (it points at the demo store, not the preview)
 * and the seeder that gives the Liquid listings their demo.
 */
class MarketplaceLiquidDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /** A Liquid listing, its demo store, and the owner of that demo. */
    private function makeLiquidTheme(string $slug = 'blooms', string $demoSlug = 'blooms-demo'): Theme
    {
        $owner = User::factory()->create(['email' => 'showcase-demo@example.com']);
        Store::create([
            'user_id' => $owner->id, 'title' => 'Blooms Demo', 'slug' => $demoSlug,
            'color' => '#6d272b', 'store_type' => 'clothing',
        ]);

        $theme = Theme::create([
            'name' => 'Blooms', 'slug' => $slug, 'status' => 'published',
            'engine' => 'liquid', 'demo_slug' => $demoSlug,
            'price' => 0, 'category' => 'general', 'tagline' => 'An editorial boutique.',
            'seller_id' => null,
        ]);
        $theme->versions()->create([
            'semver' => '1.2.10', 'manifest' => ['description' => 'An editorial boutique.', 'vertical' => 'clothing'],
            'checksum' => hash('sha256', 'liquid:'.$slug), 'status' => 'published',
        ]);

        return $theme;
    }

    public function test_the_live_demo_button_leads_to_the_demo_store(): void
    {
        $this->makeLiquidTheme();

        $res = $this->withSession(['locale' => 'en'])->get(route('marketplace.show', 'blooms'))->assertOk();

        $res->assertSee(url('/blooms-demo'), false);
        $res->assertSee('View live demo');
    }

    public function test_a_listing_without_a_demo_falls_back_to_the_preview(): void
    {
        $theme = $this->makeLiquidTheme();
        $theme->update(['demo_slug' => null]);

        $res = $this->withSession(['locale' => 'en'])->get(route('marketplace.show', 'blooms'))->assertOk();

        $res->assertSee(route('marketplace.preview', 'blooms'), false);
    }

    public function test_the_seeder_gives_a_liquid_listing_its_own_demo_store(): void
    {
        // A listing with NO demo, and the vertical's showcase store to copy.
        $theme = $this->makeLiquidTheme();
        $theme->update(['demo_slug' => null]);
        // Free the slug the seeder wants (the fixture already used it).
        Store::query()->where('slug', 'blooms-demo')->delete();

        $owner = User::query()->where('email', 'showcase-demo@example.com')->firstOrFail();
        $source = Store::create([
            'user_id' => $owner->id, 'title' => 'Northgate Demo', 'slug' => 'clothing-demo',
            'color' => '#6d272b', 'store_type' => 'clothing',
        ]);

        $this->seed(LiquidShowcaseSeeder::class);

        $theme->refresh();
        $this->assertSame('blooms-demo', $theme->demo_slug, 'the listing must link its demo');

        $demo = Store::query()->where('slug', 'blooms-demo')->first();
        $this->assertNotNull($demo, 'the demo store must exist');
        $this->assertNotSame($source->id, $demo->id, 'the demo is a copy, not the source');
        $this->assertSame(
            'mp:blooms@1.2.10',
            StoreSettings::legacy((int) $demo->id, 'store_template'),
            'the demo must render the theme it demonstrates'
        );
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $theme = $this->makeLiquidTheme();

        $this->seed(LiquidShowcaseSeeder::class);

        $theme->refresh();
        $this->assertSame('blooms-demo', $theme->demo_slug);
        $this->assertSame(1, Store::query()->where('slug', 'blooms-demo')->count(), 'no second demo store');
    }
}
