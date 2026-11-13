<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T1-3: the register's read-only coupon-check endpoint. Reuses the shared
 * CouponService resolver, tenant-scoped to the active restaurant's owner, with no
 * side effects (never increments `used`).
 */
class PosCouponCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(?User $owner = null): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = $owner ?: User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$r, $staff, $owner];
    }

    private function ownerCoupon(User $owner, string $code, float $pct): Coupon
    {
        return Coupon::create([
            'code' => $code, 'percentage' => $pct, 'limit' => 5, 'used' => 0,
            'user_id' => $owner->id, 'coupon_type' => 'percentage',
            'issuer_scope' => 'owner', 'applies_to' => 'food_order',
        ]);
    }

    public function test_valid_owner_coupon_returns_value_without_incrementing(): void
    {
        [$r, $staff, $owner] = $this->storeFixture();
        $coupon = $this->ownerCoupon($owner, 'SAVE20', 20);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.coupon.check', ['store' => $r->id]), ['code' => 'SAVE20', 'subtotal' => 50])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('code', 'SAVE20')
            ->assertJsonPath('value', 10); // 20% of 50

        $this->assertSame(0, (int) $coupon->fresh()->used, 'check must not consume the coupon');
    }

    public function test_coupon_is_tenant_isolated(): void
    {
        [$rA, $staffA, $ownerA] = $this->storeFixture();
        $this->ownerCoupon($ownerA, 'AONLY', 15);
        [$rB, $staffB] = $this->storeFixture();

        // Owner B's cashier cannot validate owner A's coupon.
        $this->actingAs($staffB, 'staff')
            ->postJson(route('pos.coupon.check', ['store' => $rB->id]), ['code' => 'AONLY', 'subtotal' => 50])
            ->assertOk()
            ->assertJsonPath('valid', false);
    }

    public function test_unknown_code_is_invalid(): void
    {
        [$r, $staff] = $this->storeFixture();

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.coupon.check', ['store' => $r->id]), ['code' => 'NOPE', 'subtotal' => 50])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonStructure(['valid', 'message']);
    }

    public function test_settle_time_coupon_applies_to_existing_order(): void
    {
        [$r, $staff, $owner] = $this->storeFixture();
        $this->ownerCoupon($owner, 'SAVE20', 20);
        $item = Menu::where('store_id', $r->id)->first();

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 5]], // subtotal 50
        ]);
        $before = (float) $order->total;

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.coupon', ['id' => $order->id, 'store' => $r->id]), ['coupon_code' => 'SAVE20'])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->coupon_id);
        $this->assertEqualsWithDelta($before - 10, (float) $fresh->total, 0.01); // 20% of 50 off

        // Invalid code on a fresh (un-couponed) order → 422.
        $order2 = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.coupon', ['id' => $order2->id, 'store' => $r->id]), ['coupon_code' => 'NOPE'])
            ->assertStatus(422);

        // Paid order → 422.
        $order2->forceFill(['is_paid' => 1])->save();
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.coupon', ['id' => $order2->id, 'store' => $r->id]), ['coupon_code' => 'SAVE20'])
            ->assertStatus(422);
    }
}
