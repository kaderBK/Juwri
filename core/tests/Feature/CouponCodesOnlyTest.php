<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * Community Admin -> Coupon Codes screen: works without Billing ee, and never
 * exposes offer-only fields (auto_apply, starts_at, badge, banner, countdown).
 */
class CouponCodesOnlyTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$', 'settings.currency_pos' => 1]);
    }

    public function test_coupon_store_and_update_work_and_forms_hide_offer_only_fields(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.coupons.create'))
            ->assertOk()
            ->assertDontSee('promo[auto_apply]', false)
            ->assertDontSee('promo[badge]', false)
            ->assertDontSee('promo[banner]', false)
            ->assertDontSee('promo[starts_at]', false)
            ->assertDontSee('promo[show_banner]', false)
            ->assertDontSee('promo[show_countdown]', false);

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.coupons.store'), [
            'code' => 'SAVE20',
            'discount_percentage' => 20,
            'uses_limit' => 10,
            'expiry_at' => now()->addMonth()->format('Y-m-d\TH:i'),
        ]));

        $coupon = Coupon::where('code', 'SAVE20')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.coupons.edit', $coupon->id))
            ->assertOk()
            ->assertDontSee('promo[auto_apply]', false)
            ->assertDontSee('promo[badge]', false);

        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.coupons.update', $coupon->id), [
            'uses_limit' => 25,
            'coupon_type' => 'percentage',
            'expiry_at' => now()->addMonths(2)->format('Y-m-d\TH:i'),
        ]));

        $this->assertSame(25, $coupon->fresh()->limit);
    }
}
