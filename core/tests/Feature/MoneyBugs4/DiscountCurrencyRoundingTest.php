<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\MoneyBugs4;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\Agents\AgentToolsService;
use App\Services\CouponService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Bug batch 4 · 2 — a coupon / order discount rounds to the ORDER currency's decimals (the rule the order totals
 * use: KWD fils, USD cents, JPY whole yen), not to 2 decimals for every currency, so the discount every surface
 * shows (storefront pre-flight, POS check, agent quote, marketplace bag) is the one the order records.
 */
class DiscountCurrencyRoundingTest extends TestCase
{
    use BeforeAfter;
    use RefreshDatabase;

    private const D = 0.000001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: Store, 1: Menu, 2: User} */
    private function store(string $currency, float $price): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', $currency);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);

        return [$r, $menu, $owner];
    }

    private function coupon(User $owner, float $percent, string $type = 'percentage'): Coupon
    {
        return Coupon::create([
            'code' => 'C'.Str::upper(Str::random(6)), 'coupon_type' => $type, 'percentage' => $percent,
            'limit' => 100, 'used' => 0, 'user_id' => $owner->id,
        ]);
    }

    private function order(Store $r, Menu $menu, int $qty, Coupon $coupon): Order
    {
        return OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway', 'coupon_code' => $coupon->code,
            'items' => [['item_id' => $menu->id, 'quantity' => $qty]],
        ])->fresh();
    }

    public function test_kwd_coupon_discount_keeps_fils_on_the_order(): void
    {
        [$r, $menu, $owner] = $this->store('KWD', 1.125);
        $coupon = $this->coupon($owner, 10);

        $order = $this->order($r, $menu, 3, $coupon);                                       // 3.375 − 10%
        $this->assertEqualsWithDelta($this->ba(0.34, 0.338), (float) $order->discount_amount, self::D);
        $this->assertEqualsWithDelta($this->ba(3.035, 3.037), (float) $order->total, self::D);
        $this->assertEqualsWithDelta($this->ba(3.035, 3.037), (float) $order->grand_total, self::D);
    }

    public function test_usd_and_jpy_order_discounts_unchanged(): void
    {
        [$r, $menu, $owner] = $this->store('USD', 6.75);
        $order = $this->order($r, $menu, 3, $this->coupon($owner, 10));                     // 20.25 − 2.025
        $this->assertEqualsWithDelta(2.03, (float) $order->discount_amount, self::D);
        $this->assertEqualsWithDelta(18.22, (float) $order->total, self::D);

        [$j, $jm, $jo] = $this->store('JPY', 333);
        $order = $this->order($j, $jm, 1, $this->coupon($jo, 15));                          // 333 − 49.95
        $this->assertEqualsWithDelta(50.0, (float) $order->discount_amount, self::D);
        $this->assertEqualsWithDelta(283.0, (float) $order->total, self::D);
    }

    public function test_resolver_rounds_to_the_given_currency(): void
    {
        [, , $owner] = $this->store('KWD', 1.125);
        $pct = $this->coupon($owner, 10);
        $svc = new CouponService;
        $ctx = ['surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 3.375];

        $this->assertEqualsWithDelta($this->ba(0.34, 0.338), $svc->resolve($pct->code, $ctx + ['currency' => 'KWD'])['discount'], self::D);
        $this->assertEqualsWithDelta($this->ba(0.34, 0.0), $svc->resolve($pct->code, ['subtotal' => 3.4] + $ctx + ['currency' => 'JPY'])['discount'], self::D);
        $this->assertEqualsWithDelta(0.34, $svc->resolve($pct->code, $ctx + ['currency' => 'USD'])['discount'], self::D);
        // No currency (plan checkout and other callers that pass none): 2 decimals as before.
        $this->assertEqualsWithDelta(0.34, $svc->resolve($pct->code, $ctx)['discount'], self::D);
    }

    public function test_kwd_storefront_pre_flight_shows_the_fils_discount(): void
    {
        [$r, , $owner] = $this->store('KWD', 1.125);
        $coupon = $this->coupon($owner, 10);

        $this->postJson(route('store.applyCoupon', ['store' => $r->id]), ['code' => $coupon->code, 'subtotal' => 3.375])
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('discount', $this->ba(0.34, 0.338));
    }

    public function test_kwd_pos_coupon_check_shows_the_fils_discount(): void
    {
        [$r, , $owner] = $this->store('KWD', 1.125);
        $coupon = $this->coupon($owner, 10);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.coupon.check', ['store' => $r->id]), ['code' => $coupon->code, 'subtotal' => 3.375])
            ->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('value', $this->ba(0.34, 0.338));
    }

    public function test_kwd_agent_quote_matches_the_order(): void
    {
        [$r, $menu, $owner] = $this->store('KWD', 1.125);
        $coupon = $this->coupon($owner, 10);

        $quote = AgentToolsService::validateCart($r, [['item_id' => $menu->id, 'quantity' => 3]], $coupon->code);
        $this->assertEqualsWithDelta($this->ba(0.34, 0.338), $quote['discount'], self::D);
        $this->assertEqualsWithDelta($this->ba(3.035, 3.037), $quote['total'], self::D);

        $order = $this->order($r, $menu, 3, $coupon);
        $this->assertEqualsWithDelta($quote['total'], (float) $order->total, self::D);
    }

    public function test_kwd_bogo_discount_keeps_fils(): void
    {
        [$r, $menu, $owner] = $this->store('KWD', 1.125);
        $coupon = Coupon::create([
            'code' => 'BOGO'.Str::upper(Str::random(4)), 'coupon_type' => 'bogo', 'user_id' => $owner->id,
            'percentage' => 0, 'limit' => 9999, 'buy_scope' => 'item', 'buy_qty' => 1, 'get_qty' => 1,
            'buy_id' => $menu->id, 'get_id' => $menu->id, 'get_discount_percent' => 50,
        ]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'variation' => 0, 'unit_price' => 1.125]);

        // 2 × 1.125, the second at half price: 0.5625 off.
        $this->assertEqualsWithDelta($this->ba(0.56, 0.563), (new CouponService)->bogoDiscount($coupon, $order->fresh()), self::D);
    }
}
