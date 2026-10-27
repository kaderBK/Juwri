<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\CouponBugs5;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\CouponService;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Bug batch 5 · 1 — a buy-X-get-Y coupon priced the free/discounted unit from `order_items.unit_price`, which is
 * only filled for a scheduled (happy-hour) price, so on an ordinary order it gave 0. It now uses the line's SOLD
 * unit price (base / variant / scheduled price + extras + choice-group options — the value Order::computeMoney()
 * charges), in the order currency, and draws the eligible units cheapest first.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class BxgySoldUnitPriceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{owner: User, post: Store, buy: Menu, get: Menu, cat: int} */
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function boot(string $currency, float|int $buyPrice, float|int $getPrice): void
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        // The store currency first: menu prices are stored in its minor units.
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($store->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', 0);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', 0);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $buy = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => $buyPrice, 'active' => '1']);
        $get = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Soda', 'price' => $getPrice, 'active' => '1']);

        $this->ctx = ['owner' => $owner, 'store' => $store->fresh(), 'buy' => $buy->fresh(), 'get' => $get->fresh(), 'cat' => (int) $cat->id];
    }

    /** Buy 2 pizzas, get 1 soda at $pct % off. */
    private function coupon(int $pct = 50, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'BXGY'.Str::upper(Str::random(4)), 'coupon_type' => 'bogo', 'user_id' => $this->ctx['owner']->id,
            'percentage' => 0, 'limit' => 9999, 'used' => 0, 'buy_scope' => 'item',
            'buy_id' => $this->ctx['buy']->id, 'buy_qty' => 2,
            'get_id' => $this->ctx['get']->id, 'get_qty' => 1, 'get_discount_percent' => $pct,
            'issuer_scope' => 'owner', 'applies_to' => 'food_order',
        ], $overrides));
    }

    private function bareOrder(string $currency): Order
    {
        return Order::create([
            'store_id' => $this->ctx['store']->id, 'type' => 'takeaway', 'status' => 'pending', 'currency_code' => $currency,
        ]);
    }

    /** @return array<string, array{string, float|int, float|int, float, float, float}> currency, buy, get, discount, before grand, after grand */
    public static function currencies(): array
    {
        return [
            // 50% of a 7.25 soda = 3.625 → 3.63 (cents). Grand 2×10.00 + 7.25 = 27.25.
            'USD' => ['USD', 10.00, 7.25, 3.63, 27.25, 23.62],
            // 50% of a 1.125 soda = 0.5625 → 0.563 (fils). Grand 2×2.500 + 1.125 = 6.125.
            'KWD' => ['KWD', 2.500, 1.125, 0.563, 6.125, 5.562],
            // 50% of a 855 soda = 427.5 → 428 (yen). Grand 2×1200 + 855 = 3255.
            'JPY' => ['JPY', 1200, 855, 428.0, 3255.0, 2827.0],
        ];
    }

    #[DataProvider('currencies')]
    public function test_ordinary_lines_without_a_scheduled_price_get_the_discount(string $cur, float|int $buyP, float|int $getP, float $disc, float $grandBefore, float $grandAfter): void
    {
        $this->boot($cur, $buyP, $getP);
        $order = $this->bareOrder($cur);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $this->ctx['buy']->id, 'quantity' => 2, 'variation' => 0]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $this->ctx['get']->id, 'quantity' => 1, 'variation' => 0]);

        $this->assertSame($disc, (new CouponService)->bogoDiscount($this->coupon(), $order->fresh())); // before: 0.0
    }

    public function test_sold_unit_price_counts_variant_extras_and_options_in_kwd(): void
    {
        $this->boot('KWD', 2.500, 1.000);
        $variant = MenuVariant::create(['menu_id' => $this->ctx['get']->id, 'store_id' => $this->ctx['store']->id, 'price' => 1.250, 'active' => 1]);
        $order = $this->bareOrder('KWD');
        OrderItem::create(['order_id' => $order->id, 'item_id' => $this->ctx['buy']->id, 'quantity' => 2, 'variation' => 0]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'item_id' => $this->ctx['get']->id, 'quantity' => 1, 'variation' => $variant->id,
            'options' => [['title' => 'Ice', 'selected' => [['option' => 'Extra ice', 'price' => 0.125]]]],
        ]);
        OrderItemExtra::create(['order_item_id' => $line->id, 'extra_id' => null, 'price' => 0.375]);

        $order = $order->fresh();
        // Sold unit: variant 1.250 + extra 0.375 + option 0.125 = 1.750 — the per-unit value computeMoney charges.
        $this->assertEqualsWithDelta(2 * 2.500 + 1.750, $order->computeMoney()['subtotal'], 0.0001);
        // 100% off the soda = its whole sold price.
        $this->assertSame(1.75, (new CouponService)->bogoDiscount($this->coupon(100), $order)); // before: 0.0
        // 50% → 0.875 (fils, not 0.88).
        $this->assertSame(0.875, (new CouponService)->bogoDiscount($this->coupon(50), $order)); // before: 0.0
    }

    public function test_scheduled_price_still_replaces_the_base(): void
    {
        $this->boot('USD', 10, 5);
        $order = $this->bareOrder('USD');
        OrderItem::create(['order_id' => $order->id, 'item_id' => $this->ctx['buy']->id, 'quantity' => 2, 'variation' => 0]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $this->ctx['get']->id, 'quantity' => 1, 'variation' => 0, 'unit_price' => 3.00]);

        // Happy-hour 3.00 replaces the list 5.00 (unchanged by the fix).
        $this->assertSame(3.0, (new CouponService)->bogoDiscount($this->coupon(100), $order->fresh())); // before: 3.0
    }

    public function test_eligible_units_are_the_cheapest_get_units(): void
    {
        $this->boot('USD', 10, 2);
        $order = $this->bareOrder('USD');
        OrderItem::create(['order_id' => $order->id, 'item_id' => $this->ctx['buy']->id, 'quantity' => 2, 'variation' => 0]);
        // Plain soda 2.00 ×3, then one soda with a 1.50 option (3.50).
        OrderItem::create(['order_id' => $order->id, 'item_id' => $this->ctx['get']->id, 'quantity' => 3, 'variation' => 0]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $this->ctx['get']->id, 'quantity' => 1, 'variation' => 0,
            'options' => [['title' => 'Size', 'selected' => [['option' => 'XL', 'price' => 1.50]]]],
        ]);

        // Buy 2 → 1 soda free: the cheapest (2.00). Before the fix the LAST get line's unit_price was used
        // (here null → 0); with only the line price swapped in, it would have given the 3.50 soda away.
        $this->assertSame(2.0, (new CouponService)->bogoDiscount($this->coupon(100), $order->fresh())); // before: 0.0

        // Buy 2 get 3: the three plain sodas (6.00), never 3 × 3.50 = 10.50.
        $this->assertSame(6.0, (new CouponService)->bogoDiscount($this->coupon(100, ['get_qty' => 3]), $order->fresh())); // before: 0.0

        // Buy 2 get 4: all four units, 3 × 2.00 + 3.50 = 9.50 (the sold value of the units, nothing more).
        $this->assertSame(9.5, (new CouponService)->bogoDiscount($this->coupon(100, ['get_qty' => 4]), $order->fresh())); // before: 0.0
    }

    #[DataProvider('currencies')]
    public function test_api_order(string $cur, float|int $buyP, float|int $getP, float $disc, float $grandBefore, float $grandAfter): void
    {
        $this->boot($cur, $buyP, $getP);
        $c = $this->coupon();
        $order = OrderApiService::create((int) $this->ctx['owner']->id, (int) $this->ctx['store']->id, [
            'type' => 'takeaway', 'coupon_code' => $c->code,
            'items' => [['item_id' => $this->ctx['buy']->id, 'quantity' => 2], ['item_id' => $this->ctx['get']->id, 'quantity' => 1]],
        ])->fresh();

        $this->assertMoney($order, $cur, $disc, $grandAfter); // before: discount 0.0, grand $grandBefore
        $this->assertSame($c->id, (int) $order->coupon_id);
    }

    #[DataProvider('currencies')]
    public function test_pos_order(string $cur, float|int $buyP, float|int $getP, float $disc, float $grandBefore, float $grandAfter): void
    {
        $this->boot($cur, $buyP, $getP);
        $c = $this->coupon();
        $staff = StaffMember::create([
            'owner_id' => $this->ctx['owner']->id, 'store_id' => $this->ctx['store']->id, 'name' => 'S',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $order = PosService::createOrder($this->ctx['store'], $staff, [
            'type' => 'takeaway', 'coupon_code' => $c->code,
            'items' => [['item_id' => $this->ctx['buy']->id, 'quantity' => 2], ['item_id' => $this->ctx['get']->id, 'quantity' => 1]],
        ])->fresh();

        $this->assertMoney($order, $cur, $disc, $grandAfter); // before: discount 0.0, grand $grandBefore
    }

    #[DataProvider('currencies')]
    public function test_storefront_order(string $cur, float|int $buyP, float|int $getP, float $disc, float $grandBefore, float $grandAfter): void
    {
        $this->boot($cur, $buyP, $getP);
        $c = $this->coupon();
        $this->post(route('store.submitOrder', $this->ctx['store']), [
            'items' => json_encode([
                ['id' => $this->ctx['buy']->id, 'quantity' => 2, 'variants' => 0, 'extras' => [], 'mods' => []],
                ['id' => $this->ctx['get']->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []],
            ]),
            'tip_amount' => '0', 'name' => 'Web', 'ordering-type' => 'takeaway',
            'phone-number' => '555-9', 'pay_via' => 'pay_online', 'coupon_code' => $c->code,
        ])->assertOk()->assertJson(['success' => true]);
        $order = Order::where('store_id', $this->ctx['store']->id)->where('customer_name', 'Web')->latest('id')->first();
        $this->assertNotNull($order);

        $this->assertMoney($order, $cur, $disc, $grandAfter); // before: discount 0.0, grand $grandBefore
    }

    private function assertMoney(Order $order, string $cur, float $discount, float $grand): void
    {
        $this->assertSame($cur, $order->currency_code);
        $this->assertEqualsWithDelta($discount, (float) $order->discount_amount, 0.0000001, 'discount recorded on the order');
        // Shown == charged: the derived total and the frozen column agree with the expected grand.
        $this->assertEqualsWithDelta($grand, (float) $order->computeMoney()['grand'], 0.0000001, 'derived grand');
        $this->assertEqualsWithDelta($grand, (float) $order->grand_total, 0.0000001, 'stored grand_total');
    }
}
