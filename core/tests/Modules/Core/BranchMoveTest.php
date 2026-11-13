<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Core\Services\Tenancy\BranchMove;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-341: moving a branch to another store of the same owner. Built on the P6-325 classification
 * ({@see BranchMove}) so there is ONE list of branch_id tables; every one gets a move rule here
 * (REKEY / DROP / STAY / BLOCK). Move happens in ONE transaction.
 */
class BranchMoveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $storeA;

    private Store $storeB;

    private Branch $mainA;

    private Branch $downtown;

    private Branch $mainB;

    private OwnershipTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();

        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $this->owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->storeA = $this->newStore($this->owner, 'A');
        $this->storeB = $this->newStore($this->owner, 'B');
        $this->mainA = Branch::where('store_id', $this->storeA->id)->orderBy('id')->firstOrFail();
        $this->mainB = Branch::where('store_id', $this->storeB->id)->orderBy('id')->firstOrFail();
        $this->downtown = Branch::create(['store_id' => $this->storeA->id, 'name' => 'Downtown', 'active' => true]);

        $this->service = app(OwnershipTransferService::class);
    }

    private function newStore(User $owner, string $tag): Store
    {
        $title = 'R '.Str::random(6).' '.$tag;

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertStoreTables(int $branchId, int $count = 1, ?int $storeId = null, ?int $accountId = null): array
    {
        $storeId = $storeId ?? $this->storeA->id;
        $accountId = $accountId ?? $this->storeA->account_id;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('store_tables')->insertGetId([
                'account_id' => $accountId, 'store_id' => $storeId, 'branch_id' => $branchId,
                'table_no' => 100 + $i, 'shape' => 'square', 'seats' => 4, 'status' => 'free',
            ]);
        }

        return $ids;
    }

    private function insertReservations(int $branchId, int $count = 1, ?int $storeId = null, ?int $accountId = null): array
    {
        $storeId = $storeId ?? $this->storeA->id;
        $accountId = $accountId ?? $this->storeA->account_id;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('reservations')->insertGetId([
                'account_id' => $accountId, 'store_id' => $storeId, 'branch_id' => $branchId,
                'name' => 'R'.Str::random(4), 'reserved_at' => now()->addDay(), 'party_size' => 2,
            ]);
        }

        return $ids;
    }

    private function insertStaffMembers(int $branchId, int $count = 1, ?int $storeId = null, ?int $accountId = null): array
    {
        $storeId = $storeId ?? $this->storeA->id;
        $accountId = $accountId ?? $this->storeA->account_id;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('staff_members')->insertGetId([
                'account_id' => $accountId, 'owner_id' => $this->owner->id, 'store_id' => $storeId,
                'branch_id' => $branchId, 'name' => 'S', 'email' => Str::random(8).'@x.test', 'password' => 'x',
                'role' => 'waiter', 'active' => 1,
            ]);
        }

        return $ids;
    }

    private function insertStaffTokens(array $staffIds, ?int $storeId = null, ?int $accountId = null): array
    {
        $storeId = $storeId ?? $this->storeA->id;
        $accountId = $accountId ?? $this->storeA->account_id;
        $ids = [];
        foreach ($staffIds as $sid) {
            $ids[] = DB::table('staff_tokens')->insertGetId([
                'account_id' => $accountId, 'store_id' => $storeId, 'staff_id' => $sid,
                'name' => 'tok', 'token' => Str::random(40),
            ]);
        }

        return $ids;
    }

    private function insertPosTerminals(int $branchId, int $count = 1, ?int $storeId = null, ?int $accountId = null): array
    {
        $storeId = $storeId ?? $this->storeA->id;
        $accountId = $accountId ?? $this->storeA->account_id;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('pos_terminals')->insertGetId([
                'account_id' => $accountId, 'store_id' => $storeId, 'branch_id' => $branchId,
                'name' => 'T'.Str::random(3),
            ]);
        }

        return $ids;
    }

    private function insertPrinterProfiles(int $branchId, int $count = 1, ?int $storeId = null, ?int $accountId = null): array
    {
        $storeId = $storeId ?? $this->storeA->id;
        $accountId = $accountId ?? $this->storeA->account_id;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('printer_profiles')->insertGetId([
                'account_id' => $accountId, 'store_id' => $storeId, 'branch_id' => $branchId,
                'name' => 'P'.Str::random(3), 'transport' => 'browser',
            ]);
        }

        return $ids;
    }

    private function insertWaitlistEntries(int $branchId, int $count = 1, ?int $storeId = null, ?int $accountId = null): array
    {
        $storeId = $storeId ?? $this->storeA->id;
        $accountId = $accountId ?? $this->storeA->account_id;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('waitlist_entries')->insertGetId([
                'account_id' => $accountId, 'store_id' => $storeId, 'branch_id' => $branchId,
                'name' => 'W'.Str::random(3), 'party_size' => 2, 'status' => 'waiting',
            ]);
        }

        return $ids;
    }

    private function makeTransfer(): StoreTransfer
    {
        return StoreTransfer::create([
            'kind' => 'branch',
            'token' => StoreTransfer::newToken(),
            'from_user_id' => $this->owner->id,
            'store_id' => $this->storeA->id,
            'branch_id' => $this->downtown->id,
            'target_store_id' => $this->storeB->id,
            'status' => 'pending',
        ]);
    }

    public function test_clean_move_rekeys_drop_rows_to_the_target_store_and_drops_per_branch_rows(): void
    {
        $tables = ['store_tables', 'reservations', 'pos_terminals', 'printer_profiles', 'waitlist_entries'];
        $this->assertTrue(Schema::hasTable('staff_tokens'), 'staff_tokens table required');
        $this->assertTrue(Schema::hasTable('branch_menu'), 'branch_menu table required');
        $this->assertTrue(Schema::hasTable('store_backups'), 'store_backups table required');
        foreach ($tables as $t) {
            $this->assertTrue(Schema::hasTable($t), "table $t required");
        }

        $tableId = $this->insertStoreTables($this->downtown->id);
        $resvId = $this->insertReservations($this->downtown->id);
        $staffId = $this->insertStaffMembers($this->downtown->id);
        $tokenId = $this->insertStaffTokens($staffId);
        $posId = $this->insertPosTerminals($this->downtown->id);
        $printerId = $this->insertPrinterProfiles($this->downtown->id);
        $waitId = $this->insertWaitlistEntries($this->downtown->id);

        $otherMainTable = $this->insertStoreTables($this->mainA->id);
        $otherMainResv = $this->insertReservations($this->mainA->id);
        $otherMainStaff = $this->insertStaffMembers($this->mainA->id);

        $branchMenuId = DB::table('branch_menu')->insertGetId([
            'account_id' => $this->storeA->account_id, 'store_id' => $this->storeA->id,
            'branch_id' => $this->downtown->id, 'menu_id' => 1, 'available' => true,
        ]);
        $backupId = DB::table('store_backups')->insertGetId([
            'account_id' => $this->storeA->account_id, 'store_id' => $this->storeA->id,
            'user_id' => $this->owner->id, 'token' => Str::random(32),
            'status' => 'done', 'branch_id' => $this->downtown->id,
        ]);

        $beforeA = [];
        foreach ($tables as $t) {
            $beforeA[$t] = DB::table($t)->where('store_id', $this->storeA->id)->where('branch_id', $this->downtown->id)->count();
        }
        $beforeStaffA = DB::table('staff_members')->where('store_id', $this->storeA->id)->where('branch_id', $this->downtown->id)->count();
        $beforeTokensA = DB::table('staff_tokens')->where('store_id', $this->storeA->id)->whereIn('staff_id', $staffId)->count();

        $why = $this->service->applyBranch($this->makeTransfer());
        $this->assertNull($why, 'clean branch moves');

        foreach ($tables as $t) {
            $ids = match ($t) {
                'store_tables' => $tableId,
                'reservations' => $resvId,
                'pos_terminals' => $posId,
                'printer_profiles' => $printerId,
                'waitlist_entries' => $waitId,
            };
            foreach ($ids as $id) {
                $row = DB::table($t)->where('id', $id)->first();
                $this->assertNotNull($row, "$t row $id should still exist");
                $this->assertSame($this->storeB->id, (int) $row->store_id, "$t row $id store_id should be store B");
                $this->assertSame((int) $this->storeB->account_id, (int) $row->account_id, "$t row $id account_id should be store B's account");
            }
            $this->assertSame(0, DB::table($t)->where('store_id', $this->storeA->id)->where('branch_id', $this->downtown->id)->count(), "$t rows for Downtown should be gone from store A");
            $this->assertSame($beforeA[$t], DB::table($t)->where('store_id', $this->storeB->id)->whereIn('id', $ids)->count(), "$t rows should be rekeyed to B");
        }

        $staffRow = DB::table('staff_members')->where('id', $staffId[0])->first();
        $this->assertSame($this->storeB->id, (int) $staffRow->store_id, 'staff row store_id should be B');
        $this->assertSame((int) $this->storeB->account_id, (int) $staffRow->account_id, 'staff row account_id should be B');
        $this->assertSame($beforeStaffA - 1, DB::table('staff_members')->where('store_id', $this->storeA->id)->where('branch_id', $this->downtown->id)->count(), 'staff row dropped from A');

        $tokenRow = DB::table('staff_tokens')->where('id', $tokenId[0])->first();
        $this->assertSame($this->storeB->id, (int) $tokenRow->store_id, 'staff_token child row should follow staff');
        $this->assertSame((int) $this->storeB->account_id, (int) $tokenRow->account_id, 'staff_token child row account_id should be B');
        $this->assertSame($beforeTokensA - 1, DB::table('staff_tokens')->where('store_id', $this->storeA->id)->whereIn('staff_id', $staffId)->count(), 'staff_tokens child rows should follow staff out of A');

        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeB->id, (int) $branch->store_id, 'branch.store_id should be B');
        $this->assertSame((int) $this->storeB->account_id, (int) $branch->account_id, 'branch.account_id should be B');

        $this->assertSame(0, DB::table('branch_menu')->where('id', $branchMenuId)->count(), 'branch_menu row for Downtown should be dropped');

        $backup = DB::table('store_backups')->where('id', $backupId)->first();
        $this->assertNotNull($backup, 'store_backups row should be kept');
        $this->assertSame($this->storeA->id, (int) $backup->store_id, 'store_backups row should stay with the source store (A)');
        $this->assertSame((int) $this->storeA->account_id, (int) $backup->account_id, 'store_backups row should stay with source account');

        $this->assertSame(1, DB::table('store_tables')->where('id', $otherMainTable[0])->where('store_id', $this->storeA->id)->count(), 'Main branch rows should not be rekeyed');
        $this->assertSame(1, DB::table('reservations')->where('id', $otherMainResv[0])->where('store_id', $this->storeA->id)->count(), 'Main branch rows should not be rekeyed');
        $this->assertSame(1, DB::table('staff_members')->where('id', $otherMainStaff[0])->where('store_id', $this->storeA->id)->count(), 'Main branch rows should not be rekeyed');
    }

    public function test_a_branch_with_an_order_is_blocked_as_history_and_nothing_changes(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('orders table required');
        }
        Order::factory()->create([
            'store_id' => $this->storeA->id, 'branch_id' => $this->downtown->id, 'status' => 'completed',
        ]);

        $transfer = $this->makeTransfer();
        $why = $this->service->applyBranch($transfer);
        $this->assertSame('history', $why);

        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeA->id, (int) $branch->store_id, 'branch should stay in store A on history block');
        $transfer->refresh();
        $this->assertSame('declined', $transfer->status, 'transfer should be marked declined on block');
    }

    public function test_a_branch_with_a_fiscal_device_is_blocked_as_history(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_devices'), 'fiscal_devices table required');
        DB::table('fiscal_devices')->insert([
            'account_id' => $this->storeA->account_id, 'store_id' => $this->storeA->id,
            'branch_id' => $this->downtown->id, 'country' => 'SA',
            'device_key' => 'dev-'.Str::random(6),
        ]);

        $why = $this->service->applyBranch($this->makeTransfer());
        $this->assertSame('history', $why);

        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeA->id, (int) $branch->store_id, 'fiscal device should leave the branch untouched in store A');
        $this->assertSame(1, DB::table('fiscal_devices')->where('branch_id', $this->downtown->id)->where('store_id', $this->storeA->id)->count(), 'fiscal device should be untouched');
    }

    public function test_an_open_pos_shift_blocks_the_move(): void
    {
        $this->assertTrue(Schema::hasTable('pos_shifts'), 'pos_shifts table required');
        $staffId = DB::table('staff_members')->insertGetId([
            'account_id' => $this->storeA->account_id, 'owner_id' => $this->owner->id, 'store_id' => $this->storeA->id,
            'branch_id' => $this->mainA->id, 'name' => 'S', 'email' => Str::random(8).'@x.test', 'password' => 'x',
            'role' => 'cashier', 'active' => 1,
        ]);
        DB::table('pos_shifts')->insert([
            'account_id' => $this->storeA->account_id, 'store_id' => $this->storeA->id,
            'branch_id' => $this->downtown->id, 'staff_id' => $staffId,
            'opened_at' => now(), 'status' => 'open',
        ]);

        $why = $this->service->applyBranch($this->makeTransfer());
        $this->assertSame('history', $why);

        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeA->id, (int) $branch->store_id, 'open pos shift should leave the branch in store A');
    }

    public function test_a_coupon_on_the_branch_blocks_as_linked(): void
    {
        $this->assertTrue(Schema::hasTable('coupons'), 'coupons table required');
        DB::table('coupons')->insert([
            'account_id' => $this->storeA->account_id, 'user_id' => $this->owner->id,
            'branch_id' => $this->downtown->id, 'code' => Str::upper(Str::random(8)),
            'percentage_rate' => 10, 'coupon_type' => 'percentage', 'limit' => 1, 'used' => 0,
        ]);

        $why = $this->service->applyBranch($this->makeTransfer());
        $this->assertSame('linked', $why);

        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeA->id, (int) $branch->store_id, 'coupon should leave the branch in store A');
        $this->assertSame(1, DB::table('coupons')->where('branch_id', $this->downtown->id)->count(), 'coupon should be untouched');
    }

    public function test_stock_on_hand_blocks_the_move_and_zero_stock_lets_the_row_be_deleted(): void
    {
        $this->assertTrue(Schema::hasTable('branch_stock_levels'), 'branch_stock_levels table required');
        DB::table('branch_stock_levels')->insert([
            'account_id' => $this->storeA->account_id, 'store_id' => $this->storeA->id,
            'branch_id' => $this->downtown->id, 'ingredient_id' => 1, 'current_stock' => 5, 'par_level' => 0,
        ]);

        $blockedTransfer = $this->makeTransfer();
        $why = $this->service->applyBranch($blockedTransfer);
        $this->assertSame('stock', $why);
        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeA->id, (int) $branch->store_id, 'branch should stay in A while stock > 0');

        DB::table('branch_stock_levels')->where('branch_id', $this->downtown->id)->update(['current_stock' => 0]);

        $cleanTransfer = $this->makeTransfer();
        $why = $this->service->applyBranch($cleanTransfer);
        $this->assertNull($why, 'zero-stock move succeeds');
        $this->assertSame(0, DB::table('branch_stock_levels')->where('branch_id', $this->downtown->id)->count(), 'zero-stock row deleted on move');
        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeB->id, (int) $branch->store_id, 'branch moved to B once stock is zero');
    }

    public function test_an_open_table_session_blocks_the_move(): void
    {
        $this->assertTrue(Schema::hasTable('table_sessions'), 'table_sessions table required');
        DB::table('table_sessions')->insert([
            'account_id' => $this->storeA->account_id, 'store_id' => $this->storeA->id,
            'branch_id' => $this->downtown->id, 'table_key' => 'k-'.Str::random(6),
            'token' => Str::random(40), 'day' => now()->toDateString(),
            'expires_at' => now()->addHour(),
        ]);

        $why = $this->service->applyBranch($this->makeTransfer());
        $this->assertSame('open_session', $why);

        $branch = Branch::find($this->downtown->id);
        $this->assertSame($this->storeA->id, (int) $branch->store_id, 'open table session should leave branch in store A');
    }

    public function test_moving_the_only_branch_of_store_b_to_a_is_blocked_as_last(): void
    {
        $this->assertSame(1, Branch::where('store_id', $this->storeB->id)->count(), 'store B should have exactly one branch in setUp');
        $this->assertSame(2, Branch::where('store_id', $this->storeA->id)->count(), 'store A should have two branches in setUp');

        $transfer = StoreTransfer::create([
            'kind' => 'branch',
            'token' => StoreTransfer::newToken(),
            'from_user_id' => $this->owner->id,
            'store_id' => $this->storeB->id,
            'branch_id' => $this->mainB->id,
            'target_store_id' => $this->storeA->id,
            'status' => 'pending',
        ]);

        $why = $this->service->applyBranch($transfer);
        $this->assertSame('last', $why);

        $branch = Branch::find($this->mainB->id);
        $this->assertSame($this->storeB->id, (int) $branch->store_id, 'last-branch block should leave the branch in store B');
    }

    public function test_same_store_via_branch_move_blocker(): void
    {
        $this->assertSame('same_store', BranchMove::blocker($this->downtown, $this->storeA));
    }

    public function test_controller_move_branch_with_held_blocker_sets_session_error_with_an_order_returns_success_when_clean(): void
    {
        $this->assertTrue(Schema::hasTable('orders'), 'orders table required');

        Order::factory()->create([
            'store_id' => $this->storeA->id, 'branch_id' => $this->downtown->id, 'status' => 'completed',
        ]);

        $response = $this->actingAs($this->owner)
            ->withSession(['dashboard_store_id' => $this->storeA->id])
            ->post(route('transfer.branch.move'), [
                'branch_id' => $this->downtown->id,
                'target_store_id' => $this->storeB->id,
            ]);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame($this->storeA->id, (int) Branch::find($this->downtown->id)->store_id, 'branch should still be in A when the controller hits the blocker');

        Order::query()->where('branch_id', $this->downtown->id)->delete();

        $response = $this->actingAs($this->owner)
            ->withSession(['dashboard_store_id' => $this->storeA->id])
            ->post(route('transfer.branch.move'), [
                'branch_id' => $this->downtown->id,
                'target_store_id' => $this->storeB->id,
            ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame($this->storeB->id, (int) Branch::find($this->downtown->id)->store_id, 'branch should be in B after a clean controller move');
    }

    public function test_controller_move_branch_to_a_store_owned_by_a_different_user_returns_403(): void
    {
        $otherPlan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $otherOwner = User::factory()->create(['group_id' => (string) $otherPlan->id, 'status' => 1, 'email_verified_at' => now()]);
        $otherStore = $this->newStore($otherOwner, 'Z');

        $response = $this->actingAs($this->owner)
            ->withSession(['dashboard_store_id' => $this->storeA->id])
            ->post(route('transfer.branch.move'), [
                'branch_id' => $this->downtown->id,
                'target_store_id' => $otherStore->id,
            ]);
        $response->assertStatus(403);
        $this->assertSame($this->storeA->id, (int) Branch::find($this->downtown->id)->store_id, 'cross-owner attempt should leave branch untouched in store A');
    }
}
