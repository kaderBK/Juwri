<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * H14: PosService::refund()'s row lock, proven against the real engine.
 *
 * refund() reads the sum of prior refunds and checks the cap under a
 * `lockForUpdate()` to serialize concurrent refunds on one order. But
 * lockForUpdate() compiles to NOTHING on sqlite (Laravel's SQLite grammar has
 * no lock support), so every refund-cap test on sqlite proves the arithmetic
 * and never the mutual exclusion the lock exists for. This class runs on MySQL
 * only (TestCase's #[Group('mysql')] guard) and asserts the lock both in the
 * emitted SQL and in real cross-connection contention.
 */
#[Group('mysql')]
class MysqlLanePosRefundLockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * refund() opens its OWN DB::transaction and proof 2 needs a second
     * connection to see the committed fixture rows. RefreshDatabase's default
     * per-test transaction would hide them and the contention test would pass
     * for the wrong reason.
     *
     * Only off on a real server DB. On sqlite :memory: (where this class is
     * skipped) an empty list made RefreshDatabase migrate the worker's in-memory
     * DB without caching its PDO while still marking it migrated, so EVERY later
     * test in that worker met an empty database ("no such table: plans") — the
     * Pos folder failed in parallel/random order whenever this class ran first.
     *
     * @return array<int, string|null>
     */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    /** @var array{plan: list<object>, user: list<int>, store: list<int>, menu: list<int>, order: list<int>} */
    private array $created = ['plan' => [], 'user' => [], 'store' => [], 'menu' => [], 'order' => []];

    /**
     * Because the per-test transaction is off, every row this class creates is
     * COMMITTED. Leaving them behind leaked a `P xxxxx` plan (and its user /
     * store / menu / order) per test into the shared MySQL DB, which later broke
     * plan-count assertions in unrelated tests (PlanAdminPolishTest, UserPlanReaderTest,
     * SeoPricingFaqSchemaTest) once this ran earlier in the same filter run.
     * Delete exactly what we made, children first, with FK checks off for the
     * delete only (a session variable, not DDL — no implicit commit).
     */
    protected function tearDown(): void
    {
        $c = $this->created;
        $this->created = ['plan' => [], 'user' => [], 'store' => [], 'menu' => [], 'order' => []];

        try {
            Schema::disableForeignKeyConstraints();
            if ($c['order'] !== []) {
                if (Schema::hasTable('order_items')) {
                    DB::table('order_items')->whereIn('order_id', $c['order'])->delete();
                }
                DB::table('orders')->whereIn('id', $c['order'])->delete();
            }
            if ($c['store'] !== [] && Schema::hasTable('branches')) {
                DB::table('branches')->whereIn('store_id', $c['store'])->delete();
            }
            if ($c['menu'] !== []) {
                DB::table('items')->whereIn('id', $c['menu'])->delete();
            }
            if ($c['store'] !== []) {
                DB::table('stores')->whereIn('id', $c['store'])->delete();
            }
            if ($c['user'] !== []) {
                // The owner's account (AccountProvisioner, created with the first store) is committed too.
                DB::table('accounts')->whereIn('owner_user_id', $c['user'])->delete();
                DB::table('users')->whereIn('id', $c['user'])->delete();
            }
            TestPlans::delete(...$c['plan']);
        } finally {
            Schema::enableForeignKeyConstraints();
            parent::tearDown();
        }
    }

    private function paidOrder(): Order
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $this->created['plan'][] = $plan;
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->created['user'][] = $owner->id;
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->created['store'][] = $r->id;
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish', 'price' => 20, 'active' => 1]);
        $this->created['menu'][] = $item->id;
        $order = OrderApiService::create((int) $r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ]);
        $this->created['order'][] = $order->id;
        $order->forceFill(['is_paid' => 1])->save();

        return $order->fresh();
    }

    public function test_refund_emits_for_update_on_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        $order = $this->paidOrder();

        DB::enableQueryLog();
        PosService::refund($order, null, 1.0, 'cash', 'test');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $emittedForUpdate = collect($log)->contains(fn ($q) => (bool) preg_match('/for update/i', $q['query']));
        $this->assertTrue($emittedForUpdate, 'refund() must emit a FOR UPDATE lock clause on MySQL. Queries: '.json_encode(collect($log)->pluck('query')->all()));

        // Clean up (no test transaction to unwind).
        DB::table('orders')->where('id', $order->id)->delete();
    }

    public function test_a_second_connection_cannot_take_the_order_row_while_a_refund_holds_it(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        $order = $this->paidOrder();

        // A genuinely separate session.
        config(['database.connections.mysql_probe' => config('database.connections.mysql')]);
        $probe = DB::connection('mysql_probe');

        try {
            $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $probe->beginTransaction();
            // The probe now holds the order row.
            $probe->table('orders')->where('id', $order->id)->lockForUpdate()->first();

            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/Lock wait timeout|Deadlock/i');

            // The default connection's refund must block on the probe's lock and
            // time out — proof the lock is real (impossible to observe on sqlite).
            PosService::refund($order, null, 1.0, 'cash', 'test');
        } finally {
            $probe->rollBack();
            DB::purge('mysql_probe');
            DB::table('orders')->where('id', $order->id)->delete();
        }
    }
}
