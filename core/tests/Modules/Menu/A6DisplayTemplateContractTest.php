<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ImageMenu;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A6 — the platform E2E `a-display` journey drives each display-only storefront
 * (pageview; and a store still saved on the removed legacy `flipbook`, which now
 * renders on folio) through a template DOM contract. These templates render
 * a menu as a visual experience only, with no ordering capability — no cart,
 * checkout, or add-to-cart controls.
 *
 * Display-only contracts:
 *   - flipbook (removed in WI-03): the saved slug renders folio's book stage
 *     (#folioBook) from the same image_menus, but NO cart/checkout.
 *   - pageview: renders a stage container (#pv-main) with pages (.pv-page),
 *     navigation buttons (.pv-btn), item thumbnails (.pv-thumb), but NO ordering.
 *
 * Discriminating: each needle is the exact selector the a-display journey targets;
 * deleting or renaming these elements from the theme blade turns the matching
 * assertion red. This test drives the full HTTP path so the controller's template
 * gate is exercised (HANDOFF §9).
 *
 * Absence assertions ensure display-only templates never accidentally ship with
 * ordering UI (cart badge, checkout button, add-to-cart control).
 */
class A6DisplayTemplateContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** A display-only store on `$template`: no ordering enabled, items for display. */
    private function displayStore(string $template): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '0'], // NO ordering
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => $template.'-d-'.Str::lower(Str::random(6)), 'color' => '#7c5cff',
            'title' => ucfirst($template).' Display', 'sub_title' => 'Menu', 'description' => 'Display-only menu',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_order', '0'); // Display only
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '0');
        StoreOption::updateStoreOption($store->id, 'restaurant_on_table_order', '0');
        FeatureAccess::flush();

        if ($template === 'flipbook') {
            // Flipbook (now served by folio) uses image_menus (full-page images), not regular menu items
            foreach (range(1, 3) as $i) {
                ImageMenu::create([
                    'store_id' => $store->id,
                    'name' => "Page {$i}",
                    'image' => 'default.png',
                    'position' => $i,
                    'active' => '1',
                ]);
            }
        } else {
            // Pageview uses regular menu items grouped by category
            foreach (range(1, 2) as $catNum) {
                $cat = MenuCategory::create([
                    'store_id' => $store->id,
                    'name' => "Category {$catNum}",
                    'active' => '1',
                ]);
                foreach (range(1, 3) as $itemNum) {
                    Menu::create([
                        'store_id' => $store->id,
                        'category_id' => $cat->id,
                        'name' => "Item {$catNum}-{$itemNum}",
                        'price' => 10.00 + $itemNum,
                        'active' => '1',
                    ]);
                }
            }
        }

        return $store;
    }

    /**
     * The a-display DOM contract per display-only template. Each needle is
     * server-rendered by the theme; the puppeteer journey targets them.
     *
     * @return array<string, array{0:string, 1:string[], 2:string[]}>
     */
    public static function contracts(): array
    {
        return [
            'flipbook' => ['flipbook', [
                // Present: folio's book stage, fed by the same image menus
                'id="folioBook"',
                'id="folioConfig"',
            ], [
                // Absent: ordering controls
                'id="cvCartBadge"',
                'id="cvGoCheckout"',
                'id="addToCart"',
                'class="cart-badge"',
                'data-add-to-cart',
            ]],
            'pageview' => ['pageview', [
                // Present: root container, pages, navigation
                'id="pv-main"',
                'class="pv-page"',
                'class="pv-btn"',
            ], [
                // Absent: ordering controls
                'id="cvCartBadge"',
                'id="cvGoCheckout"',
                'id="addToCart"',
                'class="cart-badge"',
                'data-add-to-cart',
            ]],
        ];
    }

    /**
     * Test that each display-only storefront renders the display contract.
     */
    #[DataProvider('contracts')]
    public function test_display_template_contract(string $template, array $present, array $absent): void
    {
        $store = $this->displayStore($template);

        $response = $this->get('/'.$store->slug);
        $response->assertStatus(200);
        $html = $response->getContent();

        // Assert present elements
        foreach ($present as $needle) {
            $this->assertStringContainsString(
                $needle,
                $html,
                "Display-only template {$template} should contain {$needle}"
            );
        }

        // Assert absent ordering controls
        foreach ($absent as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "Display-only template {$template} should NOT contain {$needle} (ordering disabled)"
            );
        }
    }

    /**
     * Parametrized test entry point.
     */
    public static function contracts_for_data_provider()
    {
        return array_values(self::contracts());
    }
}
