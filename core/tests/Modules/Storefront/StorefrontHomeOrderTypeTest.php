<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The home-page order-type bar: which chips a store gets, and when none.
 *
 * The interesting half of this feature is a browser fact — the bar clicks the
 * theme's own hidden control rather than forcing `ordering-type` into the POST,
 * and whether fourteen compiled bundles honour that click is checked in
 * tests/e2e/journeys/storefront-home-order-type.js.
 *
 * What the server decides, and what this pins, is the OFFER: a chip may only
 * exist for a service the store actually sells, two chips are the minimum for
 * the thing to be a choice at all, and the ~40 legacy templates are left alone.
 */
class StorefrontHomeOrderTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        /* ViewServiceProvider only shares $settings when APP_INSTALLED is set and
           phpunit.xml leaves it unset, so a storefront render dies on an
           undefined variable. Same stub the other storefront suites use. */
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
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @param  array<string,string>  $services  store_options for the order types on sale */
    private function store(array $services, string $theme = 'crave'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            // An ARRAY, not json_encode: the column is cast, and pre-encoding it
            // double-encodes so Entitlements::has('allow_ordering') reads false
            // and StoreController never computes $allow_delivery at all.
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        foreach ($services as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1',
        ]);

        return $store;
    }

    private const ALL_SERVICES = [
        'restaurant_delivery_order' => '1',
        'restaurant_takeaway_order' => '1',
        'restaurant_on_table_order' => '1',
    ];

    public function test_a_chip_renders_for_every_service_the_store_sells(): void
    {
        $store = $this->store(self::ALL_SERVICES);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbOrderType"', $html);
        foreach (['delivery', 'takeaway', 'on-table'] as $type) {
            $this->assertStringContainsString('data-mbt="'.$type.'"', $html, "a $type chip");
        }
    }

    /** A chip for a service the store does not sell would be a dead control. */
    public function test_a_service_the_store_does_not_sell_gets_no_chip(): void
    {
        $store = $this->store([
            'restaurant_delivery_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_on_table_order' => '0',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-mbt="delivery"', $html);
        $this->assertStringContainsString('data-mbt="takeaway"', $html);
        $this->assertStringNotContainsString('data-mbt="on-table"', $html);
    }

    /** One option is not a choice — the whole bar stays off. */
    public function test_a_store_selling_one_service_gets_no_bar(): void
    {
        $store = $this->store([
            'restaurant_delivery_order' => '1',
            'restaurant_takeaway_order' => '0',
            'restaurant_on_table_order' => '0',
        ]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('mbOrderType', $html);
        $this->assertStringNotContainsString('data-mbt=', $html);
    }

    /**
     * room_service is never offered here. It exists only when the guest scanned
     * a room QR, the themes already default to it in that case, and a way OUT
     * of it on the home page would let a hotel guest silently drop their order
     * off the folio.
     */
    public function test_room_service_is_never_offered_by_the_bar(): void
    {
        $store = $this->store(self::ALL_SERVICES + ['hotel_mode' => '1']);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-mbt="room_service"', $html);
    }

    /** Every retained template gets the bar; the three with a native home
        control drop it client-side, which the e2e journey covers. */
    public function test_the_bar_renders_on_every_retained_template(): void
    {
        foreach (KeptTemplates::MODERN as $theme) {
            $store = $this->store(self::ALL_SERVICES, $theme);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();
            $this->assertStringContainsString('id="mbOrderType"', $html, "$theme should render the order-type bar");
        }
    }

    /** WI-03: a store saved on a removed legacy template renders the fallback, bar included. */
    public function test_store_on_a_removed_template_gets_the_bar_via_the_fallback(): void
    {
        $store = $this->store(self::ALL_SERVICES, 'old-2-theme');

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbOrderType"', $html);
    }
}
