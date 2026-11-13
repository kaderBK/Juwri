<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Coupons & Wallets P4-1: coupon scope columns + backfill mapping.
 */
class CouponScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_columns_are_settable(): void
    {
        $c = Coupon::create([
            'code' => 'RSL10', 'percentage' => 10, 'limit' => 5, 'used' => 0,
            'coupon_type' => 'free_delivery', 'issuer_scope' => 'reseller', 'reseller_id' => 7,
            'applies_to' => 'plan_checkout', 'min_order_amount' => 25.00, 'per_customer_limit' => 1,
            'branch_id' => 3,
        ]);

        $c->refresh();
        $this->assertSame('reseller', $c->issuer_scope);
        $this->assertSame(7, (int) $c->reseller_id);
        $this->assertSame('plan_checkout', $c->applies_to);
        $this->assertSame('free_delivery', $c->coupon_type);
        $this->assertEqualsWithDelta(25.0, (float) $c->min_order_amount, 0.001);
        $this->assertSame(1, (int) $c->per_customer_limit);
        $this->assertSame(3, (int) $c->branch_id);
    }

    public function test_backfill_maps_user_id_convention_to_scope(): void
    {
        // Legacy rows (pre-scope) then the migration's backfill statements.
        DB::table('coupons')->insert([
            // before: 'percentage' => 10 (the value column is split by type: a percent goes to percentage_rate)
            ['code' => 'PLAT', 'percentage_rate' => 10, 'limit' => 5, 'used' => 0, 'user_id' => null, 'coupon_type' => 'percentage'],
            ['code' => 'OWN', 'percentage_rate' => 10, 'limit' => 5, 'used' => 0, 'user_id' => 42, 'coupon_type' => 'percentage'],
        ]);
        DB::table('coupons')->whereNull('user_id')->update(['issuer_scope' => 'platform', 'applies_to' => 'plan_checkout']);
        DB::table('coupons')->whereNotNull('user_id')->update(['issuer_scope' => 'owner', 'applies_to' => 'food_order']);

        $this->assertSame('platform', DB::table('coupons')->where('code', 'PLAT')->value('issuer_scope'));
        $this->assertSame('plan_checkout', DB::table('coupons')->where('code', 'PLAT')->value('applies_to'));
        $this->assertSame('owner', DB::table('coupons')->where('code', 'OWN')->value('issuer_scope'));
        $this->assertSame('food_order', DB::table('coupons')->where('code', 'OWN')->value('applies_to'));
    }

    public function test_resolve_isolates_surfaces(): void
    {
        // A platform coupon and an owner coupon share nothing across surfaces.
        Coupon::create(['code' => 'PLAT', 'percentage' => 10, 'limit' => 5, 'used' => 0, 'coupon_type' => 'percentage', 'user_id' => null, 'expiry_at' => now()->addMonth()]);
        Coupon::create(['code' => 'OWN', 'percentage' => 20, 'limit' => 5, 'used' => 0, 'coupon_type' => 'percentage', 'user_id' => 42, 'expiry_at' => now()->addMonth()]);
        $svc = new CouponService();

        // Platform coupon: valid on plan checkout, invalid in a diner cart.
        $this->assertTrue($svc->resolve('PLAT', ['surface' => 'plan_checkout', 'subtotal' => 100])['ok']);
        $this->assertFalse($svc->resolve('PLAT', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 100])['ok']);

        // Owner coupon: valid for that owner's diner cart, invalid on plan checkout.
        $this->assertTrue($svc->resolve('OWN', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 100])['ok']);
        $this->assertFalse($svc->resolve('OWN', ['surface' => 'plan_checkout', 'subtotal' => 100])['ok']);

        // Another owner cannot use it.
        $this->assertFalse($svc->resolve('OWN', ['surface' => 'food_order', 'owner_id' => 7, 'subtotal' => 100])['ok']);
    }

    public function test_resolve_enforces_min_order_amount(): void
    {
        Coupon::create(['code' => 'MIN50', 'percentage' => 10, 'limit' => 5, 'used' => 0, 'coupon_type' => 'percentage', 'user_id' => 42, 'expiry_at' => now()->addMonth(), 'min_order_amount' => 50]);
        $svc = new CouponService();

        $this->assertFalse($svc->resolve('MIN50', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 30])['ok'], 'below min');
        $this->assertTrue($svc->resolve('MIN50', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 60])['ok'], 'meets min');
    }

    public function test_resolve_enforces_branch_scope(): void
    {
        Coupon::create(['code' => 'BR5', 'percentage' => 10, 'limit' => 5, 'used' => 0, 'coupon_type' => 'percentage', 'user_id' => 42, 'expiry_at' => now()->addMonth(), 'branch_id' => 5]);
        $svc = new CouponService();

        $this->assertTrue($svc->resolve('BR5', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 50, 'branch_id' => 5])['ok'], 'matching branch');
        $this->assertFalse($svc->resolve('BR5', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 50, 'branch_id' => 9])['ok'], 'wrong branch');
        $this->assertFalse($svc->resolve('BR5', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 50])['ok'], 'no branch');
    }

    public function test_resolve_enforces_per_customer_limit(): void
    {
        $owner = \Juwri\Core\Models\User::factory()->create(['status' => 1]);
        $r = \Juwri\Core\Models\Store::create(['user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1']);
        $c1 = \App\Models\Customer::create(['name' => 'A', 'store_id' => $r->id, 'phone' => '55-1']);
        $c2 = \App\Models\Customer::create(['name' => 'B', 'store_id' => $r->id, 'phone' => '55-2']);

        $coupon = Coupon::create(['code' => 'ONCE', 'percentage' => 10, 'limit' => 99, 'used' => 0, 'coupon_type' => 'percentage', 'user_id' => $owner->id, 'expiry_at' => now()->addMonth(), 'per_customer_limit' => 1]);
        // c1 already redeemed it once.
        \App\Models\Order::create(['store_id' => $r->id, 'customer_id' => $c1->id, 'type' => 'takeaway', 'status' => 'completed', 'coupon_id' => $coupon->id]);
        $svc = new CouponService();

        $this->assertFalse($svc->resolve('ONCE', ['surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 50, 'customer_id' => $c1->id])['ok'], 'customer over their cap');
        $this->assertTrue($svc->resolve('ONCE', ['surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 50, 'customer_id' => $c2->id])['ok'], 'a different customer may still use it');
    }

    public function test_resolve_flags_free_delivery(): void
    {
        Coupon::create(['code' => 'FREESHIP', 'percentage' => 0, 'limit' => 5, 'used' => 0, 'coupon_type' => 'free_delivery', 'user_id' => 42, 'expiry_at' => now()->addMonth()]);
        $result = new CouponService()->resolve('FREESHIP', ['surface' => 'food_order', 'owner_id' => 42, 'subtotal' => 50]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['free_delivery']);
        $this->assertEqualsWithDelta(0.0, $result['discount'], 0.001, 'free delivery is not a subtotal discount');
    }
}
