<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
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

/** T5-6: POS split payment via the shared SplitBillService — 2-way cash+card settles. */
class PosSplitTest extends TestCase
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

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$r, $staff, $item];
    }

    public function test_two_way_cash_card_split_settles_order(): void
    {
        [$r, $staff, $item] = $this->storeFixture();
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]], // total 20
        ]);

        $split = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.split', ['id' => $order->id, 'store' => $r->id]), ['parts' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'shares');
        $shares = $split->json('shares');

        // Pay share 1 cash — order still unpaid.
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.splitPay', ['id' => $order->id, 'store' => $r->id]), ['share_id' => $shares[0]['id'], 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('is_paid', false);

        // Pay share 2 card — now settled.
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.splitPay', ['id' => $order->id, 'store' => $r->id]), ['share_id' => $shares[1]['id'], 'method' => 'card'])
            ->assertOk()
            ->assertJsonPath('is_paid', true);

        $this->assertSame(1, (int) $order->fresh()->is_paid);
    }

    public function test_split_is_tenant_scoped(): void
    {
        [$rA, , $itemA] = $this->storeFixture();
        $orderA = OrderApiService::create($rA->user_id, $rA->id, ['type' => 'takeaway', 'items' => [['item_id' => $itemA->id, 'quantity' => 1]]]);
        [$rB, $staffB] = $this->storeFixture();

        // Owner B's cashier cannot split owner A's order.
        $this->actingAs($staffB, 'staff')
            ->postJson(route('pos.orders.split', ['id' => $orderA->id, 'store' => $rB->id]), ['parts' => 2])
            ->assertNotFound();
    }
}
