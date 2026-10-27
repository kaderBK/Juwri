<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Http\Controllers\User\StoreCouponController;
use Tests\TestCase;

/**
 * WI-C5: the merchant's own coupon page surfaces (a) how many diners were refused
 * an exhausted coupon today ("Declined today" tile) and (b) a "Used up" badge on
 * any code at its limit — so a spent single-use coupon reads as spent, not as "the
 * discount silently stopped working".
 */
class StoreCouponPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals();
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(1000, 9999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);

        return $owner;
    }

    public function test_index_passes_todays_decline_count_and_coupons(): void
    {
        // The owner dashboard LAYOUT composers only register under APP_INSTALLED
        // (unset in tests), so we assert the controller's view DATA rather than the
        // rendered shell: the "Declined today" tile reads `deniedToday`, and the
        // "Used up" badge is pure blade over used/limit (compiled by view:cache).
        $owner = $this->owner();
        Coupon::create(['code' => 'SPENT', 'percentage' => 50, 'limit' => 1, 'used' => 1, 'coupon_type' => 'percentage', 'user_id' => $owner->id, 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth()]);
        TenantUsageDaily::bump($owner->id, 'coupon_claim_denied', 3);
        // Another tenant's declines must not leak into this owner's count.
        TenantUsageDaily::bump($owner->id + 999, 'coupon_claim_denied', 5);

        $this->actingAs($owner);
        $view = (new StoreCouponController)->index();
        $data = $view->getData();

        $this->assertSame(3, $data['deniedToday']);
        $this->assertTrue($data['coupons']->contains(fn ($c) => $c->code === 'SPENT'));
    }

    public function test_store_with_an_empty_limit_saves_the_unlimited_sentinel(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('stores.coupons.store'), [
            'code' => 'NOLIMIT', 'coupon_type' => 'percentage', 'percentage' => 10, 'limit' => '',
        ])->assertRedirect(route('stores.coupons.index'));

        $this->assertSame(0, (int) DB::table('coupons')->where('code', 'NOLIMIT')->value('limit'));
    }

    public function test_a_zero_limit_coupon_past_any_used_count_still_resolves_and_claims_atomically(): void
    {
        $owner = $this->owner();
        $coupon = Coupon::create([
            'code' => 'FOREVER', 'percentage' => 10, 'limit' => 0, 'used' => 10000,
            'coupon_type' => 'percentage', 'user_id' => $owner->id,
        ]);

        $result = (new CouponService)->resolve('FOREVER', [
            'surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 100,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue(CouponService::claimUsage($coupon));
        $this->assertSame(10001, (int) $coupon->fresh()->used);
    }

    public function test_index_shows_unlimited_and_the_edit_form_leaves_the_limit_input_empty(): void
    {
        $owner = $this->owner();
        $coupon = Coupon::create([
            'code' => 'ALWAYS', 'percentage' => 10, 'limit' => 0, 'used' => 3,
            'coupon_type' => 'percentage', 'user_id' => $owner->id,
        ]);

        $this->actingAs($owner);
        $this->get(route('stores.coupons.index'))
            ->assertOk()
            ->assertSee(___('Unlimited'));
        $html = $this->get(route('stores.coupons.edit', $coupon))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/id="cpf-limit"[^>]*\bvalue=""/', (string) $html);
    }

    public function test_index_marks_the_legacy_9999_default(): void
    {
        $owner = $this->owner();
        Coupon::create([
            'code' => 'LEGACY', 'percentage' => 10, 'limit' => 9999, 'used' => 2,
            'coupon_type' => 'percentage', 'user_id' => $owner->id,
        ]);

        $this->actingAs($owner);
        $this->get(route('stores.coupons.index'))
            ->assertOk()
            ->assertSee('9999')
            ->assertSee('('.___('default').')');
    }
}
