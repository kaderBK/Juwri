<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\Seo\StoreJsonLdBuilder;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F8: storefront (QR-menu) Restaurant + Menu JSON-LD. Service assertions plus a
 * real render off the storefront page so the wiring — not just the builder — is
 * proven, and the block stays off the pages that share the layout.
 */
class StorefrontJsonLdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

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
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0', 'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '5551234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_for_storefront_uses_the_storefront_url(): void
    {
        $store = $this->store();
        $data = StoreJsonLdBuilder::forStorefront($store);

        $this->assertSame('Restaurant', $data['@type']);
        $this->assertSame(route('publicView', $store->slug), $data['url']);
    }

    /**
     * hasMenu is intentionally NOT emitted on the storefront (the plan menu-limit
     * is a render-time gate, so listing raw active items would leak hidden ones).
     * priceRange still resolves from the menu average.
     */
    public function test_for_storefront_omits_hasmenu_but_keeps_pricerange(): void
    {
        $store = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap', 'price' => 10, 'active' => '1', 'position' => 1]);

        $data = StoreJsonLdBuilder::forStorefront($store);
        $this->assertArrayNotHasKey('hasMenu', $data);
        $this->assertArrayHasKey('priceRange', $data);
    }

    /** End-to-end: the ld+json block renders on the storefront menu index (orbit, keep-list). */
    public function test_jsonld_block_renders_on_the_menu_index(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'store_template', 'orbit');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap', 'price' => 10, 'active' => '1', 'position' => 1]);

        $this->get(route('publicView', $store->slug))->assertOk()->assertSeeHtml('application/ld+json')->assertSeeHtml('"@type":"Restaurant"')->assertSeeHtml(route('publicView', $store->slug));
    }

    /**
     * F8b + B2 builder: groups by category (first-seen order) and lists ONLY the
     * menus handed in — the leak-safety property (the caller passes the
     * already-rendered set). B2 removed the old per-section 12 / 8-section sub-caps
     * in favour of a single total-item cap (tested separately), so a 15-item
     * category now emits all 15.
     */
    public function test_menu_from_menus_groups_all_items_in_first_seen_order(): void
    {
        $store = $this->store();
        $mains = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $drinks = MenuCategory::create(['store_id' => $store->id, 'name' => 'Drinks', 'active' => '1', 'position' => 2]);

        $menus = collect();
        for ($i = 1; $i <= 15; $i++) {
            $menus->push(Menu::create(['store_id' => $store->id, 'category_id' => $mains->id, 'name' => "Main $i", 'price' => 10, 'active' => '1', 'position' => $i])->load('category'));
        }
        $menus->push(Menu::create(['store_id' => $store->id, 'category_id' => $drinks->id, 'name' => 'Cola', 'price' => 3, 'active' => '1', 'position' => 1])->load('category'));

        $node = StoreJsonLdBuilder::menuFromMenus($menus, $store);
        $this->assertSame('Menu', $node['@type']);

        $sections = collect($node['hasMenuSection'])->keyBy('name');
        $this->assertCount(15, $sections['Mains']['hasMenuItem']);           // no per-section cap now
        $this->assertSame('MenuItem', $sections['Mains']['hasMenuItem'][0]['@type']);
        $this->assertSame('Main 1', $sections['Mains']['hasMenuItem'][0]['name']);
        $this->assertSame('Cola', $sections['Drinks']['hasMenuItem'][0]['name']);
    }

    /** B2: more than 8 categories all emit (the old 8-section cap is gone; only the 200-item total cap bounds the tree). */
    public function test_menu_from_menus_emits_all_sections(): void
    {
        $store = $this->store();
        $menus = collect();
        for ($c = 1; $c <= 10; $c++) {
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => "Cat $c", 'active' => '1', 'position' => $c]);
            $menus->push(Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => "Dish $c", 'price' => 5, 'active' => '1', 'position' => 1])->load('category'));
        }

        $node = StoreJsonLdBuilder::menuFromMenus($menus, $store);
        $this->assertCount(10, $node['hasMenuSection']);
    }

    /** B2: each MenuItem carries Offer{price,priceCurrency}, description, image and suitableForDiet. */
    public function test_b2_menu_item_carries_offer_description_image_diet(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap',
            'description' => 'Crispy chickpea', 'price' => 12.5, 'active' => '1', 'position' => 1,
            'type' => 'veg', 'is_vegan' => true, 'image' => 'wrap.png',
        ])->load('category');

        $node = StoreJsonLdBuilder::menuFromMenus(collect([$menu]), $store);
        $item = $node['hasMenuSection'][0]['hasMenuItem'][0];

        $this->assertSame('Falafel Wrap', $item['name']);
        $this->assertSame('Crispy chickpea', $item['description']);
        $this->assertSame('Offer', $item['offers']['@type']);
        $this->assertSame('12.50', $item['offers']['price']);   // currency-correct 2dp
        $this->assertSame('USD', $item['offers']['priceCurrency']);
        $this->assertContains('https://schema.org/VegetarianDiet', $item['suitableForDiet']);
        $this->assertContains('https://schema.org/VeganDiet', $item['suitableForDiet']);
        $this->assertStringContainsString('wrap.png', $item['image']);
    }

    /** B2: a default.png image and a non-veg item with no flags emit neither an image nor a suitableForDiet key. */
    public function test_b2_omits_image_and_diet_when_absent(): void
    {
        $store = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Beef Burger',
            'price' => 8, 'active' => '1', 'position' => 1, 'type' => 'nonveg', 'image' => 'default.png',
        ])->load('category');

        $item = StoreJsonLdBuilder::menuFromMenus(collect([$menu]), $store)['hasMenuSection'][0]['hasMenuItem'][0];
        $this->assertArrayNotHasKey('image', $item);
        $this->assertArrayNotHasKey('suitableForDiet', $item);
        $this->assertArrayHasKey('offers', $item);   // price still emits
    }

    /** B2: the whole tree is capped at 200 items total (silent page-weight bound). */
    public function test_b2_total_item_cap_at_200(): void
    {
        $store = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Big', 'active' => '1', 'position' => 1]);
        $menus = collect();
        for ($i = 1; $i <= 205; $i++) {
            $menus->push(Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => "Dish $i", 'price' => 5, 'active' => '1', 'position' => $i])->load('category'));
        }

        $node = StoreJsonLdBuilder::menuFromMenus($menus, $store);
        $emitted = collect($node['hasMenuSection'])->sum(fn ($s) => count($s['hasMenuItem']));
        $this->assertSame(200, $emitted);
    }

    /**
     * The storefront emits the MenuItem block ONLY when the per-store option is on,
     * and even then lists only rendered items — an inactive dish (never in the
     * rendered set) can never leak into the structured data.
     */
    public function test_storefront_hasmenu_gated_by_option_and_never_leaks_inactive(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'store_template', 'orbit');
        StoreOption::updateStoreOption($store->id, 'menu_seo_jsonld', '1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap', 'price' => 10, 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Hidden Dish', 'price' => 9, 'active' => '0', 'position' => 2]);

        $res = $this->get(route('publicView', $store->slug))->assertOk();
        $res->assertSeeHtml('"@type":"MenuItem"');
        $res->assertSeeHtml('Falafel Wrap');
        $res->assertDontSeeHtml('Hidden Dish');   // inactive → never rendered → never in JSON-LD
    }

    /** With the option OFF (default), the storefront carries no MenuItem structured data. */
    public function test_storefront_hasmenu_absent_when_option_off(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'store_template', 'orbit');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap', 'price' => 10, 'active' => '1', 'position' => 1]);

        $this->get(route('publicView', $store->slug))->assertOk()->assertDontSeeHtml('"@type":"MenuItem"');
    }

    /* ===================== google-suite B1: Restaurant node delta ===================== */

    /** B1: url AND @id resolve through effective_public_url — platform host by default, the verified custom domain when one exists. */
    public function test_b1_url_and_id_use_effective_public_url(): void
    {
        $store = $this->store();
        $data = StoreJsonLdBuilder::forStorefront($store);
        $this->assertSame(route('publicView', $store->slug), $data['url']);
        $this->assertSame($data['url'], $data['@id']);

        StoreDomain::create(['store_id' => $store->id, 'domain' => 'menu.example.test', 'verified' => true, 'verification_token' => 'tok'.uniqid()]);
        $data2 = StoreJsonLdBuilder::forStorefront($store->fresh());
        $this->assertSame('https://menu.example.test', $data2['url']);
        $this->assertSame('https://menu.example.test', $data2['@id']);
    }

    /** B1: geo prefers a real active-branch coordinate over the marketplace mk_lat/mk_lng, falling back when no branch has coords. */
    public function test_b1_geo_prefers_branch_coords_over_marketplace(): void
    {
        $store = $this->store();
        $store->update(['mk_lat' => 10.0, 'mk_lng' => 20.0]);

        // No branch with coords → falls back to mk_lat/mk_lng.
        $fallback = StoreJsonLdBuilder::forStorefront($store);
        $this->assertEqualsWithDelta(10.0, (float) $fallback['geo']['latitude'], 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $fallback['geo']['longitude'], 0.0001);

        // Active branch with coords → those win over mk_lat/mk_lng.
        Branch::factory()->create(['store_id' => $store->id, 'active' => 1, 'position' => 0, 'latitude' => 31.9, 'longitude' => 35.9]);
        $branchGeo = StoreJsonLdBuilder::forStorefront($store);
        $this->assertEqualsWithDelta(31.9, (float) $branchGeo['geo']['latitude'], 0.0001);
        $this->assertEqualsWithDelta(35.9, (float) $branchGeo['geo']['longitude'], 0.0001);
    }

    /** B1: acceptsReservations emits true only when the per-store enable_reservations option is on (distinct stores avoid store_options memo staleness). */
    public function test_b1_accepts_reservations_present_only_when_enabled(): void
    {
        $none = $this->store();
        $this->assertArrayNotHasKey('acceptsReservations', StoreJsonLdBuilder::forStorefront($none));

        $on = $this->store();
        StoreOption::updateStoreOption($on->id, 'enable_reservations', '1');
        $this->assertTrue(StoreJsonLdBuilder::forStorefront($on)['acceptsReservations']);

        $off = $this->store();
        StoreOption::updateStoreOption($off->id, 'enable_reservations', '0');
        $this->assertArrayNotHasKey('acceptsReservations', StoreJsonLdBuilder::forStorefront($off));
    }

    /** B1: inLanguage comes from the active menu languages (codes or Language models), de-duplicated; absent when none. */
    public function test_b1_in_language_from_menu_languages(): void
    {
        $store = $this->store();
        $this->assertArrayNotHasKey('inLanguage', StoreJsonLdBuilder::forStorefront($store));

        $data = StoreJsonLdBuilder::forStorefront($store, null, ['en', 'ar']);
        $this->assertSame(['en', 'ar'], $data['inLanguage']);

        $en = Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $data2 = StoreJsonLdBuilder::forStorefront($store, null, collect([$en, $en]));
        $this->assertSame(['en'], $data2['inLanguage']);   // de-duped
    }

    /** B1: aggregateRating on the storefront is gated on the per-store show_store_reviews opt-in (default OFF). */
    public function test_b1_aggregate_rating_gated_on_show_store_reviews(): void
    {
        // >=3 approved reviews but opt-in OFF (default) → no rating on the storefront.
        $off = $this->store();
        StoreReview::factory()->count(3)->create(['store_id' => $off->id, 'status' => 'approved', 'stars' => 5]);
        $this->assertArrayNotHasKey('aggregateRating', StoreJsonLdBuilder::forStorefront($off));

        // Opt-in ON → rating present.
        $on = $this->store();
        StoreReview::factory()->count(3)->create(['store_id' => $on->id, 'status' => 'approved', 'stars' => 4]);
        StoreOption::updateStoreOption($on->id, 'show_store_reviews', '1');
        $data = StoreJsonLdBuilder::forStorefront($on);
        $this->assertArrayHasKey('aggregateRating', $data);
        $this->assertSame(3, $data['aggregateRating']['reviewCount']);
    }

    /**
     * B1 SAFETY: the storefront gate must NOT change marketplace output — forStore()
     * still emits aggregateRating with the same reviews (no show_store_reviews gate),
     * keeps geo on mk_lat/mk_lng (ignores branch coords), and carries none of the new
     * storefront-only keys.
     */
    public function test_b1_marketplace_for_store_output_unchanged(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $store = $this->store();
        $store->update(['mk_lat' => 12.5, 'mk_lng' => 45.5]);
        // A branch with different coords + reviews with the opt-in OFF: neither may leak into marketplace output.
        Branch::factory()->create(['store_id' => $store->id, 'active' => 1, 'position' => 0, 'latitude' => 1.1, 'longitude' => 2.2]);
        StoreReview::factory()->count(3)->create(['store_id' => $store->id, 'status' => 'approved', 'stars' => 5]);

        $mk = StoreJsonLdBuilder::forStore($store);
        $this->assertEqualsWithDelta(12.5, (float) $mk['geo']['latitude'], 0.0001);   // mk_lat, not branch 1.1
        $this->assertEqualsWithDelta(45.5, (float) $mk['geo']['longitude'], 0.0001);
        $this->assertArrayHasKey('aggregateRating', $mk);                              // NOT gated on marketplace
        $this->assertSame(route('mk.store', $store->slug), $mk['url']);                // marketplace store route
        $this->assertArrayNotHasKey('@id', $mk);
        $this->assertArrayNotHasKey('acceptsReservations', $mk);
        $this->assertArrayNotHasKey('inLanguage', $mk);
    }

    /* ===================== google-suite B4: validator harness ===================== */

    /** Pull the ld+json block off a rendered storefront page (the block carries a CSP nonce attribute). */
    private function decodeRenderedJsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m), 'exactly one JSON-LD block expected');
        $data = json_decode($m[1], true);
        $this->assertNotNull($data, 'rendered JSON-LD must parse: '.substr((string) $m[1], 0, 120));

        return $data;
    }

    /** B4: the rendered storefront JSON-LD parses and carries the schema-required Restaurant fields (toggle OFF → no hasMenu). */
    public function test_b4_rendered_jsonld_parses_with_required_fields(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'store_template', 'orbit');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap', 'price' => 10, 'active' => '1', 'position' => 1]);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $data = $this->decodeRenderedJsonLd($html);

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('Restaurant', $data['@type']);
        $this->assertNotEmpty($data['name']);
        $this->assertSame(route('publicView', $store->slug), $data['url']);
        $this->assertSame($data['url'], $data['@id']);
        $this->assertArrayNotHasKey('hasMenu', $data);   // toggle OFF
    }

    /**
     * B4 SNAPSHOT: a fully-configured store renders the whole enriched node end to
     * end — geo from the branch, acceptsReservations, gated aggregateRating, and the
     * owner-gated hasMenu tree down to Offer{price,priceCurrency}. This is the
     * demo-store shape (store 3425's real snapshot is an F0 view-source step — the
     * lane worktree has no live DB).
     */
    public function test_b4_full_enriched_snapshot_with_toggle_on(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'store_template', 'orbit');
        StoreOption::updateStoreOption($store->id, 'menu_seo_jsonld', '1');
        StoreOption::updateStoreOption($store->id, 'enable_reservations', '1');
        StoreOption::updateStoreOption($store->id, 'show_store_reviews', '1');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        Branch::factory()->create(['store_id' => $store->id, 'active' => 1, 'position' => 0, 'latitude' => 31.9, 'longitude' => 35.9]);
        StoreReview::factory()->count(3)->create(['store_id' => $store->id, 'status' => 'approved', 'stars' => 5]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap', 'price' => 10, 'active' => '1', 'position' => 1, 'type' => 'veg']);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $data = $this->decodeRenderedJsonLd($html);

        $this->assertSame('Restaurant', $data['@type']);
        $this->assertTrue($data['acceptsReservations']);
        $this->assertEqualsWithDelta(31.9, (float) $data['geo']['latitude'], 0.0001);   // branch coord, not mk
        $this->assertArrayHasKey('aggregateRating', $data);
        $this->assertSame(3, $data['aggregateRating']['reviewCount']);

        $section = $data['hasMenu']['hasMenuSection'][0];
        $this->assertSame('MenuSection', $section['@type']);
        $item = $section['hasMenuItem'][0];
        $this->assertSame('Falafel Wrap', $item['name']);
        $this->assertSame('Offer', $item['offers']['@type']);
        $this->assertSame('10.00', $item['offers']['price']);        // currency-correct decimal string
        $this->assertSame('USD', $item['offers']['priceCurrency']);
        $this->assertContains('https://schema.org/VegetarianDiet', $item['suitableForDiet']);
    }
}
