<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Bugs7;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Bug batch 7 · 4 — the storefront coupon check (restaurant.applyCoupon) received the subtotal only, so a buy-X-get-Y
 * coupon previewed 0 off while the placed order got the real discount. The themes now post the cart lines (the same
 * `items` shape submitOrder takes) and the endpoint prices the coupon on them exactly like the order will: the lines'
 * sold unit prices (base / variant / scheduled price + extras + options), in the store currency, capped at the
 * subtotal. Without lines (an old cached theme bundle) the response is unchanged.
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class StorefrontBxgyPreviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{owner: User, post: Store, buy: Menu, get: Menu} */
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function boot(string $currency, float|int $buyPrice, float|int $getPrice): void
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($store->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', 0);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', 0);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $buy = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => $buyPrice, 'active' => '1']);
        $get = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Soda', 'price' => $getPrice, 'active' => '1']);

        $this->ctx = ['owner' => $owner, 'store' => $store->fresh(), 'buy' => $buy->fresh(), 'get' => $get->fresh()];
    }

    /** Buy 2 pizzas, get 1 soda at $pct % off. */
    private function bxgy(int $pct = 50, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'BXGY'.Str::upper(Str::random(4)), 'coupon_type' => 'bogo', 'user_id' => $this->ctx['owner']->id,
            'percentage' => 0, 'limit' => 9999, 'used' => 0, 'buy_scope' => 'item', 'buy_id' => $this->ctx['buy']->id, 'buy_qty' => 2,
            'get_id' => $this->ctx['get']->id, 'get_qty' => 1, 'get_discount_percent' => $pct, 'issuer_scope' => 'owner', 'applies_to' => 'food_order',
        ], $overrides));
    }

    /** @param list<array<string, mixed>> $lines storefront cart lines (submitOrder `items` shape) */
    private function preview(string $code, float $subtotal, ?array $lines, bool $asJsonString = false): array
    {
        $body = ['code' => $code, 'subtotal' => $subtotal];
        if ($lines !== null) {
            $body['items'] = $asJsonString ? json_encode($lines) : $lines;
            $body['ordering-type'] = 'takeaway';
        }

        return $this->postJson(route('store.applyCoupon', $this->ctx['store']), $body)->assertOk()->json();
    }

    /** @param list<array<string, mixed>> $lines */
    private function place(string $code, array $lines): Order
    {
        $this->post(route('store.submitOrder', $this->ctx['store']), [
            'items' => json_encode($lines), 'tip_amount' => '0', 'name' => 'Web', 'ordering-type' => 'takeaway',
            'phone-number' => '555-9', 'pay_via' => 'pay_online', 'coupon_code' => $code,
        ])->assertOk()->assertJson(['success' => true]);

        return Order::where('store_id', $this->ctx['store']->id)->latest('id')->firstOrFail();
    }

    private function line(Menu $menu, int $qty, int $variant = 0): array
    {
        return ['id' => $menu->id, 'quantity' => $qty, 'variants' => $variant, 'extras' => [], 'mods' => []];
    }

    /** @return array<string, array{string, float|int, float|int, float}> currency, buy, get, discount */
    public static function currencies(): array
    {
        return [
            'USD' => ['USD', 10.00, 7.25, 3.63],   // 50 % of 7.25 = 3.625 → 3.63
            'KWD' => ['KWD', 2.500, 1.125, 0.563], // 50 % of 1.125 = 0.5625 → 0.563 fils
            'JPY' => ['JPY', 1200, 855, 428.0],    // 50 % of 855 = 427.5 → 428 yen
        ];
    }

    #[DataProvider('currencies')]
    public function test_preview_with_cart_lines_equals_the_placed_order_discount(string $cur, float|int $buyP, float|int $getP, float $disc): void
    {
        $this->boot($cur, $buyP, $getP);
        $c = $this->bxgy();
        $lines = [$this->line($this->ctx['buy'], 2), $this->line($this->ctx['get'], 1)];
        $subtotal = (float) (2 * $buyP + $getP);

        $res = $this->preview($c->code, $subtotal, $lines);
        $this->assertTrue($res['success']);
        $this->assertSame('bogo', $res['coupon_type']);
        $this->assertSame($disc, (float) $res['discount']); // before: 0.0
        // The items field may also arrive as the JSON string submitOrder takes.
        $this->assertSame($disc, (float) $this->preview($c->code, $subtotal, $lines, true)['discount']); // before: 0.0

        $order = $this->place($c->code, $lines);
        $this->assertEqualsWithDelta((float) $res['discount'], (float) $order->discount_amount, 0.0000001, 'preview == placed order');
    }

    public function test_preview_prices_the_variant_and_the_cheapest_units_like_the_order(): void
    {
        $this->boot('KWD', 2.500, 1.000);
        $variant = MenuVariant::create(['menu_id' => $this->ctx['get']->id, 'store_id' => $this->ctx['store']->id, 'price' => 1.250, 'active' => 1]);
        $c = $this->bxgy(100);
        // Two get lines: the plain soda (1.000) and the 1.250 variant — the free unit is the cheapest (1.000).
        $lines = [$this->line($this->ctx['buy'], 2), $this->line($this->ctx['get'], 1, $variant->id), $this->line($this->ctx['get'], 1)];

        $res = $this->preview($c->code, 7.25, $lines);
        $this->assertSame(1.0, (float) $res['discount']); // before: 0.0
        $this->assertEqualsWithDelta(1.0, (float) $this->place($c->code, $lines)->discount_amount, 0.0000001);

        // Only the variant line: its sold unit price 1.250.
        $c2 = $this->bxgy(100);
        $only = [$this->line($this->ctx['buy'], 2), $this->line($this->ctx['get'], 1, $variant->id)];
        $this->assertSame(1.25, (float) $this->preview($c2->code, 6.25, $only)['discount']); // before: 0.0
        $this->assertEqualsWithDelta(1.25, (float) $this->place($c2->code, $only)->discount_amount, 0.0000001);
    }

    public function test_buy_condition_not_met_and_unknown_lines_preview_nothing(): void
    {
        $this->boot('USD', 10, 5);
        $c = $this->bxgy();
        // One pizza only: no free unit (same as the order).
        $this->assertSame(0.0, (float) $this->preview($c->code, 15, [$this->line($this->ctx['buy'], 1), $this->line($this->ctx['get'], 1)])['discount']);
        // A line for another store's item is ignored, never priced.
        $this->assertSame(0.0, (float) $this->preview($c->code, 15, [['id' => 999999, 'quantity' => 5]])['discount']);
    }

    public function test_without_lines_the_response_is_unchanged(): void
    {
        $this->boot('USD', 10, 5);
        $c = $this->bxgy();
        // An old cached theme bundle posts the subtotal only: 0, as before.
        $this->assertSame(0.0, (float) $this->preview($c->code, 25, null)['discount']);

        // A percentage coupon ignores the lines: 10 % of the posted subtotal.
        $pct = Coupon::create(['code' => 'TEN'.Str::upper(Str::random(3)), 'coupon_type' => 'percentage', 'percentage' => 10, 'user_id' => $this->ctx['owner']->id, 'limit' => 99, 'used' => 0]);
        $this->assertSame(2.5, (float) $this->preview($pct->code, 25, null)['discount']);
        $this->assertSame(2.5, (float) $this->preview($pct->code, 25, [$this->line($this->ctx['buy'], 2), $this->line($this->ctx['get'], 1)])['discount']);
    }
}
