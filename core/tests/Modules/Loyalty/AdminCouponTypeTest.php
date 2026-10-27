<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-5: superadmin can create fixed-amount platform coupons (not just
 * percentage). The `percentage` column carries the flat amount for a fixed
 * coupon, and the 1–100 cap must only apply to the percentage type.
 */
class AdminCouponTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        $this->admin = User::factory()->create([
            'group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    public function test_admin_can_create_a_fixed_amount_plan_coupon(): void
    {
        $res = $this->actingAs($this->admin)->post(route('admin.coupons.store'), [
            'code' => 'FLAT150',
            'coupon_type' => 'fixed',
            'discount_percentage' => 150, // flat amount, above the percentage cap
            'expiry_at' => now()->addMonth()->toDateString(),
            'uses_limit' => 5,
            'applies_interval' => 'any',
        ]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('coupons', [
            // before: 'percentage' => 150 (one integer column for a percent or an amount)
            'code' => 'FLAT150', 'coupon_type' => 'fixed', 'percentage_rate' => null, 'amount_minor' => 15000, 'currency_code' => 'USD', 'user_id' => null,
        ]);
    }

    public function test_percentage_coupon_is_still_capped_at_100(): void
    {
        $res = $this->actingAs($this->admin)->post(route('admin.coupons.store'), [
            'code' => 'PCT150',
            'coupon_type' => 'percentage',
            'discount_percentage' => 150, // invalid for a percentage
            'expiry_at' => now()->addMonth()->toDateString(),
            'uses_limit' => 5,
            'applies_interval' => 'any',
        ]);

        $res->assertOk()->assertJson(['success' => false]);
        $this->assertDatabaseMissing('coupons', ['code' => 'PCT150']);
    }
}
