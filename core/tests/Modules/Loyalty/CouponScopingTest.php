<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coupon namespace isolation: a restaurant's diner checkout must only match that
 * owner's own coupons. Global/platform (user_id NULL) coupons and other owners'
 * coupons must never apply. Regression cover for the CouponService scoping fix.
 */
class CouponScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'        => 'SAVE50',
            'coupon_type' => 'percentage',
            'percentage'  => 50,
            'limit'       => 5,
            'used'        => 0,
            'expiry_at'   => now()->addDays(7),
            'user_id'     => 1,
        ], $overrides));
    }

    public function test_owner_coupon_applies_for_that_owner(): void
    {
        $this->makeCoupon(['user_id' => 42]);

        $result = new CouponService()->validate('SAVE50', 100.0, 42);

        $this->assertTrue($result['ok']);
        $this->assertSame(50.0, $result['discount']);
    }

    public function test_global_admin_coupon_does_not_leak_into_restaurant_checkout(): void
    {
        // Platform/admin coupon (no owner) — belongs to plan checkout, not diners.
        $this->makeCoupon(['user_id' => null]);

        $result = new CouponService()->validate('SAVE50', 100.0, 42);

        $this->assertFalse($result['ok']);
    }

    public function test_another_owners_coupon_does_not_apply(): void
    {
        $this->makeCoupon(['user_id' => 7]);

        $result = new CouponService()->validate('SAVE50', 100.0, 42);

        $this->assertFalse($result['ok']);
    }

    public function test_global_coupon_is_addressable_only_when_opted_in(): void
    {
        $this->makeCoupon(['user_id' => null]);

        // Platform plan checkout explicitly opts into the global namespace.
        $result = new CouponService()->validate('SAVE50', 100.0, 42, true);

        $this->assertTrue($result['ok']);
    }
}
