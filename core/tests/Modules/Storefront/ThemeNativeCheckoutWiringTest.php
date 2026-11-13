<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane V — INTEGRATION FIX 1: wire `shelf`/`bookla` to the
 * native checkout endpoints Lane B/D built (GoodsCartService/
 * GoodsCheckoutController for goods, LaundryBookingController/
 * ServiceBookingService for laundry) instead of the legacy
 * `restaurant.submitOrder` (see PROGRESS.md's "⚠️ INTEGRATION GAP" note).
 *
 * Two things are proven here:
 *  1. The theme's OWN rendered HTML now points at the native routes (and
 *     never at `restaurant.submitOrder`) — a real user's browser fetches the
 *     right endpoint, not a stale legacy one.
 *  2. Those native endpoints — called exactly the way the theme's JS calls
 *     them (fetch with `Accept: application/json`, per-item add-to-cart then
 *     a separate checkout/place call, no client-serialized `items` blob) —
 *     produce a real Order row with the right `type`, correct totals
 *     (including per-kg for laundry), and the chosen variant captured.
 */
class ThemeNativeCheckoutWiringTest extends TestCase
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
        $title = 'Store '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id,
            'store_type' => $storeType,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#1f6feb',
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

    /**
     * Both themes hand every route to their JS via `@json(...)` (the whole
     * $jsItems map for a per-item `addRoute`, a scalar for the checkout/book
     * route), which — like any `json_encode()` — escapes "/" as "\/". Compare
     * against that same encoding rather than the raw URL.
     */
    private function jsonEncodedUrl(string $url): string
    {
        return trim(json_encode($url), '"');
    }

    // ---------------------------------------------------------------- shelf

    public function test_shelf_theme_html_points_at_native_goods_routes_not_legacy_sendorder(): void
    {
        $store = $this->makeStore('retail', 'shelf');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Skincare']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Face Cream', 'price' => 24.50, 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml($this->jsonEncodedUrl(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id])));
        $response->assertSeeHtml($this->jsonEncodedUrl(route('storefront.checkout.place', ['slug' => $store->slug])));
        $response->assertDontSeeHtml($this->jsonEncodedUrl(route('store.submitOrder', $store->id)));
    }

    public function test_shelf_add_to_cart_then_checkout_places_a_ship_order_with_variant_and_correct_total(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'T-Shirt', 'price' => 20, 'active' => 1]);
        $variantL = MenuVariant::create(['menu_id' => $menu->id, 'price' => 27, 'active' => 1]);

        // Exactly what shelf's postAddToCart() sends: fetch(item.addRoute, {..., headers:{Accept: application/json}}).
        $addRes = $this->postJson(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), [
            'variation' => $variantL->id,
            'quantity' => 2,
        ]);
        $addRes->assertOk();
        $addRes->assertJson(['success' => true]);
        $this->assertNotNull($addRes->json('line_id'));

        // Exactly what shelf's shPlaceOrder handler sends: no `items` blob —
        // the store-side cart already has the line from the call above.
        $placeRes = $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship',
            'customer_name' => 'Jane',
            'phone_number' => '5551234',
            'address' => '221B Baker Street',
        ]);

        $placeRes->assertOk();
        $placeRes->assertJson(['success' => true]);
        $this->assertNotNull($placeRes->json('redirect'));

        $order = Order::where('store_id', $store->id)->first();
        $this->assertNotNull($order, 'no order was created');
        $this->assertSame('ship', $order->type);
        $this->assertSame('221B Baker Street', $order->address);
        $item = $order->items()->first();
        $this->assertEquals($variantL->id, (int) $item->variation);
        $this->assertSame(2, (int) $item->quantity);
        // 2 × 27.00 (variant price, not the base 20.00) = 54.00.
        $this->assertEqualsWithDelta(54.0, (float) $order->fresh(['items'])->total, 0.001);
    }

    public function test_shelf_checkout_validation_failure_returns_json_not_a_redirect(): void
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Mug', 'price' => 15, 'active' => 1]);

        $this->postJson(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => 1]);

        // 'ship' needs an address; omit it — the theme's fetch() must be able
        // to read a JSON error body (a raw redirect response is useless to a
        // fetch() caller expecting JSON).
        $res = $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship',
            'customer_name' => 'Jane',
        ]);

        $res->assertStatus(422);
        $res->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    public function test_restaurant_theme_is_unaffected_and_still_targets_legacy_sendorder(): void
    {
        $store = $this->makeStore('restaurant', 'crave');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12, 'type' => 'veg', 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml($this->jsonEncodedUrl(route('store.submitOrder', $store->id)));
    }

    // --------------------------------------------------------------- bookla

    public function test_bookla_theme_html_points_at_native_laundry_routes_not_legacy_sendorder(): void
    {
        $store = $this->makeStore('laundry', 'bookla');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Wash']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Wash & Fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);

        $response = $this->get(route('publicView', $store->slug));

        $response->assertOk();
        $response->assertSeeHtml($this->jsonEncodedUrl(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $menu->id])));
        $response->assertSeeHtml($this->jsonEncodedUrl(route('laundry.book.place', ['slug' => $store->slug])));
        $response->assertDontSeeHtml($this->jsonEncodedUrl(route('store.submitOrder', $store->id)));
    }

    private function laundryStore(): Store
    {
        $owner = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'laundry']);

        foreach ([
            'allow_order_scheduling' => '1',
            'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30,
            'scheduling_prep_minutes' => 20,
            'timezone' => 'UTC',
        ] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    public function test_bookla_add_line_then_book_places_a_pickup_slot_order_with_correct_per_kg_total(): void
    {
        $store = $this->laundryStore();
        $wash = Menu::create(['store_id' => $store->id, 'name' => 'Wash & Fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);
        app(FulfillmentSettingsService::class)->update($store, ['pickup_slot']);

        // Exactly what bookla's postAddLine() sends — a FRACTIONAL kg amount
        // (2.5), never rounded to an integer the way the old submitOrder path did.
        $addRes = $this->postJson(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), [
            'quantity' => 2.5,
        ]);
        $addRes->assertOk();
        $addRes->assertJson(['success' => true]);
        $this->assertNotNull($addRes->json('line_id'));

        $slot = Carbon::now('UTC')->addHours(2)->startOfHour();

        $placeRes = $this->postJson(route('laundry.book.place', ['slug' => $store->slug]), [
            'type' => 'pickup_slot',
            'customer_name' => 'Sam',
            'phone_number' => '5550000',
            'scheduled_at' => $slot->format('Y-m-d H:i'),
        ]);

        $placeRes->assertOk();
        $placeRes->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->first();
        $this->assertNotNull($order, 'no order was created');
        $this->assertSame('pickup_slot', $order->type);
        $item = $order->items()->first();
        $this->assertEqualsWithDelta(2.5, (float) $item->weight_kg, 0.001);
        // 2.5kg × 5.00/kg = 12.50, not 5.00 (a naive qty=1 read) nor 15.00
        // (rounding 2.5 up to 3 the way the legacy submitOrder path did).
        $this->assertEqualsWithDelta(12.5, (float) $order->fresh(['items'])->total, 0.001);
    }

    public function test_bookla_add_line_validation_failure_returns_json_not_a_redirect(): void
    {
        $store = $this->laundryStore();
        $wash = Menu::create(['store_id' => $store->id, 'name' => 'Wash & Fold', 'price' => 5, 'pricing_unit' => 'kg', 'active' => 1]);

        $res = $this->postJson(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), [
            'variation' => 999999, // not a variant of this menu
            'quantity' => 1,
        ]);

        $res->assertStatus(422);
        $res->assertJson(['success' => false]);
    }
}
