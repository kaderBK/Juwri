<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Services\OrderApiService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R1c: an order's subtotal/tax/grand are snapshotted onto columns at create time
 * and the ->total accessor prefers the column (killing the per-item read walk),
 * while legacy NULL-column rows still derive identically.
 */
class OrderTotalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_created_order_snapshots_columns_matching_accessor(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 12.5, 'active' => 1]);

        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $fresh = Order::find($order->id);

        $this->assertNotNull($fresh->grand_total, 'grand_total column must be snapshotted at create.');
        $this->assertNotNull($fresh->subtotal, 'subtotal column must be snapshotted at create.');
        $this->assertEqualsWithDelta(25.0, (float) $fresh->subtotal, 0.001, 'subtotal = 12.5 * 2.');

        // The snapshotted column must equal the derivation for the same order.
        $derived = $fresh->computeMoney();
        $this->assertEqualsWithDelta($derived['grand'], (float) $fresh->grand_total, 0.001);
        $this->assertEqualsWithDelta($derived['grand'], (float) $fresh->total, 0.001);
    }

    public function test_snapshot_is_a_point_in_time_column_for_aggregates(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 3]],
        ]);

        // The column can be SUMmed in SQL without touching the accessor.
        $sum = (float) DB::table('orders')->where('id', $order->id)->sum('grand_total_minor') / 100; // P1-05: USD minor units
        $this->assertEqualsWithDelta(30.0, $sum, 0.001, 'grand_total is SQL-aggregatable.');

        // It is a frozen point-in-time value: the ACCESSOR stays live (an
        // unsnapshotted line re-prices off the menu), so raising the menu price
        // moves ->total but leaves the reporting column at what was charged.
        $item->update(['price' => 20]);
        $fresh = Order::find($order->id);
        $this->assertEqualsWithDelta(60.0, (float) $fresh->total, 0.001, 'accessor re-prices live.');
        $this->assertEqualsWithDelta(30.0, (float) $fresh->grand_total, 0.001, 'column stays the order-time snapshot.');
    }

    public function test_accessor_always_derives_regardless_of_column(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 7.25, 'active' => 1]);
        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 4]],
        ]);

        // A pre-R1c NULL row and a snapshotted row both derive the same live total.
        DB::table('orders')->where('id', $order->id)->update([
            'subtotal_minor' => null, 'tax_total_minor' => null, 'grand_total_minor' => null,
        ]);
        $fresh = Order::find($order->id);
        $this->assertNull($fresh->grand_total);
        $this->assertEqualsWithDelta(29.0, (float) $fresh->total, 0.001, 'NULL column derives 7.25 * 4.');
    }

    public function test_backfill_command_populates_missing_snapshots(): void
    {
        $r = $this->storeFixture();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 5, 'active' => 1]);
        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'subtotal_minor' => null, 'tax_total_minor' => null, 'grand_total_minor' => null,
        ]);

        $this->artisan('orders:snapshot-totals')->assertExitCode(0);

        $fresh = Order::find($order->id);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->grand_total, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->subtotal, 0.001);
    }

    public function test_the_backfill_command_is_scheduled(): void
    {
        // WI-H10: the backfill was invoked from nowhere but a test; it must now run nightly.
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains($e->command ?? '', 'orders:snapshot-totals'));

        $this->assertTrue($scheduled, 'orders:snapshot-totals must be registered in the scheduler.');
    }
}
