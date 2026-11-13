<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Services\SplitBillService;
use Juwri\Modules\Tables\Services\TableMergeService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T4 — moving ONE open order to another table.
 *
 * The endpoint existed before this track but did three things wrong: it had no
 * audit trail, no guards at all (a settled order, a cross-branch table and a
 * split bill mid-payment were all accepted), and no UI. What it must NOT do is
 * touch money — the assertions below pin `total` and `amount_due` across the
 * move, including the wallet / gift-card TENDERS and the discount.
 */
class TableTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        config(['settings.admin_allow_table_merge' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** @return array{0: Store, 1: StaffMember} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(8).'@t.test', 'password' => bcrypt('x'),
            'role' => 'manager', 'active' => 1,
        ]);

        return [$r, $staff];
    }

    private function table(Store $r, int $no, ?int $branchId = null): StoreTable
    {
        $t = new StoreTable;
        $t->forceFill([
            'store_id' => $r->id, 'table_no' => $no, 'label' => "T$no", 'seats' => 4,
            'status' => 'free', 'branch_id' => $branchId, 'key' => Str::random(10),
        ])->save();

        return $t;
    }

    private function orderAt(Store $r, ?StoreTable $t, float $price = 10.0, array $extra = []): Order
    {
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'I'.Str::random(4), 'price' => $price, 'active' => '1']);
        $o = new Order;
        $o->forceFill(array_merge([
            'store_id' => $r->id, 'type' => $t ? 'on-table' : 'takeaway',
            'table_number' => $t?->table_no, 'status' => 'new', 'is_paid' => 0,
            'branch_id' => $t?->branch_id, 'source' => 'pos',
            'track_token' => Str::random(32), 'created_at' => now(),
        ], $extra))->save();
        OrderItem::create(['order_id' => $o->id, 'item_id' => $menu->id, 'quantity' => 2]);

        return $o->fresh();
    }

    /* ===================================================================== */

    /**
     * A move changes WHERE the order is, never WHAT it costs. Tax, discount,
     * tip, wallet and gift-card tenders must all survive it untouched — this
     * compares `total` and `amount_due` before and after, so any accidental
     * re-pricing in the transfer path fails here.
     */
    public function test_transfer_preserves_payments_and_discounts(): void
    {
        [$r, $staff] = $this->store();
        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '10');

        $from = $this->table($r, 1);
        $to = $this->table($r, 2);
        $order = $this->orderAt($r, $from, 25.0, [
            'discount_amount' => 5,
            'tip_amount' => 3,
            'service_charge_amount' => 2,
            'wallet_applied' => 4,
            'gift_card_applied' => 1.5,
            'coupon_id' => null,
        ]);

        $totalBefore = (float) $order->total;
        $dueBefore = (float) $order->amount_due;

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $r->id]), ['table_no' => $to->table_no])
            ->assertOk();

        $order = $order->fresh();
        $this->assertSame((string) $to->table_no, (string) $order->table_number);
        $this->assertSame(round($totalBefore, 4), round((float) $order->total, 4));
        $this->assertSame(round($dueBefore, 4), round((float) $order->amount_due, 4));
        $this->assertSame(5.0, (float) $order->discount_amount);
        $this->assertSame(4.0, (float) $order->wallet_applied);
        $this->assertSame(1.5, (float) $order->gift_card_applied);
        $this->assertSame(3.0, (float) $order->tip_amount);
        // No new order, no copied lines.
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::count());
    }

    public function test_transfer_writes_an_audit_row(): void
    {
        [$r, $staff] = $this->store();
        $from = $this->table($r, 1);
        $to = $this->table($r, 2);
        $order = $this->orderAt($r, $from);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $r->id]), ['table_no' => $to->table_no])
            ->assertOk();

        $log = ActivityLog::where('action', 'order.transfer')->latest('id')->first();
        $this->assertNotNull($log, 'A transfer must leave a who/when/from/to audit row.');
        $this->assertSame((int) $r->id, (int) $log->store_id);
        $this->assertSame((int) $order->id, (int) $log->meta['order_id']);
        $this->assertSame((string) $from->table_no, (string) $log->meta['from_table_number']);
        $this->assertSame((string) $to->table_no, (string) $log->meta['to_table_number']);
        $this->assertSame((int) $staff->id, (int) $log->meta['staff_id']);
    }

    public function test_a_settled_order_cannot_be_moved(): void
    {
        [$r, $staff] = $this->store();
        $from = $this->table($r, 1);
        $to = $this->table($r, 2);
        $order = $this->orderAt($r, $from, 10.0, ['is_paid' => 1]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $r->id]), ['table_no' => $to->table_no])
            ->assertStatus(422)
            ->assertJson(['message' => 'A settled order cannot be moved.']);

        $this->assertSame((string) $from->table_no, (string) $order->fresh()->table_number);
    }

    public function test_a_cross_branch_move_is_refused(): void
    {
        [$r, $staff] = $this->store();
        $one = Branch::create(['store_id' => $r->id, 'name' => 'One', 'active' => 1, 'position' => 1]);
        $two = Branch::create(['store_id' => $r->id, 'name' => 'Two', 'active' => 1, 'position' => 2]);

        $from = $this->table($r, 1, $one->id);
        $to = $this->table($r, 2, $two->id);
        $order = $this->orderAt($r, $from);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $r->id]), ['table_no' => $to->table_no])
            ->assertStatus(422);

        $this->assertSame((string) $from->table_no, (string) $order->fresh()->table_number);
    }

    public function test_a_split_bill_in_progress_blocks_the_move(): void
    {
        [$r, $staff] = $this->store();
        $from = $this->table($r, 1);
        $to = $this->table($r, 2);
        $order = $this->orderAt($r, $from, 30.0);

        SplitBillService::createEvenSplit($order->fresh(), 3);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $r->id]), ['table_no' => $to->table_no])
            ->assertStatus(422)
            ->assertJson(['message' => 'Finish or cancel the split bill before moving this order.']);
    }

    /** Moving an order onto a merged secondary means "join that party". */
    public function test_moving_onto_a_merged_secondary_lands_on_the_primary(): void
    {
        [$r, $staff] = $this->store();
        $primary = $this->table($r, 1);
        $secondary = $this->table($r, 2);
        $lone = $this->table($r, 3);
        TableMergeService::merge((int) $r->id, (int) $primary->id, [(int) $secondary->id]);

        $order = $this->orderAt($r, $lone);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $r->id]), ['table_no' => $secondary->table_no])
            ->assertOk();

        $order = $order->fresh();
        $this->assertSame((string) $primary->table_no, (string) $order->table_number);
        // …and it remembers which table it really belongs to, so unmerge can
        // put it back where the guests are actually sitting.
        $this->assertSame((int) $secondary->id, (int) $order->merged_from_table_id);
    }

    public function test_a_takeaway_order_becomes_dine_in_when_seated(): void
    {
        [$r, $staff] = $this->store();
        $to = $this->table($r, 4);
        $order = $this->orderAt($r, null);
        $this->assertSame('takeaway', $order->type);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $r->id]), ['table_no' => $to->table_no])
            ->assertOk();

        $order = $order->fresh();
        $this->assertSame('on-table', $order->type);
        $this->assertSame((string) $to->table_no, (string) $order->table_number);
    }

    public function test_another_stores_table_is_not_reachable(): void
    {
        [$rA, $staffA] = $this->store();
        [$rB] = $this->store();
        $from = $this->table($rA, 1);
        $this->table($rB, 9);
        $order = $this->orderAt($rA, $from);

        $this->actingAs($staffA, 'staff')
            ->postJson(route('pos.orders.transfer', ['id' => $order->id, 'store' => $rA->id]), ['table_no' => 9])
            ->assertNotFound();

        $this->assertSame((string) $from->table_no, (string) $order->fresh()->table_number);
    }
}
