<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Coupons C6: POS settle records payment_method='coupon' when a coupon leaves 0 due
 * (whatever tender method was sent), refuses 'coupon' when anything is still owed,
 * and leaves normal cash settle behaviour unchanged.
 */
class PosCouponSettleTest extends TestCase
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

    /** @return array{0: Store, 1: StaffMember, 2: User, 3: Menu} */
    private function storeFixture(float $price = 10.0): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5),
            'description' => 'T',
            'monthly_price' => 1,
            'annual_price' => 1,
            'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111',
            'title' => $title,
            'sub_title' => 'T',
            'description' => 'T',
            'main_image' => 'd.png',
            'cover_image' => 'd.png',
            'address' => '1 St',
            'phone' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
        $staff = StaffMember::create([
            'owner_id' => $store->user_id,
            'store_id' => $store->id,
            'name' => 'S '.Str::random(4),
            'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'),
            'role' => 'cashier',
            'active' => 1,
        ]);

        return [$store, $staff, $owner, $item];
    }

    private function ownerCoupon(User $owner, string $code, float $pct): Coupon
    {
        return Coupon::create([
            'code' => $code,
            'percentage' => $pct,
            'limit' => 5,
            'used' => 0,
            'user_id' => $owner->id,
            'coupon_type' => 'percentage',
            'issuer_scope' => 'owner',
            'applies_to' => 'food_order',
        ]);
    }

    public function test_couponed_order_with_zero_due_records_coupon_payment_method_regardless_of_sent_method(): void
    {
        [$store, $staff, $owner, $item] = $this->storeFixture(10.0);
        $this->ownerCoupon($owner, 'FREE100', 100);

        foreach (['cash', 'card', 'coupon'] as $sentMethod) {
            $order = PosService::createOrder($store, $staff, [
                'type' => 'takeaway',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ]);

            $this->actingAs($staff, 'staff')
                ->postJson(route('pos.orders.coupon', ['id' => $order->id, 'store' => $store->id]), ['coupon_code' => 'FREE100'])
                ->assertOk();

            $payload = ['method' => $sentMethod];
            if ($sentMethod === 'cash') {
                $payload['tendered'] = 10;
            }

            $this->actingAs($staff, 'staff')
                ->postJson(route('pos.orders.settle', ['id' => $order->id, 'store' => $store->id]), $payload)
                ->assertOk();

            $settled = $order->fresh();
            $this->assertTrue((bool) $settled->is_paid);
            $this->assertSame('coupon', $settled->payment_method);
            $this->assertNull($settled->amount_tendered);
            $this->assertNull($settled->change_due);
        }
    }

    public function test_coupon_method_rejected_when_order_still_owes_money(): void
    {
        [$store, $staff, $owner, $item] = $this->storeFixture(10.0);
        $this->ownerCoupon($owner, 'HALF50', 50);

        $unpaidOrder = PosService::createOrder($store, $staff, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.settle', ['id' => $unpaidOrder->id, 'store' => $store->id]), ['method' => 'coupon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
        $this->assertFalse((bool) $unpaidOrder->fresh()->is_paid);

        $partialOrder = PosService::createOrder($store, $staff, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.coupon', ['id' => $partialOrder->id, 'store' => $store->id]), ['coupon_code' => 'HALF50'])
            ->assertOk();

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.settle', ['id' => $partialOrder->id, 'store' => $store->id]), ['method' => 'coupon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
        $this->assertFalse((bool) $partialOrder->fresh()->is_paid);
    }

    public function test_cash_settle_of_normal_order_is_unchanged(): void
    {
        [$store, $staff, , $item] = $this->storeFixture(12.0);
        $order = PosService::createOrder($store, $staff, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.settle', ['id' => $order->id, 'store' => $store->id]), [
                'method' => 'cash',
                'tendered' => 20,
            ])
            ->assertOk();

        $settled = $order->fresh();
        $this->assertTrue((bool) $settled->is_paid);
        $this->assertSame('cash', $settled->payment_method);
        $this->assertEquals(20, (float) $settled->amount_tendered);
        $this->assertEquals(8, (float) $settled->change_due);
    }
}
