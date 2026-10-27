<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use App\Http\Middleware\ApiTokenAuth;
use Juwri\Modules\Content\Jobs\PingIndexNow;
use Juwri\Modules\Messaging\Jobs\SendSmsJob;
use Juwri\Modules\Integrations\Models\ApiToken;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\TenantConnectionResolver;
use Juwri\Core\Services\Tenancy\TenantCache;
use Juwri\Core\Services\Tenancy\TenantConnections;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Services\Tenancy\TenantStorage;
use LogicException;
use Tests\TestCase;

/**
 * P1-04 slice 7 (J25-6, J29-3/4): tenant-aware infrastructure — queued jobs carry the dispatcher's tenant (and can
 * never read another account's rows), per-account cache namespace, per-account storage paths, session separation,
 * per-account rate limit, account_id in the log context, and the tenant connection resolver.
 */
class TenantInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        ProbeTenantJob::$seen = [];
        $this->ownerA = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->ownerB = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->storeA = $this->store($this->ownerA);
        $this->storeB = $this->store($this->ownerB);
        $this->ownerA->refresh();
        $this->ownerB->refresh();
        foreach ([$this->storeA, $this->storeB] as $store) {
            Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        }
        Route::middleware(['web', 'auth'])->post('__tenancy/dispatch', function () {
            ProbeTenantJob::dispatch((int) request('foreign'))->onConnection((string) request('conn', 'sync'));

            // a sync job inside the request hands the request's own context back afterwards
            return response()->json(['after' => Order::orderBy('store_id')->pluck('store_id')->map(fn ($v) => (int) $v)->all()]);
        });
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'title' => 'S', 'slug' => 'inf-'.Str::lower(Str::random(8)),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    // ---- queue --------------------------------------------------------------------------------------------

    public function test_a_sync_job_dispatched_by_account_a_cannot_read_account_b(): void
    {
        $res = $this->actingAs($this->ownerA)->postJson('__tenancy/dispatch', ['foreign' => $this->storeB->id, 'conn' => 'sync'])->assertOk();

        $this->assertSame([[
            'orders' => [$this->storeA->id], 'foreign' => 0, 'account' => (int) $this->ownerA->account_id,
        ]], ProbeTenantJob::$seen);
        $this->assertSame([$this->storeA->id], $res->json('after'));
    }

    public function test_a_worker_job_restores_the_dispatchers_tenant(): void
    {
        $this->actingAs($this->ownerB)->postJson('__tenancy/dispatch', ['foreign' => $this->storeA->id, 'conn' => 'database'])->assertOk();
        $this->assertSame([], ProbeTenantJob::$seen, 'queued, not run yet');
        TenantContext::current()->end();
        $this->app['auth']->forgetGuards();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $this->assertSame([[
            'orders' => [$this->storeB->id], 'foreign' => 0, 'account' => (int) $this->ownerB->account_id,
        ]], ProbeTenantJob::$seen);
        $this->assertFalse(TenantContext::current()->active(), 'the worker context is gone after the job');
    }

    public function test_a_job_dispatched_outside_a_tenant_context_runs_unscoped(): void
    {
        ProbeTenantJob::dispatch($this->storeB->id);

        $this->assertSame([$this->storeA->id, $this->storeB->id], ProbeTenantJob::$seen[0]['orders']);
        $this->assertNull(ProbeTenantJob::$seen[0]['account']);
    }

    public function test_platform_jobs_run_unscoped_even_when_a_tenant_dispatched_them(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs((int) $this->ownerA->account_id);
        $job = fn (string $class) => new SyncJob($this->app, (string) json_encode(['displayName' => $class, 'job' => 'x', 'data' => []]), 'sync', 'default');

        Context::addHidden(TenantJobs::KEY, ['account_id' => (int) $this->ownerB->account_id, 'store_ids' => null]);
        TenantJobs::processing($job(PingIndexNow::class));
        $this->assertFalse($ctx->active(), 'PLATFORM job: unscoped');
        TenantJobs::finished();
        $this->assertSame((int) $this->ownerA->account_id, $ctx->accountId(), 'previous context restored');

        TenantJobs::processing($job(SendSmsJob::class));
        $this->assertSame((int) $this->ownerB->account_id, $ctx->accountId(), 'TENANT job: the carried account');
        TenantJobs::finished();
        $this->assertSame((int) $this->ownerA->account_id, $ctx->accountId());
    }

    // ---- cache --------------------------------------------------------------------------------------------

    public function test_a_tenant_cache_value_of_a_is_never_served_to_b(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs((int) $this->ownerA->account_id);
        TenantCache::store()->put('dash:counts', 'A-secret', 60);
        $this->assertSame('A-secret', TenantCache::store()->remember('dash:counts', 60, fn () => 'recomputed'));

        $ctx->actAs((int) $this->ownerB->account_id);
        $this->assertNull(TenantCache::store()->get('dash:counts'));
        $this->assertSame('B-value', TenantCache::store()->remember('dash:counts', 60, fn () => 'B-value'));
        $this->assertNotSame(TenantCache::key('dash:counts'), TenantCache::key('dash:counts', (int) $this->ownerA->account_id));
        $this->assertSame('A-secret', Cache::get('tenant:'.$this->ownerA->account_id.':dash:counts'));

        $ctx->end();
        $this->assertSame('plain', TenantCache::scopedKey('plain'), 'platform callers keep their key');
        $this->expectException(LogicException::class);
        TenantCache::store()->get('dash:counts'); // no tenant, no explicit account: fail closed
    }

    // ---- storage ------------------------------------------------------------------------------------------

    public function test_tenant_upload_paths_are_namespaced_per_account(): void
    {
        $a = (int) $this->ownerA->account_id;
        $b = (int) $this->ownerB->account_id;
        TenantContext::current()->actAs($a);
        $this->assertSame("accounts/{$a}/staff-docs/{$this->storeA->id}", TenantStorage::dir("staff-docs/{$this->storeA->id}/"));
        $this->assertSame("accounts/{$b}/pod/7", TenantStorage::dir('pod/7', $b));
        $this->assertStringStartsNotWith("accounts/{$a}/", TenantStorage::dir('x', $b));
        $this->assertSame('local', TenantStorage::disk('local'), 'no region disks configured: caller disk');

        config(['tenancy.storage.region_disks' => ['global' => 'tenant_global']]);
        $this->assertSame('tenant_global', TenantStorage::disk('local'));

        TenantContext::current()->end();
        $this->expectException(LogicException::class);
        TenantStorage::dir('staff-docs/1');
    }

    // ---- session, rate limit, log context, connection resolver ---------------------------------------------

    public function test_per_tenant_session_keys_are_dropped_when_the_account_changes(): void
    {
        Route::middleware(['web', 'auth'])->get('__tenancy/session', fn () => response()->json([
            'n' => Order::count(),
            'store' => session('dashboard_store_id'),
            'locale' => session('locale'),
            'account' => session('juwri.tenant_account'),
        ]));

        // Session last used for account B (e.g. an impersonation hand-over) — now owner A signs in on it.
        $this->withSession(['dashboard_store_id' => $this->storeB->id, 'locale' => 'ar', 'juwri.tenant_account' => (int) $this->ownerB->account_id])
            ->actingAs($this->ownerA)->getJson('__tenancy/session')->assertOk()
            ->assertJson(['n' => 1, 'store' => null, 'locale' => 'ar', 'account' => (int) $this->ownerA->account_id]);

        // Same account: the selection survives.
        $this->withSession(['dashboard_store_id' => $this->storeA->id, 'juwri.tenant_account' => (int) $this->ownerA->account_id])
            ->actingAs($this->ownerA)->getJson('__tenancy/session')->assertOk()->assertJson(['store' => $this->storeA->id]);
    }

    public function test_the_owner_api_has_one_budget_per_account_across_its_tokens(): void
    {
        config(['tenancy.rate_limits.owner_api_per_account' => 2, 'api.owner_rate' => 100]);
        Route::middleware(['api', 'throttle:owner-api', ApiTokenAuth::class.':any'])->get('__tenancy/rl', fn () => response()->json(['ok' => true]));
        $token = function (User $u): string {
            $raw = Str::random(40);
            ApiToken::create(['user_id' => $u->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read']]);

            return $raw;
        };
        [$a1, $a2, $b1] = [$token($this->ownerA), $token($this->ownerA), $token($this->ownerB)];
        $get = fn (string $raw) => $this->getJson('__tenancy/rl', ['Authorization' => 'Bearer '.$raw]);

        $get($a1)->assertOk();
        $get($a2)->assertOk();
        $get($a2)->assertStatus(429); // account A's budget is shared by both of its tokens
        $get($b1)->assertOk();        // account B is untouched
    }

    public function test_log_context_carries_the_account(): void
    {
        Route::middleware(['web', 'auth'])->get('__tenancy/log', fn () => response()->json([
            'n' => Order::count(), 'account' => Context::get('account_id'),
        ]));

        $this->actingAs($this->ownerA)->getJson('__tenancy/log')->assertOk()->assertJson(['account' => (int) $this->ownerA->account_id]);
        $this->assertFalse(Context::has('account_id'), 'removed when the tenant request ends');

        TenantContext::current()->actAs((int) $this->ownerB->account_id, [$this->storeB->id]);
        $this->assertSame((int) $this->ownerB->account_id, Context::get('account_id'));
        $this->assertSame($this->storeB->id, Context::get('store_id'));
    }

    public function test_tenant_models_ask_the_connection_resolver(): void
    {
        $this->assertNull((new Order)->getConnectionName(), 'shared database: default connection');

        $a = (int) $this->ownerA->account_id;
        TenantConnections::use(new class($a) implements TenantConnectionResolver
        {
            public function __construct(private int $silo) {}

            public function connectionFor(?int $accountId): ?string
            {
                return $accountId === $this->silo ? 'tenant_silo_'.$accountId : null;
            }

            public function isolatesAccounts(): bool
            {
                return true;
            }
        });
        try {
            TenantContext::current()->actAs($a);
            $this->assertSame('tenant_silo_'.$a, (new Order)->getConnectionName());
            TenantContext::current()->actAs((int) $this->ownerB->account_id);
            $this->assertNull((new Order)->getConnectionName());
        } finally {
            TenantContext::current()->end();
            TenantConnections::use(null);
        }
        $this->assertSame(DB::getDefaultConnection(), (new Order)->getConnection()->getName());
    }
}

/** Records what a queued job can see. */
class ProbeTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function __construct(public int $foreignStore) {}

    public function handle(): void
    {
        self::$seen[] = [
            'orders' => Order::orderBy('store_id')->pluck('store_id')->map(fn ($v) => (int) $v)->all(),
            'foreign' => Order::where('store_id', $this->foreignStore)->count(),
            'account' => Context::get('account_id'),
        ];
    }
}
