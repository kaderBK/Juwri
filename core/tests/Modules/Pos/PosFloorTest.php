<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Models\StoreTable;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** POS P4: live floor occupancy + transfer an order between tables. */
class PosFloorTest extends TestCase
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

    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'C',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        StoreTable::create(['store_id' => $r->id, 'table_no' => '1', 'key' => Str::random(8), 'seats' => 4]);
        StoreTable::create(['store_id' => $r->id, 'table_no' => '2', 'key' => Str::random(8), 'seats' => 2]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$r, $staff, $item];
    }

    public function test_floor_reports_free_and_occupied_tables(): void
    {
        [$r, $staff, $item] = $this->make();
        // Seat table 1.
        PosService::createOrder($r, $staff, ['type' => 'on-table', 'table_number' => '1', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);

        $res = $this->actingAs($staff, 'staff')->getJson(route('pos.floor'))->assertOk();
        $byNo = collect($res->json())->keyBy('table_no');

        $this->assertSame('occupied', $byNo['1']['state']);
        $this->assertNotNull($byNo['1']['order_id']);
        $this->assertSame('free', $byNo['2']['state']);
    }

    public function test_transfer_moves_order_to_another_table(): void
    {
        [$r, $staff, $item] = $this->make();
        $order = PosService::createOrder($r, $staff, ['type' => 'on-table', 'table_number' => '1', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', $order->id), ['table_no' => '2'])
            ->assertOk();

        $this->assertSame(2, (int) $order->fresh()->table_number);
    }

    public function test_transfer_rejects_unknown_table(): void
    {
        [$r, $staff, $item] = $this->make();
        $order = PosService::createOrder($r, $staff, ['type' => 'on-table', 'table_number' => '1', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', $order->id), ['table_no' => '999'])
            ->assertStatus(404);
    }
}
