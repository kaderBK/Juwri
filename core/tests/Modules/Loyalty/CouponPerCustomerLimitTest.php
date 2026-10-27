<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CouponService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * WI-M3: `per_customer_limit` gets a real atomic gate — a coupon_redemptions table
 * with a UNIQUE (coupon_id, customer_id, seq) key. The COUNT-then-compare in
 * resolve() was check-then-act; two concurrent orders by the same diner both
 * passed. claimForCustomer() inserts a slot and the database refuses the loser.
 */
class CouponPerCustomerLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: User, 1: Store, 2: Menu} */
    private function seedOwner(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 10, 'active' => '1']);

        return [$owner, $r, $menu];
    }

    private function makeOrder(Store $r, Menu $menu, Customer $customer): Order
    {
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway',
            'status' => 'pending', 'customer_id' => $customer->id,
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 3, 'variation' => 0]);

        return $order->fresh();
    }

    private function customer(Store $r): Customer
    {
        return Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
    }

    private function couponFor(User $owner, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'PCL-'.rand(1000, 9999), 'percentage' => 20, 'limit' => 99, 'used' => 0,
            'user_id' => $owner->id, 'coupon_type' => 'percentage', 'issuer_scope' => 'owner',
            'applies_to' => 'food_order', 'per_customer_limit' => 1, 'expiry_at' => now()->addMonth(),
        ], $overrides));
    }

    public function test_second_order_by_the_same_customer_gets_no_discount(): void
    {
        // The RACE the unique key exists for: resolve()'s soft pre-flight sees zero
        // prior redemptions and passes, then a concurrent order for the same diner
        // takes the last per-customer slot in the window before this one's atomic
        // claim. We inject that competitor at CouponRedemption::creating — after
        // resolve's count, during claimForCustomer's insert — so claimForCustomer
        // hits the UNIQUE key, recounts, and is refused. (Sequentially, resolve
        // itself already rejects a repeat, so this is the only way to exercise the
        // settlement-level 'per_customer' denial deterministically.)
        if (DB::getDriverName() === 'pgsql') {
            // The competing insert runs inside the claim's savepoint here, so the rollback removes it too; the
            // race needs a second connection, which a rolled-back test transaction cannot provide.
            $this->markTestSkipped('needs a second connection on PostgreSQL');
        }
        [$owner, $r, $menu] = $this->seedOwner();
        $coupon = $this->couponFor($owner, ['per_customer_limit' => 1]);
        $a = $this->customer($r);

        $raced = false;
        CouponRedemption::creating(function (CouponRedemption $row) use (&$raced, $coupon, $a) {
            if (! $raced && (int) $row->coupon_id === (int) $coupon->id && (int) $row->customer_id === (int) $a->id) {
                $raced = true;
                // A concurrent winner takes slot #1 via a raw insert (no model event).
                DB::table('coupon_redemptions')->insert([
                    'coupon_id' => $coupon->id, 'customer_id' => $a->id, 'seq' => 1, 'created_at' => now(),
                ]);
            }
        });

        $order = $this->makeOrder($r, $menu, $a);
        $out = OrderPricing::applyCouponAndCredit($order, ['coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0, 'customer_id' => $a->id]);

        $this->assertSame(0.0, (float) $out['discount']);
        $this->assertNull($order->fresh()->coupon_id);
        $this->assertSame('per_customer', $out['claim_denied_reason']);
        $this->assertSame(0, (int) $coupon->fresh()->used, 'the refused order must NOT burn the global counter');
        // Only the concurrent winner's slot exists — this order claimed nothing.
        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $a->id)->count());
    }

    public function test_a_different_customer_is_unaffected(): void
    {
        [$owner, $r, $menu] = $this->seedOwner();
        $coupon = $this->couponFor($owner);
        $a = $this->customer($r);
        $b = $this->customer($r);

        OrderPricing::applyCouponAndCredit($this->makeOrder($r, $menu, $a), ['coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0, 'customer_id' => $a->id]);
        $outB = OrderPricing::applyCouponAndCredit($this->makeOrder($r, $menu, $b), ['coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0, 'customer_id' => $b->id]);

        $this->assertGreaterThan(0.0, (float) $outB['discount']);
        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $a->id)->where('seq', 1)->count());
        $this->assertSame(1, CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $b->id)->where('seq', 1)->count());
    }

    public function test_the_unique_key_refuses_a_duplicate_slot(): void
    {
        [$owner, $r] = $this->seedOwner();
        $coupon = $this->couponFor($owner);
        $a = $this->customer($r);

        CouponRedemption::create(['coupon_id' => $coupon->id, 'customer_id' => $a->id, 'seq' => 1, 'created_at' => now()]);
        $threw = false;
        try {
            // A savepoint keeps the test's outer transaction usable on PostgreSQL after the failed insert.
            DB::transaction(fn () => CouponRedemption::create(['coupon_id' => $coupon->id, 'customer_id' => $a->id, 'seq' => 1, 'created_at' => now()]));
        } catch (QueryException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the unique key must refuse a duplicate (coupon, customer, seq)');

        // claimForCustomer must return false (exhausted at limit 1), not throw.
        $this->assertFalse(CouponService::claimForCustomer($coupon, $a->id));
    }

    public function test_a_limit_above_one_allows_exactly_that_many(): void
    {
        [$owner, $r] = $this->seedOwner();
        $coupon = $this->couponFor($owner, ['per_customer_limit' => 3]);
        $a = $this->customer($r);

        $r1 = CouponService::claimForCustomer($coupon, $a->id);
        $r2 = CouponService::claimForCustomer($coupon, $a->id);
        $r3 = CouponService::claimForCustomer($coupon, $a->id);
        $r4 = CouponService::claimForCustomer($coupon, $a->id);

        $this->assertIsInt($r1);
        $this->assertIsInt($r2);
        $this->assertIsInt($r3);
        $this->assertFalse($r4);
        $this->assertSame([1, 2, 3], CouponRedemption::where('coupon_id', $coupon->id)->where('customer_id', $a->id)->orderBy('seq')->pluck('seq')->all());
    }

    public function test_legacy_order_history_still_counts(): void
    {
        [$owner, $r] = $this->seedOwner();
        $coupon = $this->couponFor($owner, ['per_customer_limit' => 1]);
        $a = $this->customer($r);

        // Pre-M3 world: a redemption recorded only as an order, no ledger row.
        Order::create(['store_id' => $r->id, 'customer_id' => $a->id, 'type' => 'takeaway', 'status' => 'completed', 'coupon_id' => $coupon->id]);

        $this->assertSame(1, CouponRedemption::countFor($coupon->id, $a->id));
        $this->assertFalse(CouponService::claimForCustomer($coupon, $a->id), 'a past redeemer must not get a fresh allowance');
    }

    public function test_no_per_customer_limit_is_never_gated(): void
    {
        [$owner, $r] = $this->seedOwner();
        $coupon = $this->couponFor($owner, ['per_customer_limit' => null]);
        $a = $this->customer($r);

        foreach (range(1, 5) as $i) {
            $this->assertSame(0, CouponService::claimForCustomer($coupon, $a->id), "ungated claim {$i}");
        }
        $this->assertSame(0, CouponRedemption::where('coupon_id', $coupon->id)->count(), 'ungated coupons write no rows');
    }

    public function test_guest_order_is_not_gated(): void
    {
        [$owner] = $this->seedOwner();
        $coupon = $this->couponFor($owner, ['per_customer_limit' => 1]);

        $this->assertSame(0, CouponService::claimForCustomer($coupon, null));
        $this->assertSame(0, CouponService::claimForCustomer($coupon, null));
    }

    public function test_a_lost_global_claim_releases_the_customer_slot(): void
    {
        // The RACE for the global limit: resolve() reads the coupon while it still
        // has capacity, a concurrent order exhausts the global `used` in the window
        // before this order's atomic claimUsage(), so the per-customer slot is
        // claimed first and must be HANDED BACK when the global claim then loses. We
        // inject the competitor at Coupon::retrieved — after resolve reads the
        // in-memory `used`, so resolve still passes, but before claimUsage's atomic
        // UPDATE, which then finds used == limit.
        [$owner, $r, $menu] = $this->seedOwner();
        $coupon = $this->couponFor($owner, ['limit' => 1, 'used' => 0, 'per_customer_limit' => 2]);
        $a = $this->customer($r);

        $raced = false;
        Coupon::retrieved(function ($c) use (&$raced, $coupon) {
            if (! $raced && (int) $c->id === (int) $coupon->id) {
                $raced = true;
                Coupon::whereKey($coupon->id)->update(['used' => 1]); // globally exhausted now
            }
        });

        $out = OrderPricing::applyCouponAndCredit($this->makeOrder($r, $menu, $a), ['coupon_code' => $coupon->code, 'owner_id' => $owner->id, 'subtotal' => 30.0, 'customer_id' => $a->id]);

        $this->assertSame('limit', $out['claim_denied_reason']);
        $this->assertSame(0, CouponRedemption::where('coupon_id', $coupon->id)->count(), 'the per-customer slot must be handed back when the global claim loses');
    }

    public function test_merchant_can_save_and_clear_the_per_customer_limit(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($owner)->post(route('stores.coupons.store'), [
            'code' => 'ONCEPD', 'coupon_type' => 'percentage', 'percentage' => 20,
            'limit' => 99, 'per_customer_limit' => 2,
        ])->assertRedirect();

        $coupon = Coupon::where('code', 'ONCEPD')->firstOrFail();
        $this->assertSame(2, (int) $coupon->per_customer_limit);

        $this->actingAs($owner)->put(route('stores.coupons.update', $coupon), [
            'code' => 'ONCEPD', 'coupon_type' => 'percentage', 'percentage' => 20, 'limit' => 99,
        ])->assertRedirect();

        $this->assertNull($coupon->fresh()->per_customer_limit);
    }
}
