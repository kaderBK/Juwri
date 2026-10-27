<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane C — C3: the `bookla` service-booking storefront theme.
 *
 * Renders a real `laundry` store through the SAME public controller every
 * other theme uses (User\StoreController::publicView) and asserts on the
 * rendered DOM/text — never blade-direct.
 */
class BooklaThemeStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
    }

    private function makeStore(string $storeType, string $template): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Service Co '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id,
            'store_type' => $storeType,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#0f9d78',
            'title' => $title,
            'sub_title' => 'T',
            'description' => 'T',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '1234',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return $store;
    }

    public function test_renders_service_list_with_services_vocabulary(): void
    {
        $store = $this->makeStore('laundry', 'bookla');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Wash']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Wash & Fold', 'price' => 5, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('id="bookla"');
        $response->assertSee('Wash & Fold');
        // C5 vocab guard.
        $response->assertSeeHtml('Book');
        $response->assertDontSeeHtml('>Dish<');
        $response->assertDontSeeHtml('Add to order');
        $response->assertDontSeeHtml('Order food');
    }

    public function test_per_kg_pricing_unit_is_rendered_defensively(): void
    {
        $store = $this->makeStore('laundry', 'bookla');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Wash']);
        // pricing_unit (Lane D's D1) does not exist on `menu` yet — the theme
        // defaults every item to "per item" until D1 lands; this proves the
        // fallback renders (not a 500) rather than asserting the "kg" copy.
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Wash & Fold', 'price' => 3, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSee('per item');
    }

    public function test_pickup_address_field_and_slot_picker_are_present(): void
    {
        $store = $this->makeStore('laundry', 'bookla');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Wash']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Wash & Fold', 'price' => 5, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('id="bkAddress"');
        $response->assertSee('Pickup address');
        $response->assertSeeHtml('id="bkSlots"');
        $response->assertSeeHtml('id="bkPlaceOrder"');
    }

    public function test_renders_rtl_for_arabic_locale(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');

        $store = $this->makeStore('laundry', 'bookla');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Wash']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Wash & Fold', 'price' => 5, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('dir="rtl"');
    }

    public function test_dark_mode_toggle_is_wired(): void
    {
        $store = $this->makeStore('laundry', 'bookla');

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml('bkDarkToggle');
        $response->assertSeeHtml('prefers-color-scheme: dark');
    }
}
