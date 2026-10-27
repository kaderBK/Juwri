<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreTable;
use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane V — V2: fulfillment matrix test.
 *
 * Two guarantees, both dynamic across every shipped vertical:
 *  1. A store only ever offers the fulfillment modes FulfillmentRegistry
 *     declares for its vertical (config/verticals.php `fulfillment` lists) —
 *     never more. Checking out with any OTHER mode is rejected before an
 *     Order row is ever written, for every non-food vertical.
 *  2. F0-3's `orders.type` enum→string migration (MySQL-only, no-op on
 *     sqlite — see database/migrations/2026_09_10_100100_change_orders_type_to_string.php)
 *     did not break any existing consumer of the legacy literals
 *     (on-table/takeaway/delivery/room_service): the food order-creation
 *     path (OrderApiService, behind POS/KDS/the legacy storefront) still
 *     only accepts those four, and the shared dashboard "Orders" list
 *     (order-row.blade.php, reachable for ANY vertical since it never
 *     vertical-gates) renders a mix of legacy and brand-new order types
 *     without erroring.
 */
class VerticalFulfillmentMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    // ---------------------------------------------------- registry ↔ config

    public function test_fulfillment_registry_modes_for_every_vertical_match_the_config_ceiling(): void
    {
        $registry = app(FulfillmentRegistry::class);

        foreach (config('verticals.types') as $type => $cfg) {
            $this->assertSame(
                $cfg['fulfillment'],
                $registry->modesFor($type),
                "[$type] FulfillmentRegistry::modesFor() drifted from config/verticals.php"
            );
        }
    }

    // ------------------------------------------------- checkout enforcement

    public function test_a_mode_outside_the_verticals_ceiling_is_rejected_before_any_order_is_written(): void
    {
        $registry = app(FulfillmentRegistry::class);
        $verticalRegistry = app(VerticalRegistry::class);
        $allModes = array_keys(config('verticals.fulfillment'));

        foreach (config('verticals.types') as $type => $cfg) {
            if ($type === 'restaurant') {
                continue; // food goes through the legacy submitOrder path, covered separately below.
            }

            $owner = User::factory()->create();
            $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => $type]);
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
            $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Thing', 'price' => 9, 'pricing_unit' => 'each', 'active' => 1]);

            $allowed = $registry->modesFor($type);
            $forbidden = array_values(array_diff($allModes, $allowed));
            $this->assertNotEmpty($forbidden, "[$type] no forbidden mode left to test with — widen \$allModes");
            $mode = $forbidden[0];

            if ($verticalRegistry->itemForm($type) === 'service') {
                $this->postJson(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);
                $res = $this->postJson(route('laundry.book.place', ['slug' => $store->slug]), [
                    'type' => $mode, 'customer_name' => 'X', 'phone_number' => '1', 'address' => '1 St',
                ]);
            } else {
                $this->postJson(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);
                $res = $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
                    'type' => $mode, 'customer_name' => 'X', 'phone_number' => '1', 'address' => '1 St',
                ]);
            }

            $res->assertStatus(422, "[$type] mode '$mode' (outside its registry ceiling) should have been rejected");
            $this->assertSame(0, Order::where('store_id', $store->id)->count(), "[$type] no order should exist after a rejected checkout");
        }
    }

    public function test_the_legacy_food_ordering_endpoint_still_only_accepts_its_original_four_types(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'restaurant']);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'type' => 'veg', 'active' => 1]);

        // A brand-new non-food mode must never become a valid food order —
        // the migration only widened the COLUMN, never submitOrder's own
        // ordering-type allowlist — so it is rejected as an INVALID
        // ordering-type, the exact same way it was rejected before F0-3.
        $res = $this->postJson(route('store.submitOrder', $store->id), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1]]),
            'name' => 'Jane',
            'ordering-type' => 'ship',
            'pay_via' => 'cash',
        ]);
        $res->assertOk();
        $res->assertJson(['success' => false]);
        $this->assertStringContainsString('ordering-type is invalid', (string) $res->json('message'));
        $this->assertSame(0, Order::where('store_id', $store->id)->count());

        // The three literals that need no extra feature gate still work end to end.
        StoreTable::firstOrCreate(['store_id' => $store->id, 'key' => '4'], ['table_no' => 4, 'label' => '4', 'seats' => 4, 'status' => 'free', 'area_type' => 'table', 'branch_id' => null]);
        foreach (['on-table' => ['table' => '4'], 'takeaway' => [], 'delivery' => ['address' => '1 St']] as $legacyType => $extra) {
            $res = $this->postJson(route('store.submitOrder', $store->id), array_merge([
                'items' => json_encode([['id' => $menu->id, 'quantity' => 1]]),
                'name' => 'Jane',
                'phone-number' => '5551234',
                'ordering-type' => $legacyType,
                'pay_via' => 'cash',
            ], $extra));

            $res->assertOk();
            $res->assertJson(['success' => true], "legacy type '$legacyType' must still place an order");
        }

        $this->assertSame(3, Order::where('store_id', $store->id)->count());
        $this->assertEqualsCanonicalizing(
            ['on-table', 'takeaway', 'delivery'],
            Order::where('store_id', $store->id)->pluck('type')->all()
        );

        // `room_service` — gated behind hotel_mode (a separate, unrelated
        // business rule) rather than the migration: proven by getting a
        // DIFFERENT rejection reason than the brand-new-mode case above,
        // never Laravel's "the selected ordering type is invalid" — i.e.
        // the validator's `in:` rule still lists it as valid.
        $res = $this->postJson(route('store.submitOrder', $store->id), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1]]),
            'name' => 'Jane',
            'ordering-type' => 'room_service',
            'pay_via' => 'cash',
        ]);
        $res->assertOk();
        $res->assertJson(['success' => false, 'message' => ___('Room service is not available.')]);
    }

    // ------------------------------------------ shared dashboard order list

    public function test_the_shared_dashboard_orders_list_renders_a_mix_of_legacy_and_new_fulfillment_types(): void
    {
        // Renders owner.orders.index (order-row.blade.php),
        // which is reachable for ANY store — it never vertical-gates — so a
        // retail store's non-food order types can genuinely reach it. Shares
        // the real view-composer context (WI-M12 ViewComposerContractTest)
        // rather than a hand-rolled double.
        $this->withoutMiddleware();
        $this->shareLayoutGlobals(['currency_sign' => '$', 'currency_pos' => 1, 'currency_code' => 'USD'], admin: true);

        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'store_type' => 'retail', 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234']);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Widget', 'price' => 10, 'active' => 1]);

        // 'takeaway' (not 'on-table') — its label is a plain, already-
        // committed lang key with no placeholder, so this stays a clean
        // "did the type survive" check with no dependency on an unrelated
        // pre-existing lang-slug quirk elsewhere in this same view.
        $legacyOrder = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'customer_name' => 'Jane', 'phone_number' => '555', 'status' => 'pending', 'seen' => 0, 'created_at' => now()]);
        OrderItem::create(['order_id' => $legacyOrder->id, 'item_id' => $menu->id, 'quantity' => 1]);

        $shipOrder = Order::create(['store_id' => $store->id, 'type' => 'ship', 'customer_name' => 'Sam', 'phone_number' => '556', 'address' => '1 St', 'status' => 'pending', 'seen' => 0, 'created_at' => now()]);
        OrderItem::create(['order_id' => $shipOrder->id, 'item_id' => $menu->id, 'quantity' => 1]);

        // Order rows are rendered into the AJAX poll payload (see
        // OrdersPageToastTest), not the initial page shell.
        $json = $this->actingAs($owner)
            ->get(route('stores.orders', $store->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json();

        $this->assertTrue($json['success']);
        $html = implode("\n", $json['orders']);
        // Legacy label unchanged: order-row.blade.php's `takeaway` arm.
        $this->assertStringContainsString('Takeaway', $html);
        $this->assertStringContainsString('data-order-id="'.$legacyOrder->id.'"', $html);
        // A brand-new type degrades gracefully (the `default => ucfirst(...)`
        // arm) instead of an UnhandledMatchError / 500.
        $this->assertStringContainsString('data-order-id="'.$shipOrder->id.'"', $html);
        $this->assertStringContainsString('Ship', $html);
    }
}
