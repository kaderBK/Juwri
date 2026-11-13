<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2-9: the marketplace (Liquid) render integration at StoreController site B.
 * An `mp:<slug>@<version>` template renders through the sandboxed pipeline
 * (Drops + whitelist + commerce runtime + budget); any failure falls back to
 * the first-party Blade theme — a diner never sees a blank menu.
 */
class MarketplaceLiquidRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        // Same global-share + middleware posture as AuroraPublicViewTest — the
        // Blade fallback path renders layout partials that read $settings.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/brokentest'));
        parent::tearDown();
    }

    private function makeRestaurant(string $template): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'Sub', 'description' => 'Desc',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);

        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        StoreOption::updateStoreOption($store->id, 'restaurant_on_table_order', '1');

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Liquid Burger', 'price' => 12, 'active' => '1']);

        return $store;
    }

    public function test_resolver_parses_mp_slug_and_rejects_traversal(): void
    {
        $this->assertNotNull(MarketplaceThemeResolver::resolve('mp:crave@1'));
        $this->assertNull(MarketplaceThemeResolver::resolve('crave'));           // legacy slug → Blade path
        $this->assertNull(MarketplaceThemeResolver::resolve('mp:../../evil@1')); // traversal → rejected
        $this->assertNull(MarketplaceThemeResolver::resolve('mp:nope@9'));       // unknown → null
    }

    public function test_mp_template_renders_through_the_liquid_pipeline(): void
    {
        $store = $this->makeRestaurant('mp:crave@1');

        $resp = $this->get(route('publicView', $store->slug));

        $resp->assertOk();
        $html = $resp->getContent();
        $this->assertStringContainsString('crave-lq', $html, 'Liquid theme markup rendered');
        $this->assertStringContainsString('Liquid Burger', $html, 'menu item from the Drop contract');
        $this->assertStringContainsString('data-mb-add', $html, 'commerce runtime binding');
        $this->assertStringContainsString('window.MB_COMMERCE=', $html, '{% commerce %} config emitted');
        $this->assertStringContainsString('commerce-runtime/v1/commerce.js', $html, 'versioned runtime bundle');
        $this->assertStringContainsString($store->title, $html);
    }

    public function test_crave_theme_ships_the_catalog_bindings(): void
    {
        $store = $this->makeRestaurant('mp:crave@1');
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'French', 'direction' => 'ltr', 'active' => 1]);
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['en', 'fr']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        // Bucket B — runtime-driven interactivity, declared by the theme.
        $this->assertStringContainsString('data-mb-search', $html, 'search input');
        $this->assertStringContainsString('data-mb-filter="vegan"', $html, 'dietary filter');
        $this->assertStringContainsString('data-mb-fav=', $html, 'favourite toggle');
        $this->assertStringContainsString('data-mb-set-lang="fr"', $html, 'language switch');
        // Bucket A — pure-Liquid additions.
        $this->assertStringContainsString('cl-badge', $html, 'open/close badge');
        // The item cards carry the searchable/filterable data attributes.
        $this->assertStringContainsString('data-mb-item="', $html);
    }

    public function test_crave_ships_the_product_view(): void
    {
        // P8-5: card-tap opens a full product view the runtime renders into.
        $store = $this->makeRestaurant('mp:crave@1');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-mb-view="product"', $html, 'product view');
        $this->assertStringContainsString('data-mb-product-mount', $html, 'product mount');
        $this->assertStringContainsString('data-mb-open="', $html, 'card opens the product');
    }

    public function test_crave_ships_the_confirmed_view(): void
    {
        // P8-7: an in-app confirmed screen the runtime renders after an order.
        $store = $this->makeRestaurant('mp:crave@1');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-mb-view="confirmed"', $html, 'confirmed view');
        $this->assertStringContainsString('data-mb-confirmed-mount', $html, 'confirmed mount');
    }

    public function test_crave_ships_the_splash_intro(): void
    {
        // P8-9: a one-time splash the runtime shows once per session.
        $store = $this->makeRestaurant('mp:crave@1');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-mb-splash', $html, 'splash overlay');
        $this->assertStringContainsString('cl-splash-title', $html, 'splash content');
    }

    public function test_crave_ships_the_catalog_sort_and_layout_controls(): void
    {
        // P8-4: server-rendered cards carry sort keys; the runtime reorders them
        // in place, and a sort select + layout toggle + empty-state drive it.
        $store = $this->makeRestaurant('mp:crave@1');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-mb-sort', $html, 'sort control');
        $this->assertStringContainsString('data-mb-layout-toggle', $html, 'layout toggle');
        $this->assertStringContainsString('data-mb-catalog', $html, 'catalog wrapper');
        $this->assertStringContainsString('data-mb-empty', $html, 'empty state');
        $this->assertStringContainsString('data-mb-price="12"', $html, 'card sort key: price');
        $this->assertStringContainsString('data-mb-rating=', $html, 'card sort key: rating');
    }

    public function test_crave_ships_the_spa_router_shell(): void
    {
        // P8-3: crave is now an SPA shell — home/favs/cart/profile views + a
        // bottom tab bar the runtime router drives.
        $store = $this->makeRestaurant('mp:crave@1');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-mb-view="home"', $html, 'home view');
        $this->assertStringContainsString('data-mb-view="cart"', $html, 'cart view');
        $this->assertStringContainsString('data-mb-view="profile"', $html, 'profile view');
        $this->assertStringContainsString('data-mb-tabbar', $html, 'bottom tab bar');
        $this->assertStringContainsString('data-mb-tab="home"', $html, 'home tab');
        $this->assertStringContainsString('data-mb-tab="cart"', $html, 'cart tab');
        // The menu still renders inside the home view (never blank).
        $this->assertStringContainsString('Liquid Burger', $html);
    }

    public function test_crave_banners_carry_the_auto_slider_binding(): void
    {
        // B7: active banners render the platform slider hook so the runtime
        // auto-advances them — zero theme JS.
        $store = $this->makeRestaurant('mp:crave@1');
        foreach (['One', 'Two'] as $i => $t) {
            MenuBanner::create([
                'store_id' => $store->id, 'title' => 'Promo '.$t,
                'image' => 'b.png', 'active' => true, 'position' => $i,
            ]);
        }

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('data-mb-slider', $html, 'slider binding on the banner rail');
        $this->assertStringContainsString('Promo One', $html, 'banner content rendered');
    }

    public function test_shell_reaches_head_parity_analytics_and_seo(): void
    {
        $store = $this->makeRestaurant('mp:crave@1');
        // P5-1 structured integrations set by the owner.
        StoreOption::updateStoreOption($store->id, 'integration_ga_id', 'G-SHELLTEST');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        // Analytics fire on the marketplace theme (was the gap).
        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=G-SHELLTEST', $html);
        // SEO/social + PWA parity.
        $this->assertStringContainsString('<link rel="canonical"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('name="csrf-token"', $html);
        // No crave-specific dark background forced on the shared shell.
        $this->assertStringNotContainsString('background:#15171a', $html);
    }

    public function test_broken_liquid_theme_falls_back_to_blade_never_blank(): void
    {
        // A stored theme whose template is invalid Liquid → RenderFailedException
        // → report() → the first-party Blade fallback renders instead.
        File::ensureDirectoryExists(storage_path('app/marketplace-themes/brokentest/1/templates'));
        File::put(
            storage_path('app/marketplace-themes/brokentest/1/templates/index.liquid'),
            '{% endif %}' // orphan end tag = parse error in any mode
        );

        $store = $this->makeRestaurant('mp:brokentest@1');

        $resp = $this->get(route('publicView', $store->slug));

        $resp->assertOk();
        $html = $resp->getContent();
        $this->assertStringNotContainsString('crave-lq', $html);
        $this->assertStringNotContainsString('window.MB_COMMERCE=', $html);
        // Blade fallback actually rendered content (not a blank page).
        $this->assertStringContainsString($store->title, $html);
    }

    public function test_plain_slug_keeps_the_first_party_blade_path(): void
    {
        $store = $this->makeRestaurant('crave');

        $resp = $this->get(route('publicView', $store->slug));

        $resp->assertOk();
        $this->assertStringContainsString('window.CRAVE', $resp->getContent(), 'first-party crave Blade theme untouched');
    }
}
