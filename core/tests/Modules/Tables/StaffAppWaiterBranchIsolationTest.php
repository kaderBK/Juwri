<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * The waiter tableside WRITE endpoints (add-items / fire / split / merge) enforced only
 * store_id, while every sibling write endpoint (status/request-bill/transfer/kds)
 * also calls orderIsInStaffBranch(). So a branch-pinned waiter could add items to / fire /
 * merge an order belonging to another branch of the same restaurant in their default
 * context. These endpoints must uphold the same branch boundary.
 */
class StaffAppWaiterBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        config(['settings.admin_allow_waiter_mode' => true]);
    }

    /** @return array{0:Store,1:Branch,2:Branch,3:StaffMember,4:Menu} */
    private function scenario(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchA = Branch::create(['store_id' => $store->id, 'name' => 'A', 'active' => 1]); // default (first)
        $branchB = Branch::create(['store_id' => $store->id, 'name' => 'B', 'active' => 1]);
        // Waiter pinned to branch B.
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'branch_id' => $branchB->id,
            'name' => 'W', 'role' => 'waiter', 'email' => 'w'.Str::random(5).'@ex.com',
            'password' => Hash::make('secret'), 'active' => 1,
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 20, 'active' => 1]);

        return [$store, $branchA, $branchB, $staff, $menu];
    }

    public function test_waiter_cannot_add_items_to_another_branch_order(): void
    {
        [$store, $branchA, , $staff, $menu] = $this->scenario();
        $orderA = Order::create(['store_id' => $store->id, 'branch_id' => $branchA->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$orderA->id}/items", [
                'items' => [['item_id' => $menu->id, 'quantity' => 1]],
            ])
            ->assertStatus(404);

        $this->assertSame(0, OrderItem::where('order_id', $orderA->id)->count());
    }

    public function test_waiter_can_add_items_to_own_branch_order(): void
    {
        [$store, , $branchB, $staff, $menu] = $this->scenario();
        $orderB = Order::create(['store_id' => $store->id, 'branch_id' => $branchB->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '6']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$orderB->id}/items", [
                'items' => [['item_id' => $menu->id, 'quantity' => 1]],
            ])
            ->assertStatus(201);

        $this->assertSame(1, OrderItem::where('order_id', $orderB->id)->count());
    }

    public function test_waiter_cannot_fire_a_course_on_another_branch_order(): void
    {
        [$store, $branchA, , $staff, $menu] = $this->scenario();
        $orderA = Order::create(['store_id' => $store->id, 'branch_id' => $branchA->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5']);
        OrderItem::create(['order_id' => $orderA->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'status' => 'held', 'course' => 2]);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$orderA->id}/fire", ['course' => 2])
            ->assertStatus(404);

        $this->assertSame('held', OrderItem::where('order_id', $orderA->id)->first()->status);
    }

    public function test_waiter_cannot_merge_across_branches(): void
    {
        [$store, $branchA, $branchB, $staff, $menu] = $this->scenario();
        // Source in the waiter's own branch, target in another branch.
        $src = Order::create(['store_id' => $store->id, 'branch_id' => $branchB->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '6']);
        $dst = Order::create(['store_id' => $store->id, 'branch_id' => $branchA->id, 'type' => 'on-table', 'status' => 'pending', 'table_number' => '5']);
        OrderItem::create(['order_id' => $src->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/waiter/orders/{$src->id}/merge", ['into_order_id' => $dst->id])
            ->assertStatus(404);

        // Nothing moved, source untouched.
        $this->assertSame(1, OrderItem::where('order_id', $src->id)->count());
        $this->assertSame('pending', $src->fresh()->status);
    }
}
