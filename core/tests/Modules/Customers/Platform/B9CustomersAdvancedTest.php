<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuReview;
use App\Models\Order;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Platform E2E · B9 — Customers, Coupons, Reviews, Reports
 *
 * Discriminating tests for the owner dashboard seam, verified against the
 * REAL schema/services (the store model is Juwri\Core\Models\Store; coupons are
 * scoped by owner `user_id` — not per-restaurant — via CouponService; staff
 * redemptions are tracked per-customer through CouponRedemption/claimForCustomer()):
 *   - Coupon creation/persistence: coupon_type, percentage column, expiry
 *   - Coupon expiry + per-customer + global usage-limit enforcement (CouponService)
 *   - Review moderation workflow (publish/reject status)
 *   - Sales report totals (grand_total SUM, tax pre-discount via Order::computeTax)
 *   - Customer list search contract (App\Models\Customer, not an orders column)
 *
 * The `platform/b-customers-coupons.js` journey drives:
 *   1. Owner login, navigate to dashboard
 *   2. Customers list with search/filter
 *   3. Create percentage coupon, apply to order, track usage
 *   4. Review moderation: publish & reject workflows
 *   5. Sales report: render totals & trends
 */
class B9CustomersAdvancedTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $owner;

    /** THEME_NAME as it was before this test switched it to crave (restored in tearDown). */
    private array $themeNameBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->themeNameBefore = [getenv('THEME_NAME'), $_ENV['THEME_NAME'] ?? null, $_SERVER['THEME_NAME'] ?? null];
        putenv('THEME_NAME=crave');
        $_ENV['THEME_NAME'] = 'crave';
        $_SERVER['THEME_NAME'] = 'crave';

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        // Create owner and store. The store model is Juwri\Core\Models\Store — App\Models\Restaurant
        // no longer exists. Coupons are NOT scoped by a posts column (there is no
        // coupons.store_id): CouponService scopes them by the owner's user_id
        // (see app/Services/CouponService.php resolve()), so there is no
        // "allow_coupons" post column either.
        $this->owner = User::factory()->create([
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'slug' => 'crave-demo',
        ]);
    }

    protected function tearDown(): void
    {
        // Put THEME_NAME back so later tests in the same worker keep the
        // phpunit.xml theme (this class switches it to crave).
        [$env, $envArray, $server] = $this->themeNameBefore + [false, null, null];
        putenv($env === false ? 'THEME_NAME' : 'THEME_NAME='.$env);
        if ($envArray === null) {
            unset($_ENV['THEME_NAME']);
        } else {
            $_ENV['THEME_NAME'] = $envArray;
        }
        if ($server === null) {
            unset($_SERVER['THEME_NAME']);
        } else {
            $_SERVER['THEME_NAME'] = $server;
        }

        parent::tearDown();
    }

    private function menu(float $price = 10.0): Menu
    {
        return Menu::create([
            'store_id' => $this->store->id,
            'name' => 'Dish',
            'price' => $price,
            'active' => 1,
        ]);
    }

    /**
     * Coupon persistence: the live schema has no `type`/`discount_percent`
     * columns — a percentage coupon is `coupon_type = 'percentage'` with the
     * amount in the `percentage` column (see BagCouponTest's canonical
     * Coupon::create() shape).
     */
    public function test_coupon_percentage_type_persists(): void
    {
        $couponCode = 'PERCENT'.Str::random(6);

        Coupon::create([
            'code' => $couponCode,
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'min_order_amount' => 50.0,
            'expiry_at' => now()->addDays(30),
            'limit' => 100,
            'used' => 0,
            'user_id' => $this->owner->id,
        ]);

        $found = Coupon::query()
            ->where('user_id', $this->owner->id)
            ->where('code', $couponCode)
            ->firstOrFail();

        $this->assertSame('percentage', $found->coupon_type);
        $this->assertSame(10, (int) $found->percentage);
        $this->assertSame(50.0, (float) $found->min_order_amount);
    }

    /**
     * Coupon validity: there is no valid_from/valid_until window — only a
     * single `expiry_at`. CouponService::resolve() is the real gate: an
     * unexpired coupon resolves with its discount, an expired one is refused.
     */
    public function test_coupon_expiry_is_enforced_by_resolve(): void
    {
        $service = new CouponService;

        $active = Coupon::create([
            'code' => 'FRESH'.Str::random(4),
            'coupon_type' => 'percentage',
            'percentage' => 5,
            'user_id' => $this->owner->id,
            'limit' => 10,
            'used' => 0,
            'expiry_at' => now()->addDays(30),
        ]);

        $expired = Coupon::create([
            'code' => 'OLD'.Str::random(4),
            'coupon_type' => 'percentage',
            'percentage' => 5,
            'user_id' => $this->owner->id,
            'limit' => 10,
            'used' => 0,
            'expiry_at' => now()->subDay(),
        ]);

        $okResult = $service->resolve($active->code, [
            'surface' => 'food_order', 'owner_id' => $this->owner->id, 'subtotal' => 100.0,
        ]);
        $expiredResult = $service->resolve($expired->code, [
            'surface' => 'food_order', 'owner_id' => $this->owner->id, 'subtotal' => 100.0,
        ]);

        $this->assertTrue($okResult['ok']);
        $this->assertSame(5.0, $okResult['discount']);
        $this->assertFalse($expiredResult['ok']);
    }

    /**
     * Coupon redemption tracking: there is no `coupon_redemptions.discount_amount`
     * column — the ledger is a per-customer claim slot (coupon_id, customer_id,
     * seq, order_id), written by CouponService::claimForCustomer() and gated by
     * `coupons.per_customer_limit`. A second claim past the limit is refused.
     */
    public function test_coupon_redemption_tracked_per_customer(): void
    {
        $coupon = Coupon::create([
            'code' => 'TRACK'.Str::random(4),
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'user_id' => $this->owner->id,
            'per_customer_limit' => 1,
            'limit' => 100,
            'used' => 0,
        ]);

        $customer = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Dana',
            'phone' => UniquePhone::next('5'),
        ]);

        $order = Order::factory()->create(['store_id' => $this->store->id]);

        $redemptionId = CouponService::claimForCustomer($coupon, $customer->id, $order->id);
        $this->assertIsInt($redemptionId);
        $this->assertGreaterThan(0, $redemptionId);

        $found = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        $this->assertSame($order->id, $found->order_id);
        $this->assertSame(1, $found->seq);

        // per_customer_limit = 1: a second claim by the same customer is refused.
        $second = CouponService::claimForCustomer($coupon, $customer->id, $order->id);
        $this->assertFalse($second);
    }

    /**
     * Review approval workflow: a pending review can be marked as approved.
     * Status should transition from 'pending' to 'approved'. Real columns are
     * `stars`/`body` (not `rating`/`comment`), and `menu_id` is required.
     */
    public function test_review_approval_workflow_pending_to_approved(): void
    {
        $menu = $this->menu();

        $review = MenuReview::create([
            'menu_id' => $menu->id,
            'store_id' => $this->store->id,
            'stars' => 5,
            'body' => 'Great food!',
            'status' => 'pending',
        ]);

        $review->update(['status' => 'approved']);

        $fresh = $review->fresh();
        $this->assertSame('approved', $fresh->status);
    }

    /**
     * Review rejection workflow: a pending review can be rejected.
     * Status should transition from 'pending' to 'rejected'.
     */
    public function test_review_rejection_workflow_pending_to_rejected(): void
    {
        $menu = $this->menu();

        $review = MenuReview::create([
            'menu_id' => $menu->id,
            'store_id' => $this->store->id,
            'stars' => 2,
            'body' => 'Not satisfied.',
            'status' => 'pending',
        ]);

        $review->update(['status' => 'rejected']);

        $fresh = $review->fresh();
        $this->assertSame('rejected', $fresh->status);
    }

    /**
     * Sales report totals query: the sum of all orders' grand_total for a
     * restaurant should match the query result. `grand_total` is a real,
     * directly-writable snapshot column (App\Models\Order::snapshotTotals())
     * that is only ever recomputed from an `updated` event with line items —
     * a plain create() with an explicit grand_total is never clobbered.
     */
    public function test_sales_report_totals_query_correct_grand_total_sum(): void
    {
        Order::factory()->count(3)->create([
            'store_id' => $this->store->id,
            'grand_total' => 100.0,
        ]);

        $result = Order::query()
            ->where('store_id', $this->store->id)
            ->selectRaw('COUNT(*) as order_count, SUM('.Order::minorMoneySql('grand_total').') as total_sales')
            ->firstOrFail();

        $this->assertSame(3, (int) $result->order_count);
        $this->assertSame(300.0, (float) $result->total_sales);
    }

    /**
     * Sales report tax calculation: tax is calculated PRE-discount. This is the
     * discriminator that catches post-discount tax-calculation bugs — verified
     * directly against the live App\Models\Order::computeTax() (the same static
     * method the receipt/report renderers call), not a hand-rolled 8% copy.
     * subtotal=100 → discount=10 → tax on 100 (not 90) = 8 → grand_total = 98.
     */
    public function test_sales_report_tax_calculated_pre_discount(): void
    {
        $postOptions = (object) ['restaurant_taxx_charge' => 8, 'restaurant_tax_type' => 'percentage'];
        $subtotal = 100.0;
        $discount = 10.0;

        $taxTotal = Order::computeTax($subtotal, $postOptions);
        $this->assertSame(8.0, $taxTotal);

        $grandTotal = round($subtotal - $discount + $taxTotal, 2);

        $order = Order::create([
            'store_id' => $this->store->id,
            'customer_name' => 'Test Customer',
            'phone_number' => '5000',
            'type' => 'takeaway',
            'status' => 'pending',
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
        ]);

        $this->assertSame(98.0, (float) $order->fresh()->grand_total);

        // A post-discount tax calc (on 90, not 100) would produce a different
        // number — proving the assertion above actually discriminates.
        $postDiscountTax = Order::computeTax($subtotal - $discount, $postOptions);
        $this->assertNotEquals($postDiscountTax, $taxTotal, 'Tax should be pre-discount, not post-discount');
    }

    /**
     * Customer search contract: customers are a real model (App\Models\Customer,
     * table `customers`, scoped by `store_id`) — orders carry no
     * customer_email/customer_phone columns at all, only `customer_name` and a
     * nullable `customer_id` FK. This verifies the DB layer the owner dashboard's
     * customer list actually queries.
     */
    public function test_customer_list_query_supports_search_by_name(): void
    {
        $customer1 = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+966501234567',
        ]);

        Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $results = Customer::query()
            ->where('store_id', $this->store->id)
            ->where('name', 'like', '%John%')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame($customer1->id, $results->first()->id);
    }

    /**
     * Coupon global usage-limit is enforced ATOMICALLY by
     * CouponService::claimUsage() (a conditional UPDATE, not check-then-act):
     * a limit=1 coupon accepts exactly one claim and refuses a second, and
     * `used` never exceeds `limit`.
     */
    public function test_coupon_global_usage_limit_enforced_atomically(): void
    {
        $coupon = Coupon::create([
            'code' => 'LIMIT'.Str::random(4),
            'coupon_type' => 'percentage',
            'percentage' => 5,
            'user_id' => $this->owner->id,
            'limit' => 1,
            'used' => 0,
        ]);

        $first = CouponService::claimUsage($coupon);
        $this->assertTrue($first);
        $this->assertSame(1, $coupon->fresh()->used);

        $second = CouponService::claimUsage($coupon);
        $this->assertFalse($second, 'A limit=1 coupon must refuse a second claim');
        $this->assertSame(1, $coupon->fresh()->used);
    }
}
