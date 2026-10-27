<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane F1/F3 — the storefront side of pharmacy OTC
 * compliance: the age-gate flag reaching the `shelf` theme's ALREADY-BUILT
 * interstitial (Lane C2 wired `$m->age_restricted ?? false` defensively —
 * this test proves it "lights up" now that F1 supplies the real column,
 * with zero further shelf-theme changes) and the F3 disclaimer block.
 *
 * Rendered through the SAME public controller every theme uses
 * (User\StoreController::publicView), never blade-direct — mirrors
 * ShelfThemeStorefrontTest's fixture shape.
 */
class PharmacyStorefrontComplianceTest extends TestCase
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

    private function makeStore(string $storeType, string $template = 'shelf'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $storeType,
            'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#1f6feb',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        if ($storeType === 'pharmacy') {
            PharmacyMarket::open($store);
        }

        return $store;
    }

    public function test_age_restricted_pharmacy_item_carries_the_flag_into_the_shelf_json(): void
    {
        $store = $this->makeStore('pharmacy');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cough & Cold']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Cough Syrup', 'price' => 7.5, 'active' => 1, 'age_restricted' => true]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Bandages', 'price' => 3, 'active' => 1, 'age_restricted' => false]);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringContainsString('"name":"Cough Syrup"', $html);
        // The age-gate interstitial markup Lane C already shipped.
        $this->assertStringContainsString('shAgeGate', $html);
        $this->assertStringContainsString('This item is age-restricted', $html);

        // The restricted item's own JSON blob carries ageRestricted:true; find its
        // slice of the ITEMS payload rather than asserting a single global flag,
        // since the non-restricted item must carry false right next to it.
        preg_match('/"name":"Cough Syrup".*?"ageRestricted":(true|false)/s', $html, $m1);
        $this->assertSame('true', $m1[1] ?? null, 'the age-restricted item must serialize ageRestricted:true');

        preg_match('/"name":"Bandages".*?"ageRestricted":(true|false)/s', $html, $m2);
        $this->assertSame('false', $m2[1] ?? null, 'a non-restricted item must serialize ageRestricted:false');
    }

    public function test_a_retail_store_never_carries_age_restricted_true_by_default(): void
    {
        $store = $this->makeStore('retail');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Snacks']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Chips', 'price' => 2, 'active' => 1]);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringContainsString('"ageRestricted":false', $html);
        $this->assertStringNotContainsString('"ageRestricted":true', $html);
    }

    // ── F3: disclaimer ───────────────────────────────────────────────────

    public function test_pharmacy_storefront_shows_the_otc_disclaimer(): void
    {
        $store = $this->makeStore('pharmacy');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Vitamins']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Vitamin C', 'price' => 9.99, 'active' => 1]);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringContainsString('sh-disclaimer', $html);
        $this->assertStringContainsString('over-the-counter', $html);
        $this->assertStringContainsString('not medical advice', $html);
        // D3: never any prescription/Rx wording anywhere on this page.
        $this->assertStringNotContainsStringIgnoringCase('prescription', $html);
        $this->assertStringNotContainsStringIgnoringCase('pharmacist', $html);
    }

    public function test_disclaimer_text_is_configurable_per_store(): void
    {
        $store = $this->makeStore('pharmacy');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Vitamins']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Vitamin C', 'price' => 9.99, 'active' => 1]);

        app(PharmacyComplianceService::class)->setDisclaimerText($store, 'Custom OTC notice for this store.');

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringContainsString('Custom OTC notice for this store.', $html);
    }

    public function test_retail_and_restaurant_storefronts_never_show_the_disclaimer(): void
    {
        // The `.sh-disclaimer` CSS RULE is always shipped (static stylesheet,
        // like `.sh-agegate`'s) — what must be absent is the rendered ELEMENT
        // and its wording, which only the `age_gate` capability (pharmacy) emits.
        $retail = $this->makeStore('retail');
        MenuCategory::create(['store_id' => $retail->id, 'name' => 'Snacks']);
        $htmlRetail = $this->get(route('publicView', $retail->slug))->getContent();
        $this->assertStringNotContainsString('class="sh-disclaimer"', $htmlRetail);
        $this->assertStringNotContainsString('over-the-counter', $htmlRetail);

        $store = $this->makeStore('restaurant', 'crave');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12, 'active' => 1]);
        $htmlRestaurant = $this->get(route('publicView', $store->slug))->getContent();
        $this->assertStringNotContainsString('class="sh-disclaimer"', $htmlRestaurant);
        $this->assertStringNotContainsString('id="shelf"', $htmlRestaurant);
    }
}
