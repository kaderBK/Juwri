<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Single-use coupons must not double-grant. resolve()'s `used < limit` gate is
 * check-then-act: two concurrent 100%-off checkouts both clear it, then a plain
 * `increment('used')` at each settlement mints two free plans from one limit=1
 * coupon. CouponService::claimUsage() closes the race with a conditional atomic
 * UPDATE — exactly one caller wins. These tests stand in for two racing
 * settlements by claiming twice against the same row.
 */
class CouponClaimAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'FREE100',
            'coupon_type' => 'percentage',
            'percentage' => 100,
            'limit' => 1,
            'used' => 0,
            'expiry_at' => now()->addDays(7),
            'user_id' => null,
        ], $overrides));
    }

    public function test_limit_one_coupon_claims_exactly_once_across_two_settlements(): void
    {
        $coupon = $this->makeCoupon(['limit' => 1]);

        $first = CouponService::claimUsage($coupon->id);
        $second = CouponService::claimUsage($coupon->id);

        // Exactly one settlement may grant.
        $this->assertTrue($first, 'First settlement should claim the single use.');
        $this->assertFalse($second, 'Second settlement must be denied — coupon exhausted.');

        // And `used` is never pushed past `limit`.
        $this->assertSame(1, (int) $coupon->fresh()->used);
    }

    public function test_multi_use_coupon_claims_up_to_limit_then_denies(): void
    {
        $coupon = $this->makeCoupon(['limit' => 3]);

        $this->assertTrue(CouponService::claimUsage($coupon->id));
        $this->assertTrue(CouponService::claimUsage($coupon->id));
        $this->assertTrue(CouponService::claimUsage($coupon->id));
        $this->assertFalse(CouponService::claimUsage($coupon->id), 'Fourth claim past limit=3 must fail.');

        $this->assertSame(3, (int) $coupon->fresh()->used);
    }

    public function test_unlimited_sentinel_limit_never_blocks(): void
    {
        // limit 0 is the "unlimited" sentinel (the column is NOT NULL, so 0 is the
        // representable sentinel; claimUsage() also guards null defensively) —
        // every claim succeeds and `used` just keeps counting.
        $coupon = $this->makeCoupon(['code' => 'UNL0', 'limit' => 0]);

        $this->assertTrue(CouponService::claimUsage($coupon->id));
        $this->assertTrue(CouponService::claimUsage($coupon->id));
        $this->assertSame(2, (int) $coupon->fresh()->used);
    }

    public function test_resolve_does_not_falsely_block_unlimited_coupon(): void
    {
        $this->makeCoupon(['code' => 'UNLIM', 'limit' => 0, 'used' => 500]);

        $result = (new CouponService)->resolve('UNLIM', [
            'surface' => 'plan_checkout',
            'subtotal' => 100.0,
        ]);

        $this->assertTrue($result['ok'], 'A used>0, unlimited-limit coupon must still resolve.');
    }
}
