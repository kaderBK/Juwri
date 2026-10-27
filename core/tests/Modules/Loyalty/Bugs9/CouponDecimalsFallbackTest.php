<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty\Bugs9;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug batch 9 · 3 — CouponService::discountDecimals() said "callers without a currency keep 2 decimals", but every
 * caller passes one since bug batch 5; the only currency-less path left (validate()'s plan-checkout fallback) rounded
 * a KWD platform's plan discount to 2 decimals. It now rounds in the platform currency (what a plan is billed in).
 *
 * `before:` comments record what the same assertion returned before the fix.
 */
class CouponDecimalsFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function platformCoupon(string $code, string $type, float $value): Coupon
    {
        return Coupon::create([
            'code' => $code, 'coupon_type' => $type, 'percentage' => $value,
            'limit' => 100, 'used' => 0, 'user_id' => null, 'reseller_id' => null,
        ]);
    }

    public function test_plan_checkout_fallback_rounds_in_the_platform_currency(): void
    {
        config(['settings.currency_code' => 'KWD']);
        $this->platformCoupon('B9PCT', 'percentage', 10);

        $r = (new CouponService)->validate('B9PCT', 12.345);
        $this->assertTrue($r['ok']);
        $this->assertSame(1.235, $r['discount']); // before: 1.23

        $this->platformCoupon('B9FIX', 'fixed', 1.125);
        $this->assertSame(1.125, (new CouponService)->validate('B9FIX', 20)['discount']); // before: 1.13
    }

    public function test_usd_is_unchanged_and_jpy_rounds_to_whole_yen(): void
    {
        config(['settings.currency_code' => 'USD']);
        $this->platformCoupon('B9USD', 'percentage', 10);
        $this->assertSame(1.23, (new CouponService)->validate('B9USD', 12.345)['discount']); // before: 1.23

        config(['settings.currency_code' => 'JPY']);
        $this->platformCoupon('B9JPY', 'percentage', 15);
        $this->assertSame(185.0, (new CouponService)->validate('B9JPY', 1234)['discount']); // before: 185.1
    }

    public function test_an_explicit_currency_still_wins(): void
    {
        config(['settings.currency_code' => 'USD']);
        $this->platformCoupon('B9EXP', 'percentage', 10);
        $r = (new CouponService)->resolve('B9EXP', ['surface' => 'plan_checkout', 'subtotal' => 12.345, 'currency' => 'KWD']);
        $this->assertSame(1.235, $r['discount']);
    }
}
