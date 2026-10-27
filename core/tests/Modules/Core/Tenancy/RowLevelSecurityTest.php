<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantDatabaseSession;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * P1-04 slice 6 (J29-2): PostgreSQL row-level security — the database safety net under the app scope.
 *
 * PostgreSQL only (skipped on SQLite/MySQL; run with DB_CONNECTION=pgsql). The suite connects as
 * the table owner, which RLS exempts unless FORCEd; each test FORCEs the tenant tables inside its transaction
 * (rolled back with it), so the owner is filtered exactly like the Cloud app role (a non-owner role).
 */
class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (tools/p104/rls_pg.sh on the runner).');
        }
        $this->ownerA = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->ownerB = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->storeA = $this->store($this->ownerA);
        $this->storeB = $this->store($this->ownerB);
        $this->ownerA->refresh();
        $this->ownerB->refresh();
        foreach ([$this->storeA, $this->storeB] as $store) {
            Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        }
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'title' => 'S', 'slug' => 'rls-'.Str::lower(Str::random(8)),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    /**
     * Make this connection subject to RLS like the Cloud app role. The runner's test user owns the tables (and has
     * no CREATEROLE), so the tenant tables are FORCEd for the owner — inside the test transaction, rolled back with it.
     */
    private function asAppRole(): void
    {
        foreach (array_keys(RowLevelSecurity::status(DB::connection())) as $table) {
            DB::statement('ALTER TABLE "'.$table.'" FORCE ROW LEVEL SECURITY');
        }
    }

    /** @return list<int> */
    private function orderStores(): array
    {
        return DB::table('orders')->orderBy('store_id')->pluck('store_id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_every_tenant_table_has_rls_and_the_generated_policy_and_is_not_forced(): void
    {
        $status = RowLevelSecurity::status(DB::connection());
        $this->assertNotEmpty($status);
        // Edition-aware but strict: the generated list also names tables of an edition package; the expected set is
        // every listed table this install migrated (Community alone, or with a package), and every installed tenant
        // table must be listed.
        $live = array_keys(TenantSchema::live());
        $listed = array_keys(RowLevelSecurity::tables());
        $this->assertSame([], array_values(array_diff($live, $listed)), 'every installed tenant table is in the generated list');
        $expected = array_values(array_intersect($listed, $live));
        $this->assertGreaterThan(100, count($expected), 'too few installed tenant tables found');
        $covered = array_keys($status);
        sort($expected);
        sort($covered);
        $this->assertSame($expected, $covered, 'every generated tenant table that is installed exists and is covered');
        foreach ($status as $table => $s) {
            $this->assertTrue($s['rls'], "{$table}: RLS enabled");
            $this->assertTrue($s['policy'], "{$table}: juwri_tenant policy");
            $this->assertFalse($s['force'], "{$table}: not FORCEd (the owner/migration role is exempt)");
        }
        $this->artisan('tenancy:rls', ['action' => 'status'])->assertSuccessful();
    }

    public function test_an_unscoped_query_returns_zero_foreign_rows(): void
    {
        $this->asAppRole();
        TenantContext::current()->actAs((int) $this->ownerA->account_id);

        // Raw query builder and Eloquent WITHOUT the app scope: only RLS stands between A and B here.
        $this->assertSame([$this->storeA->id], $this->orderStores());
        $this->assertSame([$this->storeA->id], Order::withoutGlobalScopes()->pluck('store_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([$this->storeA->id], DB::table('stores')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, DB::table('orders')->where('store_id', $this->storeB->id)->count());
        $joined = DB::table('orders')->join('stores', 'stores.id', '=', 'orders.store_id')->pluck('stores.id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([$this->storeA->id], $joined);

        TenantContext::current()->actAs((int) $this->ownerB->account_id);
        $this->assertSame([$this->storeB->id], $this->orderStores());
    }

    public function test_writes_into_another_account_are_refused(): void
    {
        $this->asAppRole();
        TenantContext::current()->actAs((int) $this->ownerA->account_id);

        // UPDATE / DELETE cannot even see B's rows.
        $this->assertSame(0, DB::table('orders')->where('store_id', $this->storeB->id)->update(['status' => 'hacked']));
        $this->assertSame(0, DB::table('orders')->where('store_id', $this->storeB->id)->delete());

        // INSERT with B's account fails the WITH CHECK (42501); a savepoint keeps the test transaction usable.
        try {
            DB::transaction(fn () => DB::table('activity_logs')->insert([
                'account_id' => $this->ownerB->account_id, 'store_id' => $this->storeB->id, 'action' => 'x',
            ]));
            $this->fail('insert into a foreign account passed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        }
        // Moving an own row into B's account is refused too.
        try {
            DB::transaction(fn () => DB::table('orders')->where('store_id', $this->storeA->id)->update(['account_id' => $this->ownerB->account_id]));
            $this->fail('re-key into a foreign account passed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('row-level security', $e->getMessage());
        }

        TenantContext::bypass(function () {
            $this->assertSame(['new', 'new'], DB::table('orders')->orderBy('store_id')->pluck('status')->all());
        });
    }

    public function test_nullable_account_tables_show_platform_rows_to_every_tenant(): void
    {
        DB::table('activity_logs')->insert([
            ['account_id' => null, 'store_id' => null, 'action' => 'platform'],
            ['account_id' => $this->ownerA->account_id, 'store_id' => $this->storeA->id, 'action' => 'a'],
            ['account_id' => $this->ownerB->account_id, 'store_id' => $this->storeB->id, 'action' => 'b'],
        ]);
        $this->asAppRole();
        TenantContext::current()->actAs((int) $this->ownerA->account_id);

        $this->assertSame(['a', 'platform'], DB::table('activity_logs')->whereIn('action', ['a', 'b', 'platform'])->orderBy('action')->pluck('action')->all());
    }

    public function test_platform_paths_bypass_explicitly(): void
    {
        $this->asAppRole();
        $all = [$this->storeA->id, $this->storeB->id];

        // No tenant context (public storefront, admin, console, platform jobs): the app sets the bypass flag.
        $this->assertSame($all, $this->orderStores());
        $this->assertSame('on', DB::selectOne("SELECT current_setting('app.tenant_bypass', true) AS v")->v);

        // Inside a tenant context, TenantContext::bypass() is the explicit opt-out.
        TenantContext::current()->actAs((int) $this->ownerA->account_id);
        $this->assertSame($all, TenantContext::bypass(fn () => $this->orderStores()));
        $this->assertSame([$this->storeA->id], $this->orderStores());
    }

    public function test_a_session_that_never_set_the_tenant_sees_nothing(): void
    {
        $this->asAppRole();
        $pdo = DB::connection()->getPdo();
        // What psql, a reporting tool or a foreign pooled client would get: no settings → fail-closed.
        $pdo->exec("SELECT set_config('app.tenant_bypass', '', false), set_config('app.account_id', '', false)");
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM orders')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT count(*) FROM stores')->fetchColumn());

        // The owner (migration role) is not subject to RLS: the migration never FORCEs it.
        $pdo->exec('ALTER TABLE orders NO FORCE ROW LEVEL SECURITY');
        $this->assertSame(2, (int) $pdo->query('SELECT count(*) FROM orders')->fetchColumn());
        TenantDatabaseSession::forget(DB::connection()); // the raw PDO calls bypassed the session cache
    }

    public function test_session_settings_follow_the_context_and_survive_a_rollback(): void
    {
        $a = (int) $this->ownerA->account_id;
        $b = (int) $this->ownerB->account_id;
        $setting = fn () => DB::selectOne("SELECT current_setting('app.account_id', true) AS a, current_setting('app.tenant_bypass', true) AS b");

        TenantContext::current()->actAs($a);
        $this->assertSame([(string) $a, ''], array_values((array) $setting()));

        DB::beginTransaction();
        TenantContext::current()->actAs($b);
        $this->assertSame((string) $b, $setting()->a);
        DB::rollBack(); // PostgreSQL reverts the set_config done inside the savepoint → re-applied on the next query
        $this->assertSame((string) $b, $setting()->a);

        TenantContext::current()->end();
        $this->assertSame('on', $setting()->b);
    }

    public function test_a_new_owner_can_create_the_first_store_under_rls(): void
    {
        $newOwner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->assertNull($newOwner->account_id);
        Route::middleware(['web', 'auth'])->post('__rls/first-store', function () {
            $store = $this->store(auth()->user());

            return response()->json([
                'store' => $store->id,
                'visible' => Store::whereKey($store->id)->exists(),
                'orders' => Order::count(),
            ]);
        });
        $this->asAppRole();

        $res = $this->actingAs($newOwner)->postJson('__rls/first-store')->assertOk();

        $this->assertTrue($res->json('visible'), 'the new store is visible in the same request');
        $this->assertSame(0, $res->json('orders'), 'and nothing of the other accounts');
        $account = (int) TenantContext::bypass(fn () => DB::table('users')->where('id', $newOwner->id)->value('account_id'));
        $this->assertGreaterThan(0, $account);
        $this->assertSame($account, (int) TenantContext::bypass(fn () => DB::table('stores')->where('id', $res->json('store'))->value('account_id')));
    }

    public function test_a_tenant_request_cannot_read_another_account_through_raw_sql(): void
    {
        Route::middleware(['web', 'auth'])->get('__rls/raw', fn () => response()->json([
            'orders' => DB::table('orders')->orderBy('store_id')->pluck('store_id')->map(fn ($v) => (int) $v)->all(),
        ]));
        $this->asAppRole();

        $this->actingAs($this->ownerB)->getJson('__rls/raw')->assertOk()->assertExactJson(['orders' => [$this->storeB->id]]);
    }

    /**
     * D-P1-21-15: coupons, supplier payments and wallet rows carry account_id and are covered by RLS; the wallet still
     * credits another account's user from a tenant request (WalletService is a platform operation on an explicit user).
     */
    public function test_coupons_supplier_payments_and_wallet_rows_are_isolated(): void
    {
        $supplierB = TenantContext::bypass(fn () => DB::table('suppliers')->insertGetId(['account_id' => $this->ownerB->account_id, 'store_id' => $this->storeB->id, 'name' => 'B', 'active' => true]));
        TenantContext::bypass(function () use ($supplierB): void {
            DB::table('supplier_payments')->insert(['supplier_id' => $supplierB, 'store_id' => $this->storeB->id, 'account_id' => $this->ownerB->account_id, 'amount_minor' => 100]);
            DB::table('coupons')->insert(['code' => 'RLSB', 'user_id' => $this->ownerB->id, 'account_id' => $this->ownerB->account_id, 'percentage_rate' => 5]);
            DB::table('coupons')->insert(['code' => 'RLSPLAT', 'issuer_scope' => 'platform', 'percentage_rate' => 5]);
            DB::table('wallet_transactions')->insert(['user_id' => $this->ownerB->id, 'account_id' => $this->ownerB->account_id, 'amount_minor' => 1000, 'balance_after_minor' => 1000, 'currency_code' => WalletService::currency()]);
        });
        $this->asAppRole();
        TenantContext::current()->actAs((int) $this->ownerA->account_id);

        $this->assertSame(0, DB::table('supplier_payments')->count());
        $this->assertSame(['RLSPLAT'], DB::table('coupons')->pluck('code')->all(), 'platform coupons (NULL account) only');
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertSame(0, DB::table('coupons')->where('code', 'RLSB')->update(['percentage_rate' => 99]));

        $this->assertSame(12.5, WalletService::credit($this->ownerB, 2.5, 'sale', 'rls-sale'));
        $this->assertSame(0, DB::table('wallet_transactions')->count(), 'still invisible to A');
        $this->assertSame(2, TenantContext::bypass(fn () => DB::table('wallet_transactions')->where('user_id', $this->ownerB->id)->count()));
    }

    public function test_a_job_dispatched_by_a_reads_only_a_even_with_raw_sql(): void
    {
        $this->asAppRole();
        TenantContext::current()->actAs((int) $this->ownerA->account_id);
        RlsProbeJob::$seen = null;

        RlsProbeJob::dispatch(); // sync queue: carries A through the Context payload, restored by TenantJobs

        $this->assertSame([$this->storeA->id], RlsProbeJob::$seen);
    }
}

/** Raw SQL inside a queued job — only RLS (+ the restored context) stands between accounts. */
class RlsProbeJob implements ShouldQueue
{
    use \Illuminate\Foundation\Bus\Dispatchable, Queueable;

    /** @var list<int>|null */
    public static ?array $seen = null;

    public function handle(): void
    {
        self::$seen = DB::table('orders')->orderBy('store_id')->pluck('store_id')->map(fn ($v) => (int) $v)->all();
    }
}
