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
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\BranchDeletion;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-325: deleting a branch re-homes its rows to the default branch or refuses while history points at it. */
class BranchDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Branch $main;

    private Branch $second;

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
        $title = 'R '.Str::random(6);
        $this->store = Store::create([
            'user_id' => $this->owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->main = Branch::where('store_id', $this->store->id)->orderBy('id')->firstOrFail();
        $this->second = Branch::create(['store_id' => $this->store->id, 'name' => 'Second', 'active' => true]);
    }

    private function staff(?int $branchId): int
    {
        return DB::table('staff_members')->insertGetId([
            'account_id' => $this->store->account_id, 'owner_id' => $this->owner->id, 'store_id' => $this->store->id,
            'branch_id' => $branchId, 'name' => 'S', 'email' => Str::random(8).'@x.test', 'password' => 'x',
        ]);
    }

    private function coupon(?int $branchId): int
    {
        return DB::table('coupons')->insertGetId([
            'account_id' => $this->store->account_id, 'user_id' => $this->owner->id, 'branch_id' => $branchId,
            'code' => Str::upper(Str::random(8)), 'percentage_rate' => 10,
        ]);
    }

    private function table(?int $branchId): int
    {
        return DB::table('store_tables')->insertGetId([
            'account_id' => $this->store->account_id, 'store_id' => $this->store->id, 'branch_id' => $branchId, 'table_no' => 1,
        ]);
    }

    private function destroy(Branch $branch)
    {
        return $this->actingAs($this->owner)->delete(route('branches.destroy', $branch->id));
    }

    public function test_staff_coupons_and_tables_are_rehomed_to_the_default_branch(): void
    {
        $staff = $this->staff($this->second->id);
        $coupon = $this->coupon($this->second->id);
        $table = $this->table($this->second->id);
        $other = $this->staff($this->main->id);

        $this->destroy($this->second)->assertRedirect()->assertSessionHas('success');

        $this->assertNull(Branch::find($this->second->id));
        $this->assertSame($this->main->id, (int) DB::table('staff_members')->where('id', $staff)->value('branch_id'));
        $this->assertSame($this->main->id, (int) DB::table('coupons')->where('id', $coupon)->value('branch_id'));
        $this->assertSame($this->main->id, (int) DB::table('store_tables')->where('id', $table)->value('branch_id'));
        $this->assertSame($this->main->id, (int) DB::table('staff_members')->where('id', $other)->value('branch_id'));
    }

    public function test_deleting_the_default_branch_moves_its_rows_to_the_next_branch(): void
    {
        $staff = $this->staff($this->main->id);
        $legacy = $this->table(null);

        $this->destroy($this->main)->assertSessionHas('success');

        $this->assertSame($this->second->id, (int) DB::table('staff_members')->where('id', $staff)->value('branch_id'));
        // NULL keeps meaning "the default branch" (ScopesToBranch), now the second one.
        $this->assertNull(DB::table('store_tables')->where('id', $legacy)->value('branch_id'));
    }

    public function test_a_branch_with_orders_cannot_be_deleted(): void
    {
        $order = Order::factory()->create(['store_id' => $this->store->id, 'branch_id' => $this->second->id, 'status' => 'completed']);
        $staff = $this->staff($this->second->id);

        $this->destroy($this->second)->assertSessionHas('error');

        $this->assertNotNull(Branch::find($this->second->id));
        $this->assertSame($this->second->id, (int) Order::whereKey($order->id)->value('branch_id'));
        $this->assertSame($this->second->id, (int) DB::table('staff_members')->where('id', $staff)->value('branch_id'));
    }

    public function test_a_branch_with_a_fiscal_device_cannot_be_deleted_and_the_device_is_untouched(): void
    {
        $device = DB::table('fiscal_devices')->insertGetId([
            'account_id' => $this->store->account_id, 'store_id' => $this->store->id, 'branch_id' => $this->second->id,
            'country' => 'SA', 'device_key' => 'dev-'.Str::random(6),
        ]);

        $this->destroy($this->second)->assertSessionHas('error');

        $this->assertNotNull(Branch::find($this->second->id));
        $this->assertSame($this->second->id, (int) DB::table('fiscal_devices')->where('id', $device)->value('branch_id'));
    }

    public function test_an_open_table_session_blocks_the_delete(): void
    {
        $row = [
            'account_id' => $this->store->account_id, 'store_id' => $this->store->id, 'branch_id' => $this->second->id,
            'table_key' => 'k', 'token' => Str::random(20), 'day' => now()->toDateString(),
        ];
        DB::table('table_sessions')->insert($row + ['expires_at' => now()->addHour()]);
        $this->assertSame('open_session', BranchDeletion::blocker($this->second));

        DB::table('table_sessions')->update(['expires_at' => now()->subMinute()]);
        $this->assertNull(BranchDeletion::blocker($this->second));
    }

    public function test_the_last_branch_still_cannot_be_deleted(): void
    {
        $this->second->delete();

        $this->destroy($this->main)->assertSessionHas('error');
        $this->assertNotNull(Branch::find($this->main->id));
        $this->assertSame('last', BranchDeletion::delete($this->main->fresh()));
    }

    public function test_per_branch_overrides_are_removed_with_the_branch(): void
    {
        DB::table('branch_menu')->insert([
            'account_id' => $this->store->account_id, 'store_id' => $this->store->id,
            'branch_id' => $this->second->id, 'menu_id' => 1,
        ]);
        DB::table('branch_menu')->insert([
            'account_id' => $this->store->account_id, 'store_id' => $this->store->id,
            'branch_id' => $this->main->id, 'menu_id' => 1,
        ]);

        $this->assertNull(BranchDeletion::delete($this->second));

        $this->assertSame(0, DB::table('branch_menu')->where('branch_id', $this->second->id)->count());
        $this->assertSame(1, DB::table('branch_menu')->where('branch_id', $this->main->id)->count());
    }

    public function test_every_classified_table_exists_with_a_branch_id_column(): void
    {
        $tables = BranchDeletion::branchTables();
        $all = [...BranchDeletion::HISTORY, ...BranchDeletion::PER_BRANCH, ...BranchDeletion::KEEP, ...BranchDeletion::MOVE];
        $this->assertSame(array_unique($all), $all, 'a table is classified in more than one BranchDeletion list');
        foreach ($all as $table) {
            $this->assertContains($table, $tables, "$table is classified but is not a registered tenant table with branch_id");
            $this->assertTrue(Schema::hasColumn($table, 'branch_id'));
        }
        $this->assertSame([], BranchDeletion::unclassified(), 'Tenant tables with branch_id but no BranchDeletion rule: '
            .implode(', ', BranchDeletion::unclassified()).'. Classify each in BranchDeletion::HISTORY, PER_BRANCH, KEEP or MOVE.');
        $this->assertContains('staff_members', $tables);
        $this->assertContains('coupons', $tables);
    }
}
