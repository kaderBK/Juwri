<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Unit;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Services\Orders\OrderTextRenderer;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-H15 — OrderTextRenderer rebuilds the storefront's confirmation/WhatsApp text
 * from the PERSISTED order. This pins the exact ▪️/▫️/\t/` X ` glyph sequence the
 * legacy submitOrder loop emits, so saved `whatsapp_order_template` values keep
 * rendering identically after the fold.
 */
class OrderTextRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_combos' => true]);
        FeatureAccess::flush();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        FeatureAccess::flush();

        return $store;
    }

    private function item(Store $store, string $name, float $price): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => $name, 'price' => $price, 'active' => 1,
        ]);
    }

    public function test_render_matches_the_legacy_line_text(): void
    {
        $store = $this->store();

        $burger = $this->item($store, 'Burger', 20.00);
        $sizeOption = MenuVariantOption::create([
            'menu_id' => $burger->id, 'title' => 'Size', 'options' => ['Small', 'Large'],
            'position' => 0, 'active' => 1,
        ]);
        $variant = MenuVariant::create([
            'menu_id' => $burger->id, 'price' => 25.00, 'options' => [$sizeOption->id => 1],
            'active' => 1, 'position' => 0,
        ]);
        $cheese = MenuExtra::create([
            'menu_id' => $burger->id, 'title' => 'Cheese', 'price' => 2.00, 'position' => 0, 'active' => 1,
        ]);
        $sauce = MenuVariantOption::create([
            'store_id' => $store->id, 'is_global' => true, 'menu_id' => null,
            'title' => 'Sauce', 'options' => ['Plain', 'Truffle'], 'option_prices' => [0, 2.50],
            'position' => 0, 'active' => 1, 'required' => false, 'min_select' => 0, 'max_select' => 1,
        ]);
        $burger->attachedVariantOptions()->attach($sauce->id, ['active' => true]);

        // Combo: Family Meal 15.00, one slot, Cola +0.75.
        $water = $this->item($store, 'Water', 2.00);
        $cola = $this->item($store, 'Cola', 3.00);
        $combo = Combo::create([
            'store_id' => $store->id, 'name' => 'Family Meal', 'description' => 'F',
            'price' => 15.00, 'active' => true, 'position' => 0,
        ]);
        $slot = ComboSlot::create([
            'combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Drink',
            'min_select' => 1, 'max_select' => 1, 'position' => 0,
        ]);
        $colaOption = ComboSlotOption::create([
            'combo_slot_id' => $slot->id, 'combo_id' => $combo->id, 'store_id' => $store->id,
            'menu_id' => $cola->id, 'upcharge' => 0.75, 'active' => true, 'position' => 0,
        ]);

        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'takeaway',
            'source' => 'qr',
            'items' => [[
                'item_id' => $burger->id, 'quantity' => 2, 'variation' => $variant->id,
                'extras' => [$cheese->id],
                'options' => [['group_id' => $sauce->id, 'selected' => ['Truffle']]],
            ]],
            'combos' => [[
                'combo_id' => $combo->id, 'quantity' => 1,
                'picks' => [['slot_id' => $slot->id, 'option_ids' => [$colaOption->id]]],
            ]],
        ]);

        $text = OrderTextRenderer::render(
            $order->fresh()->load(['items.menu', 'items.variant', 'items.itemExtras.extra'])
        );

        $wa = $text['whatsapp'];

        // The exact glyph/layout sequence the legacy loop emits.
        $this->assertStringContainsString("▪️Burger (Large) X 2\n", $wa);
        $this->assertStringContainsString("\t▫️Cheese\n", $wa);
        $this->assertStringContainsString("\t▫️Sauce: Truffle (+2.50)\n", $wa);
        $this->assertStringContainsString("▪️Family Meal X 1\n", $wa);
        $this->assertStringContainsString("\t▫️Cola\n", $wa);

        // HTML variant carries the same content with <br> line breaks.
        $msg = $text['msg'];
        $this->assertStringContainsString('Burger (Large) &times; 2<br>', $msg);
        $this->assertStringContainsString('Cheese<br>', $msg);
        $this->assertStringContainsString('Sauce: Truffle (+2.50)<br>', $msg);
        $this->assertStringContainsString('Family Meal<br>', $msg);
    }
}
