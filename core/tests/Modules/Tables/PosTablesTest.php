<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T3-1: the register's table floor-state endpoint. Statuses derive from live
 * orders (occupied / served / vacant), scoped to the tenant.
 */
class PosTablesTest extends TestCase
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
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$r, $staff];
    }

    private function table(Store $r, int $no): StoreTable
    {
        $t = new StoreTable;
        $t->forceFill(['store_id' => $r->id, 'table_no' => $no, 'label' => "T$no", 'seats' => 4, 'status' => 'free'])->save();

        return $t;
    }

    private function orderAt(Store $r, int $tableNo, string $status): Order
    {
        $o = new Order;
        $o->forceFill([
            'store_id' => $r->id, 'type' => 'on-table', 'table_number' => $tableNo,
            'status' => $status, 'is_paid' => 0, 'held_at' => null, 'created_at' => now(),
        ])->save();

        return $o;
    }

    private function tableWith(Store $r, int $no, string $storedStatus): StoreTable
    {
        $t = new StoreTable;
        $t->forceFill(['store_id' => $r->id, 'table_no' => $no, 'label' => "T$no", 'seats' => 4, 'status' => $storedStatus])->save();

        return $t;
    }

    /**
     * Gap 1 — the register honours the table's stored `status` column (Floor Map
     * source of truth), not just order-derived occupancy: dirty/cleaning → blocked,
     * seated/ordering/food → occupied, bill → served, free → order-derived/vacant.
     */
    public function test_stored_table_status_maps_to_pos_state(): void
    {
        [$r, $staff] = $this->storeFixture();
        $this->tableWith($r, 1, 'free');     // no order → vacant
        $this->tableWith($r, 2, 'dirty');    // → blocked
        $this->tableWith($r, 3, 'cleaning'); // → blocked
        $this->tableWith($r, 4, 'seated');   // → occupied (no order yet)
        $this->tableWith($r, 5, 'bill');     // → served
        $this->tableWith($r, 6, 'free');     // free + live order → occupied
        $this->orderAt($r, 6, 'new');

        $byNo = collect(
            $this->actingAs($staff, 'staff')
                ->getJson(route('pos.tables', ['store' => $r->id]))->assertOk()->json()
        )->keyBy('table_no');

        $this->assertSame('vacant', $byNo[1]['status']);
        $this->assertSame('blocked', $byNo[2]['status']);
        $this->assertSame('blocked', $byNo[3]['status']);
        $this->assertSame('occupied', $byNo[4]['status']);
        $this->assertSame('served', $byNo[5]['status']);
        $this->assertSame('occupied', $byNo[6]['status']);
    }

    public function test_imminent_reservation_marks_a_vacant_table_reserved(): void
    {
        [$r, $staff] = $this->storeFixture();
        $this->tableWith($r, 7, 'free'); // vacant + booked soon → reserved
        $this->tableWith($r, 8, 'free'); // vacant + booking far off → stays vacant
        Reservation::create([
            'store_id' => $r->id, 'name' => 'Vip Guest', 'party_size' => 2,
            'reserved_at' => now()->addHour(), 'table_number' => '7', 'status' => 'confirmed',
        ]);
        Reservation::create([
            'store_id' => $r->id, 'name' => 'Later', 'party_size' => 2,
            'reserved_at' => now()->addHours(6), 'table_number' => '8', 'status' => 'confirmed',
        ]);

        $byNo = collect(
            $this->actingAs($staff, 'staff')
                ->getJson(route('pos.tables', ['store' => $r->id]))->assertOk()->json()
        )->keyBy('table_no');

        $this->assertSame('reserved', $byNo[7]['status']);
        $this->assertSame('Vip Guest', $byNo[7]['reserved_name']);
        $this->assertSame('vacant', $byNo[8]['status']);
    }

    public function test_statuses_match_orders(): void
    {
        [$r, $staff] = $this->storeFixture();
        $this->table($r, 1); // vacant (no order)
        $this->table($r, 2); // occupied (new order)
        $this->table($r, 3); // served (ready order)
        $this->orderAt($r, 2, 'new');
        $served = $this->orderAt($r, 3, 'ready');

        $res = $this->actingAs($staff, 'staff')
            ->getJson(route('pos.tables', ['store' => $r->id]))
            ->assertOk();

        $byNo = collect($res->json())->keyBy('table_no');
        $this->assertSame('vacant', $byNo[1]['status']);
        $this->assertSame('occupied', $byNo[2]['status']);
        $this->assertSame('served', $byNo[3]['status']);
        $this->assertSame($served->id, $byNo[3]['order_id']);
        $this->assertNull($byNo[1]['order_id']);
    }

    public function test_tables_are_tenant_scoped(): void
    {
        [$rA, $staffA] = $this->storeFixture();
        $this->table($rA, 1);
        [$rB, $staffB] = $this->storeFixture();
        $this->table($rB, 9);

        $res = $this->actingAs($staffB, 'staff')
            ->getJson(route('pos.tables', ['store' => $rB->id]))
            ->assertOk();

        $nos = collect($res->json())->pluck('table_no')->all();
        $this->assertSame([9], $nos); // only B's table, not A's
    }

    private function tableB(Store $r, int $no, ?int $branchId): StoreTable
    {
        $t = new StoreTable;
        $t->forceFill([
            'store_id' => $r->id, 'table_no' => $no, 'label' => "T$no",
            'seats' => 4, 'status' => 'free', 'branch_id' => $branchId,
        ])->save();

        return $t;
    }

    /**
     * ScopesToBranch parity: legacy NULL-branch tables belong to the DEFAULT branch
     * only; a non-default branch never sees NULL or other-branch tables. Matches the
     * dashboard Floor Map + Staff App so the register's table set can't drift.
     */
    public function test_tables_follow_scopes_to_branch_rule(): void
    {
        [$r, $staff] = $this->storeFixture();
        // The default branch is the first (lowest id) — a Post may auto-create one, so
        // resolve it rather than assume, then add a strictly non-default branch.
        Branch::firstOrCreate(['store_id' => $r->id, 'name' => 'B1'], ['active' => 1, 'position' => 1]);
        $b1 = (int) Branch::where('store_id', $r->id)->orderBy('id')->value('id'); // default
        $b2 = (int) Branch::create(['store_id' => $r->id, 'name' => 'B2', 'active' => 1, 'position' => 2])->id;

        $this->tableB($r, 1, $b1);  // default branch
        $this->tableB($r, 2, null); // legacy NULL → default branch only
        $this->tableB($r, 3, $b2);  // branch 2

        $nosFor = function ($branchId) use ($r, $staff) {
            return collect(
                $this->actingAs($staff, 'staff')
                    ->getJson(route('pos.tables', ['store' => $r->id, 'branch' => $branchId]))
                    ->assertOk()->json()
            )->pluck('table_no')->sort()->values()->all();
        };

        // Non-default branch: strictly its own table — no NULL, no default's.
        $this->assertSame([3], $nosFor($b2));
        // Default branch: its table + the legacy NULL table, never branch 2's.
        $this->assertSame([1, 2], $nosFor($b1));
    }
}
