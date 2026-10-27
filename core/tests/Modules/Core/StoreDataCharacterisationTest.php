<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\MenuCategory;
use App\Models\StoreTable;
use App\Services\StoreCloneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-21 part 1, step 1 — characterisation of the store transfer (OwnershipTransferService) and the store duplicate
 * (StoreCloneService) as they behaved before the store-data registry: which rows move to the new account, which
 * user-keyed satellites are re-pointed, which rows are copied and which are not, and the safeguards (capacity at
 * accept, branch move, audit). Assertions marked "P1-21:" were changed by the registry (step 3) — each one is a
 * decision or bug fix recorded with that change.
 */
class StoreDataCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    private function owner(int $limit = 5): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => $limit, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $u, string $slug): Store
    {
        return Store::create([
            'user_id' => $u->id, 'title' => 'Store '.$slug, 'slug' => $slug,
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    /** Raw insert with the tenant keys filled the way the app fills them. */
    private function row(string $table, array $row): int
    {
        return (int) DB::table($table)->insertGetId(TenantFill::row(DB::connection(), $table, $row));
    }

    private function transfer(User $from, User $to, Store $store): StoreTransfer
    {
        return StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $from->id,
            'to_user_id' => $to->id, 'to_email' => $to->email, 'store_id' => $store->id,
            'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
    }

    /** @return array<string, int> table => row id, one row per table, all of $store */
    private function storeRows(Store $store, User $owner): array
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => 30, 'active' => 1]);
        $now = now();

        return [
            'categories' => $cat->id,
            'items' => $item->id,
            'orders' => $this->row('orders', ['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => $now]),
            'customers' => $this->row('customers', ['store_id' => $store->id, 'name' => 'Guest', 'created_at' => $now]),
            'activity_logs' => $this->row('activity_logs', ['store_id' => $store->id, 'actor_user_id' => $owner->id, 'action' => 'menu.update', 'ip' => '10.0.0.1', 'created_at' => $now]),
            'message_costs' => $this->row('message_costs', ['store_id' => $store->id, 'channel' => 'whatsapp', 'category' => 'utility', 'units' => 1, 'cost_minor' => 5, 'created_at' => $now]),
            'whatsapp_usage' => $this->row('whatsapp_usage', ['store_id' => $store->id, 'user_id' => $owner->id, 'provider' => 'meta', 'feature' => 'order', 'category' => 'utility', 'message_count' => 1, 'billable' => 1, 'est_cost' => 0.01, 'sell_price' => 0.02, 'created_at' => $now]),
            'staff_members' => $this->row('staff_members', ['store_id' => $store->id, 'owner_id' => $owner->id, 'name' => 'Waiter', 'email' => Str::random(6).'@x.com', 'password' => 'x', 'role' => 'waiter', 'active' => 1]),
            'drivers' => $this->row('drivers', ['store_id' => $store->id, 'owner_id' => $owner->id, 'name' => 'Rider', 'active' => 1, 'created_at' => $now]),
            'push_subscriptions' => $this->row('push_subscriptions', ['store_id' => $store->id, 'user_id' => $owner->id, 'platform' => 'web', 'endpoint' => 'https://push.example/'.Str::random(8), 'endpoint_hash' => hash('sha256', Str::random(12)), 'created_at' => $now]),
            'paylink_onboardings' => $this->row('paylink_onboardings', ['store_id' => $store->id, 'status' => 'active', 'provider_merchant_id' => 'pl-alice', 'cr_number' => '1010', 'owner_id_number' => '1999', 'iban' => 'SA0380000000608010167519', 'created_at' => $now]),
            'store_backups' => $this->row('store_backups', ['store_id' => $store->id, 'user_id' => $owner->id, 'token' => Str::random(32), 'status' => 'ready', 'include_pii' => 0, 'format' => 'json', 'created_at' => $now]),
        ];
    }

    public function test_transfer_moves_store_rows_to_the_new_account_and_repoints_satellites(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $store = $this->store($alice, 'char-move');
        $other = $this->store($alice, 'char-stays');
        $rows = $this->storeRows($store, $alice);
        $otherRows = $this->storeRows($other, $alice);
        $aliceAccount = (int) $store->account_id;
        $grant = $this->row('feature_grants', ['user_id' => $alice->id, 'feature_key' => 'pos', 'granted_at' => now(), 'source' => 'plan']);

        app(OwnershipTransferService::class)->applyStore($this->transfer($alice, $bob, $store));

        $store->refresh();
        $bobAccount = (int) $store->account_id;
        $this->assertSame($bob->id, (int) $store->user_id);
        $this->assertNotSame($aliceAccount, $bobAccount, 'the store joined Bob\'s account');
        $this->assertSame($bobAccount, (int) Branch::where('store_id', $store->id)->value('account_id'), 'branches follow');

        // Store data follows the store to Bob's account.
        foreach (['categories', 'items', 'orders', 'customers', 'activity_logs', 'staff_members', 'drivers', 'store_backups'] as $table) {
            $this->assertSame($bobAccount, (int) DB::table($table)->where('id', $rows[$table])->value('account_id'), $table.' re-keyed');
        }
        $this->assertSame($bobAccount, (int) DB::table('store_transfers')->where('store_id', $store->id)->value('account_id'), 'the transfer row follows (cooldown check)');

        // P1-21 (D-P1-21-2): the cost/usage ledgers stay with the account that incurred them (stay-with-old-account).
        $this->assertSame($aliceAccount, (int) DB::table('message_costs')->where('id', $rows['message_costs'])->value('account_id'));
        $this->assertSame($aliceAccount, (int) DB::table('whatsapp_usage')->where('id', $rows['whatsapp_usage'])->value('account_id'));

        // User-keyed satellites of THIS store are re-pointed to Bob.
        $this->assertSame($bob->id, (int) DB::table('staff_members')->where('id', $rows['staff_members'])->value('owner_id'));
        $this->assertSame($bob->id, (int) DB::table('store_backups')->where('id', $rows['store_backups'])->value('user_id'));
        // P1-21 bug fix: drivers were left with Alice as owner (the new owner could not manage them).
        $this->assertSame($bob->id, (int) DB::table('drivers')->where('id', $rows['drivers'])->value('owner_id'));

        // P1-21 bug fix: Alice's devices kept receiving the store's order pushes after the transfer.
        $this->assertFalse(DB::table('push_subscriptions')->where('id', $rows['push_subscriptions'])->exists());
        // P1-21 bug fix: Alice's Paylink merchant onboarding (her ID number, IBAN, merchant id) stayed on the store, and
        // a later Paylink activation re-provisioned HER merchant keys onto it. Reset: no PII, no merchant link.
        $onboarding = DB::table('paylink_onboardings')->where('id', $rows['paylink_onboardings'])->first();
        $this->assertSame('draft', $onboarding->status);
        $this->assertNull($onboarding->provider_merchant_id);
        $this->assertNull($onboarding->owner_id_number);
        $this->assertNull($onboarding->iban);
        $this->assertNull($onboarding->cr_number);
        $this->assertSame($bobAccount, (int) $onboarding->account_id);

        // Alice's other store and her account-level rows are untouched.
        foreach ($otherRows as $table => $id) {
            $this->assertSame($aliceAccount, (int) DB::table($table)->where('id', $id)->value('account_id'), 'other store: '.$table);
        }
        $this->assertSame($alice->id, (int) DB::table('drivers')->where('id', $otherRows['drivers'])->value('owner_id'));
        $this->assertTrue(DB::table('push_subscriptions')->where('id', $otherRows['push_subscriptions'])->exists());
        $this->assertSame('pl-alice', DB::table('paylink_onboardings')->where('id', $otherRows['paylink_onboardings'])->value('provider_merchant_id'));
        $this->assertSame($aliceAccount, (int) DB::table('feature_grants')->where('id', $grant)->value('account_id'));

        // Audit: one store.transfer row with before/after owner and what was reset.
        $log = ActivityLog::where('action', 'store.transfer')->where('store_id', $store->id)->firstOrFail();
        $this->assertSame(['user_id' => $alice->id], $log->meta['old']);
        $this->assertSame(['user_id' => $bob->id], $log->meta['new']);
        $this->assertArrayHasKey('credentials', $log->meta['reset']);
        $this->assertArrayHasKey('options', $log->meta['reset']);
        $this->assertArrayHasKey('domains', $log->meta['reset']);
    }

    public function test_accept_is_refused_when_the_recipient_plan_is_full(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $alice = $this->owner();
        $bob = $this->owner(1);
        $this->store($bob, 'bob-only');
        $store = $this->store($alice, 'char-full');
        $transfer = $this->transfer($alice, $bob, $store);

        $this->actingAs($bob)->post(route('transfer.accept', $transfer->token))->assertRedirect()->assertSessionHas('error');

        $this->assertSame($alice->id, (int) $store->fresh()->user_id);
        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_branch_move_takes_its_tables_and_reservations_and_is_audited(): void
    {
        $alice = $this->owner();
        $a = $this->store($alice, 'char-ba');
        $b = $this->store($alice, 'char-bb');
        $branch = Branch::create(['store_id' => $a->id, 'name' => 'Downtown']);
        $table = $this->row('store_tables', ['store_id' => $a->id, 'branch_id' => $branch->id, 'table_no' => 1, 'key' => Str::random(13)]);
        $resv = $this->row('reservations', ['store_id' => $a->id, 'branch_id' => $branch->id, 'name' => 'R', 'party_size' => 2, 'reserved_at' => now()->addDay(), 'status' => 'pending', 'source' => 'web']);

        $this->actingAs($alice);
        session(['dashboard_store_id' => $a->id]);
        $this->post(route('transfer.branch.move'), ['branch_id' => $branch->id, 'target_store_id' => $b->id])->assertRedirect();

        $this->assertSame($b->id, (int) $branch->fresh()->store_id);
        $this->assertSame($b->id, (int) DB::table('store_tables')->where('id', $table)->value('store_id'));
        $this->assertSame($b->id, (int) DB::table('reservations')->where('id', $resv)->value('store_id'));
        $this->assertTrue(ActivityLog::where('action', 'branch.transfer')->where('store_id', $b->id)->exists());
    }

    public function test_duplicate_copies_the_catalogue_and_nothing_of_the_business(): void
    {
        $alice = $this->owner();
        $source = $this->store($alice, 'char-dup');
        $rows = $this->storeRows($source, $alice);
        $main = (int) Branch::where('store_id', $source->id)->value('id');
        $station = $this->row('kitchen_stations', ['store_id' => $source->id, 'name' => 'Grill', 'display_color' => '#000', 'target_minutes' => 10, 'is_expo' => 0, 'active' => 1, 'position' => 0]);
        $staff = $rows['staff_members'];
        $roomType = $this->row('room_types', ['store_id' => $source->id, 'name' => 'Double', 'base_rate_minor' => 1000, 'max_occupancy' => 2, 'sort' => 0, 'active' => 1]);
        DB::table('items')->where('id', $rows['items'])->update(['station_id' => $station, 'hs_product_id' => 'hs-alice-1']);
        StoreTable::create(['store_id' => $source->id, 'branch_id' => $main, 'table_no' => 7, 'key' => 'SRCKEY0000001', 'server_staff_id' => $staff, 'room_type_id' => $roomType]);
        $sub = MenuCategory::create(['store_id' => $source->id, 'name' => 'Sub', 'parent' => $rows['categories']]);
        Menu::create(['store_id' => $source->id, 'category_id' => $sub->id, 'name' => 'Side', 'price' => 5, 'active' => 1]);

        ['clone' => $clone, 'counts' => $counts] = app(StoreCloneService::class)->clone($source);

        $this->assertSame((int) $source->account_id, (int) $clone->account_id);
        $this->assertNotSame($source->slug, $clone->slug);
        $this->assertSame(2, $counts['categories']);
        $this->assertSame(2, $counts['items']);
        $this->assertSame(1, $counts['tables']);
        $cloneSub = DB::table('categories')->where('store_id', $clone->id)->whereNotNull('parent')->first();
        $this->assertSame((int) DB::table('categories')->where('store_id', $clone->id)->whereNull('parent')->value('id'), (int) $cloneSub->parent, 'sub-category points at the copied parent');

        // Not copied: orders, customers, staff, drivers, logs, ledgers, devices, onboarding, backups, stations.
        foreach (['orders', 'customers', 'activity_logs', 'message_costs', 'whatsapp_usage', 'staff_members', 'drivers', 'push_subscriptions', 'paylink_onboardings', 'store_backups', 'kitchen_stations', 'room_types'] as $table) {
            $this->assertSame(0, DB::table($table)->where('store_id', $clone->id)->count(), $table.' not copied');
        }

        // The copied table has a fresh QR key; every reference points inside the clone (P1-21 bug fix: the
        // branch, server and room type of the SOURCE store were copied verbatim — cross-store references).
        $copy = DB::table('store_tables')->where('store_id', $clone->id)->first();
        $this->assertNotSame('SRCKEY0000001', $copy->key);
        $this->assertSame(7, (int) $copy->table_no);
        $this->assertNull($copy->server_staff_id);
        $this->assertNull($copy->room_type_id);
        $this->assertNull($copy->branch_id);
        // P1-21 bug fix: items kept the source store's kitchen station and HungerStation product id.
        $copiedItem = DB::table('items')->where('store_id', $clone->id)->where('name', 'Kabsa')->first();
        $this->assertNull($copiedItem->station_id);
        $this->assertNull($copiedItem->hs_product_id);
        $this->assertNotSame(DB::table('items')->where('id', $rows['items'])->value('public_id'), $copiedItem->public_id);
        // Media by reference: the same file name, no copy.
        $this->assertSame(DB::table('items')->where('id', $rows['items'])->value('image'), $copiedItem->image);

        // The source is untouched.
        $this->assertSame('SRCKEY0000001', DB::table('store_tables')->where('store_id', $source->id)->value('key'));
        $this->assertSame($station, (int) DB::table('items')->where('id', $rows['items'])->value('station_id'));
    }
}
