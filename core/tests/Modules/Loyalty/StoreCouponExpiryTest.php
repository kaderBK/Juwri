<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\StoreOption;
use App\Services\CouponService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Http\Controllers\User\StoreCouponController;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-216: a date-only owner coupon expiry ends at the end of that day in the store's
 * timezone, not at UTC midnight. Riyadh is UTC+3 with no DST, so 23:59 on 20 Oct
 * there is 20:59 UTC on 20 Oct.
 */
class StoreCouponExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        // BL-036 C7: the form refuses a past expiry, so the fixed 20 Oct dates below are created before that day.
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', 'UTC'));
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'timezone', 'Asia/Riyadh');

        return $owner;
    }

    private function createCoupon(User $owner, string $expiryDate): Coupon
    {
        $this->actingAs($owner)->post(route('stores.coupons.store'), [
            'code' => 'WELCOME', 'coupon_type' => 'percentage', 'percentage' => 10, 'expiry_at' => $expiryDate,
        ])->assertRedirect(route('stores.coupons.index'));

        return Coupon::where('code', 'WELCOME')->firstOrFail();
    }

    private function resolve(User $owner): array
    {
        return app(CouponService::class)->resolve('welcome', [
            'surface' => 'food_order', 'owner_id' => $owner->id, 'subtotal' => 100,
        ]);
    }

    public function test_date_only_expiry_is_stored_as_the_end_of_that_store_day(): void
    {
        $owner = $this->owner();

        $coupon = $this->createCoupon($owner, '2026-10-20');

        $this->assertSame('2026-10-20 20:59:59', $coupon->expiry_at->format('Y-m-d H:i:s'));
    }

    public function test_coupon_is_valid_at_2359_store_time_on_its_expiry_day_and_expired_the_next_day(): void
    {
        $owner = $this->owner();
        $this->createCoupon($owner, '2026-10-20');

        Carbon::setTestNow(Carbon::parse('2026-10-20 20:59:00', 'UTC')); // 23:59 on 20 Oct in Riyadh
        $this->assertTrue($this->resolve($owner)['ok']);

        Carbon::setTestNow(Carbon::parse('2026-10-20 21:00:00', 'UTC')); // 00:00 on 21 Oct in Riyadh
        $this->assertFalse($this->resolve($owner)['ok']);
    }

    public function test_edit_form_shows_the_same_expiry_date_back(): void
    {
        $owner = $this->owner();
        $coupon = $this->createCoupon($owner, '2026-10-20');

        $this->actingAs($owner);
        $view = (new StoreCouponController)->edit($coupon);

        $this->assertSame('2026-10-20', $view->getData()['expiryDate']);
    }
}
