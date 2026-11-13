<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy\Mut;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataEngine;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Services\Tenancy\TenantTables;
use Tests\TestCase;

/**
 * P6-27: TenantFill stamps every new row with the right account (from its store, its parent row or its
 * owning user) and re-keys / adopts rows only for the store / user named; AccountScope's store filter.
 */
class TenantFillMutTest extends TestCase
{
    use RefreshDatabase;
    use TenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenants();
        TenantFill::flushStores();
    }

    public function test_row_takes_the_account_of_its_own_store(): void
    {
        $a = (int) $this->ownerA->account_id;
        $b = (int) $this->ownerB->account_id;
        $this->assertNotSame($a, $b);
        $this->assertSame($b, TenantFill::row(DB::connection(), 'orders', ['store_id' => $this->storeB->id])['account_id']);
        $this->assertSame($a, TenantFill::row(DB::connection(), 'orders', ['store_id' => (string) $this->storeA2->id])['account_id']);
        // An explicit account is kept, never overwritten.
        $this->assertSame(123, TenantFill::row(DB::connection(), 'orders', ['store_id' => $this->storeB->id, 'account_id' => 123])['account_id']);
        // Unknown store → no account invented.
        $this->assertArrayNotHasKey('account_id', TenantFill::row(DB::connection(), 'orders', ['store_id' => 987654]));
        // Non-tenant table and the stores table are returned untouched.
        $this->assertSame(['store_id' => $this->storeB->id], TenantFill::row(DB::connection(), 'not_a_tenant_table', ['store_id' => $this->storeB->id]));
        $this->assertSame(['user_id' => $this->ownerA->id], TenantFill::row(DB::connection(), 'stores', ['user_id' => $this->ownerA->id]));
    }

    public function test_child_row_takes_store_and_account_from_its_parent(): void
    {
        $orderB = Order::withoutGlobalScopes()->where('store_id', $this->storeB->id)->value('id');
        $orderA = Order::withoutGlobalScopes()->where('store_id', $this->storeA1->id)->value('id');
        $row = TenantFill::row(DB::connection(), 'order_items', ['order_id' => $orderB]);
        $this->assertSame($this->storeB->id, $row['store_id']);
        $this->assertSame((int) $this->ownerB->account_id, $row['account_id']);

        $row = TenantFill::row(DB::connection(), 'order_items', ['order_id' => $orderA]);
        $this->assertSame($this->storeA1->id, $row['store_id']);
        $this->assertSame((int) $this->ownerA->account_id, $row['account_id']);

        // An explicit store wins over the parent's; a missing parent leaves the row store-less.
        $row = TenantFill::row(DB::connection(), 'order_items', ['order_id' => $orderB, 'store_id' => $this->storeA2->id]);
        $this->assertSame($this->storeA2->id, $row['store_id']);
        $this->assertSame((int) $this->ownerA->account_id, $row['account_id']);
        $row = TenantFill::row(DB::connection(), 'order_items', ['order_id' => 999999]);
        $this->assertArrayNotHasKey('store_id', $row);
        $this->assertArrayNotHasKey('account_id', $row);
    }

    public function test_store_less_user_owned_row_takes_the_users_account(): void
    {
        $table = 'upgrades';
        $this->assertContains($table, TenantTables::userOwned());
        $this->assertSame((int) $this->ownerB->account_id, TenantFill::row(DB::connection(), $table, ['user_id' => $this->ownerB->id])['account_id']);
        $this->assertSame((int) $this->ownerA->account_id, TenantFill::row(DB::connection(), $table, ['user_id' => $this->ownerA->id])['account_id']);
        $this->assertArrayNotHasKey('account_id', TenantFill::row(DB::connection(), $table, []));
        // A user without an account gets none.
        $loner = User::factory()->create();
        $this->assertArrayNotHasKey('account_id', TenantFill::row(DB::connection(), $table, ['user_id' => $loner->id]));
        // A store-keyed table that is not user-owned ignores user_id.
        $this->assertNotContains('orders', TenantTables::userOwned());
        $this->assertArrayNotHasKey('account_id', TenantFill::row(DB::connection(), 'orders', ['user_id' => $this->ownerB->id]));
    }

    public function test_fill_sets_only_changed_keys_on_the_model(): void
    {
        $order = new Order(['store_id' => $this->storeB->id, 'status' => 'new', 'type' => 'takeaway']);
        TenantFill::fill($order);
        $this->assertSame((int) $this->ownerB->account_id, (int) $order->account_id);
        $this->assertSame($this->storeB->id, (int) $order->store_id);

        $saved = Order::create(['store_id' => $this->storeA1->id, 'status' => 'new', 'type' => 'takeaway']);
        $this->assertSame((int) $this->ownerA->account_id, (int) $saved->fresh()->account_id);
    }

    public function test_account_for_store_is_cached_until_forgotten(): void
    {
        $db = DB::connection();
        $b = (int) $this->ownerB->account_id;
        $this->assertSame($b, TenantFill::accountForStore($db, $this->storeB->id));
        DB::table('stores')->where('id', $this->storeB->id)->update(['account_id' => $this->ownerA->account_id]);
        $this->assertSame($b, TenantFill::accountForStore($db, $this->storeB->id), 'cached');
        TenantFill::forgetStore($this->storeA1->id);
        $this->assertSame($b, TenantFill::accountForStore($db, $this->storeB->id), 'forgetting another store keeps it');
        TenantFill::forgetStore($this->storeB->id);
        $this->assertSame((int) $this->ownerA->account_id, TenantFill::accountForStore($db, $this->storeB->id));
        DB::table('stores')->where('id', $this->storeB->id)->update(['account_id' => $b]);
        TenantFill::flushStores();
        $this->assertSame($b, TenantFill::accountForStore($db, $this->storeB->id));
        $this->assertNull(TenantFill::accountForStore($db, 999999));
    }

    public function test_rekey_moves_only_the_named_store_and_survives_missing_tables(): void
    {
        $a = (int) $this->ownerA->account_id;
        $b = (int) $this->ownerB->account_id;
        $tables = StoreDataEngine::rekeyTables();
        $this->assertContains('orders', $tables);
        // A scoped tenant must not stop the transfer (it is an explicit platform bypass).
        TenantContext::current()->actAs($b);
        TenantFill::rekeyStore(DB::connection(), $this->storeA2->id, $b);
        TenantContext::current()->end();

        $byStore = DB::table('orders')->pluck('account_id', 'store_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($a, $byStore[$this->storeA1->id]);
        $this->assertSame($b, $byStore[$this->storeA2->id]);
        $this->assertSame($b, $byStore[$this->storeB->id]);
        // Every rekey table after a missing one is still processed (continue, not break).
        $missing = array_search('orders', $tables, true);
        $this->assertNotFalse($missing);
        DB::statement('ALTER TABLE orders RENAME TO orders_parked');
        try {
            TenantFill::rekeyStore(DB::connection(), $this->storeA2->id, $a);
        } finally {
            DB::statement('ALTER TABLE orders_parked RENAME TO orders');
        }
        $this->assertSame($b, (int) DB::table('orders')->where('store_id', $this->storeA2->id)->value('account_id'), 'parked table untouched');
    }

    public function test_adopt_user_rows_only_takes_store_less_unowned_rows_of_that_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->insertUpgrade($this->upgradeRow($user->id));
        $theirs = $this->insertUpgrade($this->upgradeRow($other->id));
        $owned = $this->insertUpgrade($this->upgradeRow($user->id) + ['account_id' => $this->ownerB->account_id]);

        TenantFill::adoptUserRows(DB::connection(), $user->id, (int) $this->ownerA->account_id);

        $acc = fn (int $id) => DB::table('upgrades')->where('upgrade_id', $id)->value('account_id');
        $this->assertSame((int) $this->ownerA->account_id, (int) $acc($mine));
        $this->assertNull($acc($theirs));
        $this->assertSame((int) $this->ownerB->account_id, (int) $acc($owned), 'never steals another account\'s row');
    }

    public function test_adopt_continues_past_a_missing_user_owned_table(): void
    {
        $owned = TenantTables::userOwned();
        $first = $owned[0];
        $this->assertLessThan(array_search('upgrades', $owned, true), 0);
        $user = User::factory()->create();
        $mine = $this->insertUpgrade($this->upgradeRow($user->id));
        DB::statement("ALTER TABLE {$first} RENAME TO {$first}_parked");
        try {
            TenantFill::adoptUserRows(DB::connection(), $user->id, (int) $this->ownerA->account_id);
        } finally {
            DB::statement("ALTER TABLE {$first}_parked RENAME TO {$first}");
        }
        $this->assertSame((int) $this->ownerA->account_id, (int) DB::table('upgrades')->where('upgrade_id', $mine)->value('account_id'));
    }

    /** @param array<string, mixed> $row  (PG has no "id" column on upgrades, so insertGetId fails; key is upgrade_id) */
    private function insertUpgrade(array $row): int
    {
        DB::table('upgrades')->insert($row);

        return (int) DB::table('upgrades')->max('upgrade_id');
    }

    /** @return array<string, mixed> */
    private function upgradeRow(int $userId): array
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('upgrades');
        $row = ['user_id' => $userId];
        foreach (['sub_id' => 'x', 'subscription_id' => 'x', 'upgrade_lasttime' => now()->timestamp, 'upgrade_expires' => now()->timestamp] as $col => $v) {
            if (in_array($col, $columns, true)) {
                $row[$col] = $v;
            }
        }

        return $row;
    }

    public function test_account_scope_limits_the_stores_table_to_the_acting_stores(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs((int) $this->ownerA->account_id, [$this->storeA2->id]);
        $this->assertSame([$this->storeA2->id], Store::orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $ctx->actAs((int) $this->ownerA->account_id);
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], Store::orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $ctx->actAs((int) $this->ownerB->account_id, [$this->storeA1->id]);
        $this->assertSame([], Store::pluck('id')->all(), 'a foreign store id never crosses accounts');
        $this->assertSame(3, Store::withoutGlobalScope(AccountScope::class)->count());
        $ctx->end();
    }
}
