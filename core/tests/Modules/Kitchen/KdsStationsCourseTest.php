<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\BranchMenuStation;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Kitchen\Http\Controllers\User\KdsController;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Services\Waiter\WaiterService;
use Tests\Support\TestPlans;
use Tests\TestCase;

class KdsStationsCourseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'staff_limit' => 99]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeItem(Store $r, array $a = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        return Menu::create(array_merge(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item '.Str::random(4), 'price' => 5, 'active' => '1'], $a));
    }

    /** Station create with branch/target/expo */
    public function test_station_create_with_advanced_fields(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();

        $this->actingAs($owner)->post(route('stations.store'), [
            'store_id' => $r->id, 'name' => 'Grill', 'target_minutes' => 12, 'is_expo' => '1', 'branch_id' => $branch->id,
        ])->assertRedirect();

        $st = KitchenStation::where('store_id', $r->id)->first();
        $this->assertEquals(12, $st->target_minutes);
        $this->assertTrue($st->is_expo);
        $this->assertEquals($branch->id, $st->branch_id);
    }

    /** Per-branch assignment writes the pivot; routing then uses it */
    public function test_per_branch_assignment_pivot(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $grill = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => true]);
        $bar = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar', 'active' => true]);
        $item = $this->makeItem($r, ['station_id' => $grill->id]);

        $this->actingAs($owner)->post(route('stations.assign'), [
            'menu_id' => $item->id, 'station_id' => $bar->id, 'branch_id' => $branch->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('item_kitchen_stations', ['branch_id' => $branch->id, 'menu_id' => $item->id, 'station_id' => $bar->id]);
        $this->assertEquals($bar->id, KdsService::resolveStationForOrderItem($branch->id, $item->fresh()));

        // Clearing (station '') removes the pivot → falls back to menu default
        $this->actingAs($owner)->post(route('stations.assign'), [
            'menu_id' => $item->id, 'station_id' => '', 'branch_id' => $branch->id,
        ])->assertRedirect();
        $this->assertDatabaseMissing('item_kitchen_stations', ['branch_id' => $branch->id, 'menu_id' => $item->id]);
    }

    /** Course firing: course 1 fires immediately, later courses held until fired */
    public function test_course_firing(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => 'A', 'status' => 'new', 'created_at' => now()]);
        $m = $this->makeItem($r);
        // course 1 (fired) + course 2 (held)
        $i1 = OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued', 'course' => 1, 'fired_at' => now()]);
        $i2 = OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued', 'course' => 2, 'fired_at' => null]);

        $this->assertNull($i2->fresh()->fired_at);

        $this->actingAs($owner)->post(route('kds.fireCourse', [$order->id, 2]))->assertOk()->assertJson(['fired' => 1]);
        $this->assertNotNull($i2->fresh()->fired_at);
        // Firing again fires nothing (already fired)
        $this->actingAs($owner)->post(route('kds.fireCourse', [$order->id, 2]))->assertJson(['fired' => 0]);
    }

    /** K1: a waiter-added item must honour the per-branch station override, not the menu default. */
    public function test_waiter_added_item_uses_branch_station_override(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $grill = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => true]);
        $bar = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar', 'active' => true]);
        $item = $this->makeItem($r, ['station_id' => $grill->id]); // default station = Grill
        BranchMenuStation::create(['branch_id' => $branch->id, 'menu_id' => $item->id, 'station_id' => $bar->id]); // this branch → Bar

        $order = Order::create(['store_id' => $r->id, 'branch_id' => $branch->id, 'type' => 'on-table', 'status' => 'new', 'created_at' => now()]);
        $created = new WaiterService()->addItems($order, [['item_id' => $item->id, 'quantity' => 1]]);

        $this->assertCount(1, $created);
        $this->assertEquals($bar->id, (int) $created[0]->station_id, 'tableside addition must route to the branch override (Bar), not the menu default (Grill)');
    }

    /** K2: the KDS poll must not surface held (unfired) course lines to the kitchen board. */
    public function test_poll_hides_held_course_items(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $m = $this->makeItem($r);
        $order = Order::create(['store_id' => $r->id, 'branch_id' => $branch->id, 'type' => 'on-table', 'customer_name' => 'A', 'status' => 'preparing', 'created_at' => now()]);
        $fired = OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued', 'course' => 1, 'fired_at' => now()]);
        $held = OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'variation' => 0, 'status' => 'held', 'course' => 2, 'fired_at' => null]);

        $data = $this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $r->id]))->assertOk()->json();
        $orderJson = collect($data)->firstWhere('id', $order->id);
        $this->assertNotNull($orderJson, 'the preparing order must appear on the board');
        $itemIds = collect($orderJson['items'])->pluck('id')->all();

        $this->assertContains($fired->id, $itemIds, 'the fired course must be on the board');
        $this->assertNotContains($held->id, $itemIds, 'a held (unfired) course must NOT be on the board');
    }

    /** Staff can be pinned to a station */
    public function test_staff_station_assignment(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $st = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => true]);

        $this->actingAs($owner)->post(route('staff.store'), [
            'name' => 'Cook', 'email' => 'cook'.Str::random(4).'@x.com', 'password' => 'secret1',
            'role' => 'kitchen', 'store_id' => $r->id, 'station_id' => $st->id,
        ])->assertRedirect();

        $staff = StaffMember::where('email', 'like', 'cook%')->first();
        $this->assertEquals($st->id, $staff->station_id);
    }

    /** Analytics report aggregates prep times (route authorises owner only) */
    public function test_analytics_authorises_and_renders(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $intruder = $this->makeUser();
        $this->actingAs($intruder)->get(route('kds.analytics', ['store_id' => $r->id]))->assertForbidden();

        // Build a completed ticket with item timings
        $st = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => true, 'target_minutes' => 10]);
        $m = $this->makeItem($r, ['station_id' => $st->id]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => 'A', 'status' => 'ready', 'created_at' => now()->subMinutes(8), 'ready_at' => now()->subMinutes(2)]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'variation' => 0, 'status' => 'ready', 'station_id' => $st->id, 'fired_at' => now()->subMinutes(8), 'started_at' => now()->subMinutes(7), 'item_ready_at' => now()->subMinutes(2)]);

        $controller = new KdsController;
        $this->actingAs($owner);
        $req = Request::create(route('kds.analytics'), 'GET', ['store_id' => $r->id, 'days' => 7]);
        $data = $controller->analytics($req)->getData();
        $this->assertNotNull($data['report']);
        $this->assertEquals(1, $data['report']['orders']);
        $this->assertGreaterThan(0, $data['report']['avgPrep']);
    }
}
