<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-13 (THEME-CONTRACT §5.2 acceptance, §11 check 3) — totals parity for the 8 D11 premium themes.
 *
 * Every theme now shows the SERVER quote below its cart lines (assets/store_templates/_shared/js/mb-quote.js): the
 * food themes post the `send-order` cart to {store}/quote, the goods themes ask {store}/cart-quote for their server
 * cart. This test proves, per theme, that (1) the page and the theme script are wired to the quote, and (2) the quote
 * of the theme's own checkout payload is exactly what the placed Order charges — a COD delivery order with variant +
 * modifier + add-on + qty 2 + coupon + tip, in KWD (3 decimals) with a fixed tax. The food themes are checked on
 * both checkout pipelines (legacy and unified). The goods checkouts take no tip and no modifier groups: carely and
 * aurelia use variant + add-on + qty 2 + coupon (+ delivery for the hotel), freshly an each line + a per-kg line +
 * coupon + delivery.
 */
class PremiumThemeTotalsParityTest extends TestCase
{
    use RefreshDatabase;

    private const FOOD = ['aurora', 'velvet', 'ember', 'glow', 'vivid'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        Queue::fake();
        config(['settings.admin_allow_combos' => true, 'settings.storefront_order_pipeline_stores' => '']);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function foodThemes(): array
    {
        $out = [];
        foreach (self::FOOD as $theme) {
            foreach (['legacy', 'unified'] as $pipeline) {
                $out["{$theme} {$pipeline}"] = [$theme, $pipeline];
            }
        }

        return $out;
    }

    #[DataProvider('foodThemes')]
    public function test_a_food_theme_shows_the_quote_the_order_charges(string $theme, string $pipeline): void
    {
        config(['settings.storefront_order_pipeline' => $pipeline]);
        $store = $this->store('restaurant', $theme);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_order', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', '1.500');
        $burger = $this->item($store, 'Burger', 2.125);
        $size = MenuVariantOption::create(['menu_id' => $burger->id, 'title' => 'Size', 'options' => ['Small', 'Large'], 'position' => 0, 'active' => 1]);
        $large = MenuVariant::create(['menu_id' => $burger->id, 'price' => 3.375, 'options' => [$size->id => 1], 'active' => 1, 'position' => 0]);
        $sauce = MenuVariantOption::create([
            'store_id' => $store->id, 'is_global' => true, 'menu_id' => null, 'title' => 'Sauce', 'options' => ['Plain', 'Truffle'],
            'option_prices' => [0, 0.375], 'position' => 0, 'active' => 1, 'required' => false, 'min_select' => 0, 'max_select' => 1,
        ]);
        $burger->attachedVariantOptions()->attach($sauce->id, ['active' => true]);
        $cheese = MenuExtra::create(['menu_id' => $burger->id, 'title' => 'Cheese', 'price' => 0.250, 'position' => 0, 'active' => 1]);
        $this->coupon($store);

        $this->assertThemeIsWired($store, $theme, 'mbQuote.urlFrom(');

        // The cart exactly as the theme's checkout posts it (orderLines()/orderPayload() of every food theme).
        $items = [[
            'id' => $burger->id, 'quantity' => 2, 'variants' => $large->id,
            'extras' => [['id' => $cheese->id]], 'mods' => [['groupId' => $sauce->id, 'optIndex' => 1]], 'order_note' => 'no onions',
        ]];
        $fields = ['ordering-type' => 'delivery', 'coupon_code' => 'SAVE10', 'tip_amount' => '0.500'];

        $quote = $this->postJson(route('store.quote', $store->slug), ['items' => $items] + $fields)->assertOk()->json('quote');

        $this->post(route('store.submitOrder', $store->id), [
            'items' => json_encode($items), 'name' => 'Guest', 'phone-number' => '96512345678', 'address' => '1 Gulf Road',
            'pay_via' => 'cash',
        ] + $fields)->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->latest('id')->firstOrFail()->fresh();

        // (3.375 + 0.375 + 0.250) × 2 = 8.000; −10 % = 0.800; + delivery 1.500 + fixed tax 0.250 + tip 0.500 = 9.450 KWD.
        $this->assertQuote($quote, ['subtotal' => 8000, 'delivery' => 1500, 'tax' => 250, 'tip' => 500, 'discount' => -800], 9450);
        $this->assertSame(9450, (int) $order->grand_total_minor, "{$theme} ({$pipeline}): the order charges what the theme showed");
        $this->assertSame((int) $order->grand_total_minor, $quote['total']['minor']);
    }

    public function test_a_food_quote_keeps_the_checkouts_rules_for_unsellable_and_foreign_lines(): void
    {
        config(['settings.storefront_order_pipeline' => 'legacy']);
        $store = $this->store('restaurant', 'ember');
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        $tea = $this->item($store, 'Tea', 1.000);
        $gone = $this->item($store, 'Gone', 5.000);
        $gone->forceFill(['is_sold_out' => true])->save();
        $other = $this->store('restaurant', 'ember');
        $foreign = $this->item($other, 'Foreign', 9.000);

        $items = [
            ['id' => $tea->id, 'quantity' => 3, 'variants' => 0, 'extras' => [], 'mods' => []],
            ['id' => $gone->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
        ];
        $quote = $this->postJson(route('store.quote', $store->slug), ['items' => json_encode($items), 'ordering-type' => 'takeaway'])
            ->assertOk()->json('quote');
        // The checkout skips the sold-out line and places the rest; so does the quote.
        $this->assertSame(3250, $quote['total']['minor'], 'tea 3 × 1.000 + fixed tax 0.250');
        $this->post(route('store.submitOrder', $store->id), [
            'items' => json_encode($items), 'ordering-type' => 'takeaway', 'name' => 'G', 'phone-number' => '96512345678', 'pay_via' => 'cash',
        ])->assertJson(['success' => true]);
        $this->assertSame(3250, (int) Order::where('store_id', $store->id)->firstOrFail()->fresh()->grand_total_minor);

        // The checkout refuses a cart naming another store's item or a bad quantity; the quote gives no price for it.
        $withForeign = [$items[0], ['id' => $foreign->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]];
        $this->postJson(route('store.quote', $store->slug), ['items' => json_encode($withForeign)])->assertStatus(422)->assertJsonStructure(['message', 'code']);
        $this->post(route('store.submitOrder', $store->id), [
            'items' => json_encode($withForeign), 'ordering-type' => 'takeaway', 'name' => 'G', 'phone-number' => '96512345678', 'pay_via' => 'cash',
        ])->assertJson(['success' => false]);
        $this->postJson(route('store.quote', $store->slug), ['items' => json_encode([['id' => $tea->id, 'quantity' => -4]])])->assertStatus(422);
        $this->postJson(route('store.quote', $store->slug), ['items' => json_encode([$items[1]])])->assertStatus(422);
        $this->postJson(route('store.quote', $store->slug), ['items' => 'not json'])->assertStatus(422);
        $this->postJson(route('store.quote', $store->slug), ['items' => [], 'ordering-type' => 'teleport'])->assertStatus(422);
    }

    public function test_a_food_quote_prices_combos_like_the_checkout(): void
    {
        config(['settings.storefront_order_pipeline' => 'legacy']);
        $store = $this->store('restaurant', 'velvet');
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        $tea = $this->item($store, 'Tea', 1.000);
        $water = $this->item($store, 'Water', 0.500);
        $cola = $this->item($store, 'Cola', 0.750);
        $combo = Combo::create(['store_id' => $store->id, 'name' => 'Meal', 'description' => 'T', 'price' => 3.000, 'active' => true, 'position' => 0]);
        $slot = ComboSlot::create(['combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Drink', 'min_select' => 1, 'max_select' => 1, 'position' => 0]);
        ComboSlotOption::create(['combo_slot_id' => $slot->id, 'combo_id' => $combo->id, 'store_id' => $store->id, 'menu_id' => $water->id, 'upcharge' => 0, 'active' => true, 'position' => 0]);
        $upsize = ComboSlotOption::create(['combo_slot_id' => $slot->id, 'combo_id' => $combo->id, 'store_id' => $store->id, 'menu_id' => $cola->id, 'upcharge' => 0.125, 'active' => true, 'position' => 1]);
        $items = json_encode([['id' => $tea->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]);
        $combos = json_encode([['combo_id' => $combo->id, 'quantity' => 2, 'picks' => [['slot_id' => $slot->id, 'option_ids' => [$upsize->id]]]]]);

        $quote = $this->postJson(route('store.quote', $store->slug), ['items' => $items, 'combos' => $combos, 'ordering-type' => 'takeaway'])->assertOk()->json('quote');
        $this->post(route('store.submitOrder', $store->id), [
            'items' => $items, 'combos' => $combos, 'ordering-type' => 'takeaway', 'name' => 'G', 'phone-number' => '96512345678', 'pay_via' => 'cash',
        ])->assertJson(['success' => true]);

        // tea 1.000 + 2 × (3.000 + 0.125) = 7.250 + fixed tax 0.250 = 7.500.
        $this->assertQuote($quote, ['subtotal' => 7250, 'tax' => 250], 7500);
        $this->assertSame(7500, (int) Order::where('store_id', $store->id)->firstOrFail()->fresh()->grand_total_minor);
    }

    public function test_carely_shows_the_quote_of_the_goods_cart_the_checkout_places(): void
    {
        config(['pharmacy.block_medicine_promotions' => false]);
        $store = $this->store('pharmacy', 'carely');
        PharmacyMarket::open($store, 'KW');
        app(FulfillmentSettingsService::class)->update($store, ['ship']);
        [$item, $variant, $extra] = $this->goodsItem($store);
        $this->coupon($store);
        $this->assertThemeIsWired($store, 'carely', "flow: 'goods'");

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $item->id]), ['quantity' => 2, 'variation' => $variant->id, 'extras' => [$extra->id]])->assertSessionHasNoErrors();
        $quote = $this->postJson(route('store.cartQuote', $store->slug), ['type' => 'ship', 'coupon_code' => 'SAVE10', 'flow' => 'goods'])->assertOk()->json('quote');

        $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship', 'address' => '1 Gulf Road', 'customer_name' => 'Ada', 'phone_number' => '96512345678', 'coupon_code' => 'SAVE10',
        ])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail()->fresh();

        // (3.375 + 0.250) × 2 = 7.250; −10 % = 0.725; + fixed tax 0.250 = 6.775 KWD.
        $this->assertQuote($quote, ['subtotal' => 7250, 'tax' => 250, 'discount' => -725], 6775);
        $this->assertSame((int) $order->grand_total_minor, $quote['total']['minor']);
    }

    public function test_aurelia_shows_the_quote_of_a_delivered_goods_order(): void
    {
        $store = $this->store('hotel', 'aurelia');
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', '1.500');
        app(FulfillmentSettingsService::class)->update($store, ['delivery']);
        [$item, $variant, $extra] = $this->goodsItem($store);
        $this->coupon($store);
        $this->assertThemeIsWired($store, 'aurelia', "flow: 'goods'");

        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $item->id]), ['quantity' => 2, 'variation' => $variant->id, 'extras' => [$extra->id]])->assertSessionHasNoErrors();
        $quote = $this->postJson(route('store.cartQuote', $store->slug), ['type' => 'delivery', 'coupon_code' => 'SAVE10'])->assertOk()->json('quote');

        $this->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'delivery', 'address' => 'Room 12', 'customer_name' => 'Ada', 'phone_number' => '96512345678', 'coupon_code' => 'SAVE10',
        ])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail()->fresh();

        // 7.250 − 0.725 + delivery 1.500 + fixed tax 0.250 = 8.275 KWD.
        $this->assertQuote($quote, ['subtotal' => 7250, 'delivery' => 1500, 'tax' => 250, 'discount' => -725], 8275);
        $this->assertSame((int) $order->grand_total_minor, $quote['total']['minor']);
    }

    public function test_freshly_shows_the_quote_of_the_booking_with_a_weighed_line(): void
    {
        $store = $this->store('laundry', 'freshly');
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', '1.500');
        app(FulfillmentSettingsService::class)->update($store, ['delivery']);
        $shirt = Menu::create(['store_id' => $store->id, 'name' => 'Shirt', 'price' => 0.375, 'pricing_unit' => 'each', 'active' => 1]);
        $wash = Menu::create(['store_id' => $store->id, 'name' => 'Wash & fold', 'price' => 1.250, 'pricing_unit' => 'kg', 'active' => 1]);
        $this->coupon($store);
        $this->assertThemeIsWired($store, 'freshly', "flow: 'booking'");

        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $shirt->id]), ['quantity' => 2])->assertSessionHasNoErrors();
        $this->post(route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $wash->id]), ['quantity' => 2.4])->assertSessionHasNoErrors();
        $quote = $this->postJson(route('store.cartQuote', $store->slug), ['type' => 'delivery', 'coupon_code' => 'SAVE10', 'flow' => 'booking'])->assertOk()->json('quote');

        $this->postJson(route('laundry.book.place', ['slug' => $store->slug]), [
            'type' => 'delivery', 'address' => '1 Gulf Road', 'customer_name' => 'Sam', 'phone_number' => '96512345678', 'coupon_code' => 'SAVE10',
        ])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->firstOrFail()->fresh();

        // shirts 2 × 0.375 = 0.750 + 2.4 kg × 1.250 = 3.000 → 3.750; −10 % (of the whole booking) = 0.375;
        // + delivery 1.500 + fixed tax 0.250 = 5.125 KWD.
        $this->assertQuote($quote, ['subtotal' => 3750, 'delivery' => 1500, 'tax' => 250, 'discount' => -375], 5125);
        $this->assertSame((int) $order->grand_total_minor, $quote['total']['minor']);
        $this->assertEqualsWithDelta(0.375, (float) $order->discount_amount, 0.0001, 'the coupon counts the per-item line too (was: unit_price NULL → 0)');
    }

    public function test_the_goods_quote_never_creates_a_cart_and_never_prices_another_stores_cart(): void
    {
        $store = $this->store('retail', 'carely');
        app(FulfillmentSettingsService::class)->update($store, ['ship']);
        $this->postJson(route('store.cartQuote', $store->slug), ['type' => 'ship'])->assertStatus(422);
        $this->assertSame(0, Cart::query()->where('store_id', $store->id)->count());

        [$item] = $this->goodsItem($store);
        $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $item->id]), ['quantity' => 1]);
        $other = $this->store('retail', 'carely');
        $this->postJson(route('store.cartQuote', $other->slug), ['type' => 'ship'])->assertStatus(422);
        $this->postJson(route('store.cartQuote', 'no-such-store-xyz'), [])->assertNotFound();
    }

    /* ───────────────────────────── helpers ──────────────────────────── */

    /** @param  array<string, int>  $rows */
    private function assertQuote(array $quote, array $rows, int $total): void
    {
        $byKey = [];
        $sum = 0;
        foreach ($quote['rows'] as $row) {
            $byKey[$row['key']] = $row['amount']['minor'];
            $this->assertSame('KWD', $row['amount']['currency']);
            if (empty($row['informational'])) {
                $sum += $row['amount']['minor'];
            }
        }
        $this->assertSame($rows, array_intersect_key($byKey, $rows));
        $this->assertSame([], array_diff_key($byKey, $rows), 'no other charged rows');
        $this->assertSame($total, $quote['total']['minor']);
        $this->assertSame($total, $sum, 'the rows add up to the total');
        $this->assertMatchesRegularExpression('/\d\.\d{3}/', $quote['total']['formatted'], 'KWD shows 3 decimals');
    }

    /** The store page loads the quote helper and the theme's totals call it. */
    private function assertThemeIsWired(Store $store, string $theme, string $marker): void
    {
        $html = (string) $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertStringContainsString('assets/store_templates/_shared/js/mb-quote.js', $html, "{$theme}: the page loads mb-quote.js");

        $script = in_array($theme, self::FOOD, true)
            ? (string) file_get_contents(public_path('assets/store_templates/'.$theme.'/js/script.js'))
            : $html;
        $this->assertStringContainsString('window.mbQuote.update(', $script, "{$theme}: the totals are drawn from the server quote");
        $this->assertStringContainsString($marker, $script);
    }

    private function store(string $type, string $theme): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'country_code' => 'KW']);
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        $title = 'S'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        StoreOption::updateStoreOption($store->id, 'allow_order', 1);
        StoreSettings::setMany((int) $store->id, [
            'currency_code' => 'KWD', 'currency_sign' => 'KWD', 'restaurant_tax_type' => 'fixed', 'restaurant_taxx_charge' => '0.250',
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        return $store;
    }

    private function item(Store $store, string $name, float $price): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => $price, 'active' => 1]);
    }

    /** @return array{0: Menu, 1: MenuVariant, 2: MenuExtra} an item 2.125 with a 3.375 variant and a 0.250 add-on */
    private function goodsItem(Store $store): array
    {
        $item = $this->item($store, 'Cream', 2.125);
        $size = MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'Size', 'options' => ['50 ml', '100 ml'], 'position' => 0, 'active' => 1]);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 3.375, 'options' => [$size->id => 1], 'active' => 1, 'position' => 0]);
        $extra = MenuExtra::create(['menu_id' => $item->id, 'title' => 'Gift wrap', 'price' => 0.250, 'position' => 0, 'active' => 1]);

        return [$item, $variant, $extra];
    }

    private function coupon(Store $store): void
    {
        Coupon::create([
            'user_id' => $store->user_id, 'code' => 'SAVE10', 'coupon_type' => 'percentage', 'percentage' => 10,
            'limit' => 0, 'used' => 0, 'applies_to' => 'food_order', 'status' => 1,
        ]);
    }
}
