<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Platform E2E · A1 — the ordering engine's money contract at the point a
 * couponed storefront order is snapshotted.
 *
 * The `platform/a-order.js` journey reads the created order's `subtotal`,
 * `discount_amount`, `tax_total` and `grand_total` COLUMNS straight from the DB
 * and asserts `grand_total = subtotal − discount + tax`, with tax charged on the
 * PRE-discount subtotal (CONVENTIONS §theme-totals; the client `totals()` warns
 * that taxing `sub − disc` under-quotes the moment a coupon is applied).
 *
 * Those snapshot columns — the ones D-lane reporting and every SQL revenue
 * aggregate sum — were exercised WITHOUT a coupon (OrderTotalSnapshotTest) and
 * the couponed total was asserted only through the live accessor
 * (CriticalPathsTest). This pins the intersection nobody owned: the frozen
 * columns under a percentage coupon on a taxed store, reconciling to the cent
 * with `Order::getTotalAttribute()`.
 *
 * Discriminating: were tax computed on the discounted subtotal, `tax_total`
 * would be 12.00 and `grand_total` 92.00 instead of 15.00 / 95.00.
 */
class PlatformE2eA1OrderTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar'); // the run is Arabic-only; also stamps orders.locale
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // The storefront checkout dispatches an owner order-notification (queue
        // sync on lanes); faking them keeps the test on the money contract, not
        // the mailer's markdown pipeline.
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Bites', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '15'); // 15 % of the bare subtotal
        StoreOption::updateStoreOption($store->id, 'allow_coupons', '1');
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

    /** An owner-scoped percentage coupon (StoreCouponController shape). */
    private function coupon(Store $store, string $code, float $pct): Coupon
    {
        return Coupon::create([
            'code' => $code, 'percentage' => $pct, 'limit' => 0, 'used' => 0,
            'expiry_at' => null, 'coupon_type' => 'percentage',
            'applies_to' => 'all', 'issuer_scope' => 'owner', 'user_id' => $store->user_id,
        ]);
    }

    private function send(Store $store, array $extra = []): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'name' => 'ضيف تجريبي',
            'ordering-type' => 'takeaway',
            'phone-number' => '+966512345678',
            'pay_via' => 'cash',
        ], $extra));
    }

    public function test_a_couponed_order_snapshots_grand_total_as_subtotal_minus_discount_plus_tax_after_discount(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 100.00);
        $this->coupon($store, 'SAVE20', 20);

        $this->send($store, [
            'items' => json_encode([[
                'id' => $burger->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => [],
            ]]),
            'coupon_code' => 'SAVE20',
        ])->assertOk()->assertJson(['success' => true]);

        $order = Order::query()->latest('id')->firstOrFail();

        // The coupon actually applied.
        $this->assertNotNull($order->coupon_id, 'the coupon must be recorded on the order');
        $this->assertEqualsWithDelta(20.00, (float) $order->discount_amount, 0.001, 'discount = 20 % of 100');

        // The FROZEN snapshot columns the journey reads — P6-271: tax on the subtotal
        // AFTER the discount, grand = subtotal − discount + tax.
        $this->assertEqualsWithDelta(100.00, (float) $order->subtotal, 0.001, 'subtotal column = 100');
        $this->assertEqualsWithDelta(12.00, (float) $order->tax_total, 0.001, 'tax_total = 15 % of 80 (after the coupon), not of 100');
        $this->assertEqualsWithDelta(92.00, (float) $order->grand_total, 0.001, 'grand_total = 100 − 20 + 12');

        // The frozen column and the live price authority agree to the cent.
        $this->assertEqualsWithDelta(
            round((float) $order->total, 2),
            (float) $order->grand_total,
            0.001,
            'grand_total column must equal Order::getTotalAttribute()'
        );

        // The order carries the locale it was placed in — what the track page reads.
        $this->assertSame('ar', (string) $order->locale, 'the order is stamped with the request locale');
    }

    /**
     * Guard the exact regression the client `totals()` comment warns about: taxing
     * the DISCOUNTED subtotal. Same basket, no coupon → tax is still 15.00 and the
     * grand is the full 115.00, so the couponed case above cannot be "passing" only
     * because tax happened to be computed on the reduced amount.
     */
    public function test_the_same_basket_without_a_coupon_taxes_the_full_subtotal(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 100.00);

        $this->send($store, [
            'items' => json_encode([[
                'id' => $burger->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => [],
            ]]),
        ])->assertOk()->assertJson(['success' => true]);

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertNull($order->coupon_id);
        $this->assertEqualsWithDelta(0.00, (float) $order->discount_amount, 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $order->subtotal, 0.001);
        $this->assertEqualsWithDelta(15.00, (float) $order->tax_total, 0.001);
        $this->assertEqualsWithDelta(115.00, (float) $order->grand_total, 0.001);
    }
}
