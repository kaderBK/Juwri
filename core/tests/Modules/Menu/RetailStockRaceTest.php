<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\CommittedStoreFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-08 (Opus review: stock race) — the customer stock take (Menu::reserveStock / MenuVariant::reserveStock) is ONE
 * conditional UPDATE (`stock_qty >= qty`), proven against the real engines (MySQL / InnoDB and PostgreSQL) with a
 * second session:
 *
 *  - while another session holds an uncommitted take of the last units, our take waits on the row lock (it never
 *    reads the stale count and takes the same units: a lost update would oversell);
 *  - after that session commits, our take sees the committed count and refuses — stock never goes below zero.
 *
 * sqlite has no row locks (and one writer at a time), so the class runs on MySQL and PostgreSQL only.
 */
class RetailStockRaceTest extends TestCase
{
    use RefreshDatabase;

    /** The second session must see committed fixture rows: no per-test transaction on a server database. */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    /** @var array{plan: list<object>, user: list<int>, store: list<int>} */
    private array $created = ['plan' => [], 'user' => [], 'store' => []];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Row-lock contention needs MySQL or PostgreSQL (sqlite has no row locks).');
        }
    }

    protected function tearDown(): void
    {
        $c = $this->created;
        $this->created = ['plan' => [], 'user' => [], 'store' => []];
        try {
            if ($c['store'] !== []) {
                $items = DB::table('items')->whereIn('store_id', $c['store'])->pluck('id')->all();
                DB::table('menu_variants')->whereIn('menu_id', $items)->delete();
                DB::table('items')->whereIn('id', $items)->delete();
                CommittedStoreFixtures::purge($c['store']); // branches and every other committed row of these stores
            }
            if ($c['user'] !== []) {
                // users.account_id references the account the owner's first store provisioned: unlink, then delete both
                $accounts = DB::table('accounts')->whereIn('owner_user_id', $c['user'])->pluck('id')->all();
                DB::table('users')->whereIn('id', $c['user'])->update(['account_id' => null]);
                DB::table('users')->whereIn('id', $c['user'])->delete();
                DB::table('accounts')->whereIn('id', $accounts)->delete();
            }
            TestPlans::delete(...$c['plan']);
        } finally {
            parent::tearDown();
        }
    }

    public function test_a_variant_take_waits_for_a_concurrent_take_and_never_oversells(): void
    {
        [, $variant] = $this->goods(2);

        $this->contend(
            fn ($probe) => $probe->table('menu_variants')->where('id', $variant->id)->where('stock_qty', '>=', 2)->decrement('stock_qty', 2),
            fn () => MenuVariant::reserveStock((int) $variant->id, 1)
        );

        // the other session's take is committed: the last units are gone, ours is refused, nothing goes negative
        $this->assertFalse(MenuVariant::reserveStock((int) $variant->id, 1));
        $this->assertSame(0, (int) DB::table('menu_variants')->where('id', $variant->id)->value('stock_qty'));
    }

    public function test_an_item_take_waits_for_a_concurrent_take_and_never_oversells(): void
    {
        [$item] = $this->goods(null, 3);

        $this->contend(
            fn ($probe) => $probe->table('items')->where('id', $item->id)->where('stock_qty', '>=', 2)->decrement('stock_qty', 2),
            fn () => Menu::reserveStock((int) $item->id, 2)
        );

        // one unit is left after the other session's take: two are refused, one is taken, then the item is out
        $this->assertFalse(Menu::reserveStock((int) $item->id, 2));
        $this->assertTrue(Menu::reserveStock((int) $item->id, 1));
        $this->assertFalse(Menu::reserveStock((int) $item->id, 1));
        $this->assertSame(0, (int) DB::table('items')->where('id', $item->id)->value('stock_qty'));
    }

    public function test_two_takes_of_the_last_unit_one_wins(): void
    {
        [, $variant] = $this->goods(1);

        $this->assertTrue(MenuVariant::reserveStock((int) $variant->id, 1));
        $this->assertFalse(MenuVariant::reserveStock((int) $variant->id, 1));
        $this->assertSame(0, (int) DB::table('menu_variants')->where('id', $variant->id)->value('stock_qty'));
    }

    /**
     * A second session takes stock inside an open transaction; our take must block on that row lock (and time out
     * after a second), then the other session commits.
     *
     * @param  callable(Connection): int  $otherTake
     * @param  callable(): bool  $ourTake
     */
    private function contend(callable $otherTake, callable $ourTake): void
    {
        $driver = DB::connection()->getDriverName();
        $default = (string) config('database.default');
        config(['database.connections.stock_probe' => config('database.connections.'.$default)]);
        $probe = DB::connection('stock_probe');
        $probe->beginTransaction();
        $blocked = false;
        try {
            $this->assertSame(1, $otherTake($probe), 'the other session took the units');
            $this->lockTimeout($driver, true);
            try {
                $ourTake();
            } catch (QueryException $e) {
                $blocked = (bool) preg_match('/lock wait timeout|lock timeout|deadlock|canceling statement/i', $e->getMessage());
            } finally {
                $this->lockTimeout($driver, false);
            }
            $probe->commit();
        } finally {
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }
            DB::purge('stock_probe');
        }
        $this->assertTrue($blocked, 'our take waited on the other session\'s row lock (no stale read, no lost update)');
    }

    private function lockTimeout(string $driver, bool $short): void
    {
        if ($driver === 'mysql') {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.($short ? 1 : 50));
        } else {
            DB::statement($short ? "SET lock_timeout = '1s'" : 'RESET lock_timeout');
        }
    }

    /** @return array{0: Menu, 1: MenuVariant} an item (optionally stock-tracked) with one variant cell of `$variantStock` */
    private function goods(?int $variantStock, ?int $itemStock = null): array
    {
        $plan = TestPlans::create([
            'name' => 'R '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']), 'status' => 1,
        ]);
        $this->created['plan'][] = $plan;
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->created['user'][] = $owner->id;
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'race-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'Race',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St',
            'phone' => '1', 'store_type' => 'retail', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->created['store'][] = $store->id;
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Shirt', 'price' => 139, 'active' => 1,
            'track_inventory' => $itemStock !== null, 'stock_qty' => $itemStock]);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'store_id' => $store->id, 'price' => 139, 'options' => (object) [],
            'stock_qty' => $variantStock, 'active' => true, 'position' => 1]);

        return [$item->fresh(), $variant->fresh()];
    }
}
