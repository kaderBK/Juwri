<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Database;

use App\Support\HostingPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** P6-145: sessions / cache / cache_locks tables exist, round-trip with the database drivers, and migrate down/up cleanly. */
class SessionCacheTablesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'app/Core/Database/Migrations/2026_10_14_100000_create_sessions_and_cache_tables.php';

    public function test_tables_have_laravels_standard_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']));
        $this->assertTrue(Schema::hasColumns('cache', ['key', 'value', 'expiration']));
        $this->assertTrue(Schema::hasColumns('cache_locks', ['key', 'owner', 'expiration']));
        $indexed = array_merge(...array_column(Schema::getIndexes('sessions'), 'columns'));
        $this->assertContains('user_id', $indexed);
        $this->assertContains('last_activity', $indexed);
    }

    public function test_session_round_trip_with_the_database_driver(): void
    {
        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        Route::middleware('web')->get('/_p6145/put', function () {
            session(['p6145' => 'kept']);

            return 'ok';
        });
        Route::middleware('web')->get('/_p6145/get', fn () => (string) session('p6145', 'lost'));

        $put = $this->get('/_p6145/put')->assertOk();
        $this->assertSame(1, DB::table('sessions')->count());
        $cookie = $put->getCookie((string) config('session.cookie'), false);
        $this->assertNotNull($cookie);

        $this->withCookie((string) config('session.cookie'), (string) $cookie->getValue())
            ->get('/_p6145/get')->assertOk()->assertSeeText('kept');
    }

    public function test_cache_and_lock_round_trip_with_the_database_store(): void
    {
        $store = Cache::store('database');
        $store->put('p6145', 'v', 60);
        $this->assertSame('v', $store->get('p6145'));
        $lock = $store->lock('p6145-lock', 10);
        $this->assertTrue($lock->get());
        $this->assertSame(1, DB::table('cache_locks')->count());
        $lock->release(); // (contention is not asserted: on PostgreSQL the losing INSERT aborts the test transaction)
        $this->assertSame(0, DB::table('cache_locks')->count());
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%p6145')->count());
    }

    /** Calls down()/up() directly and always restores the tables (DDL commits on MySQL, so later tests need them). */
    public function test_migration_down_and_up_and_keeps_hand_made_tables(): void
    {
        $migration = require base_path(self::MIGRATION);
        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('sessions'));
            $this->assertFalse(Schema::hasTable('cache'));
            $this->assertFalse(Schema::hasTable('cache_locks'));

            $migration->up();
            $this->assertTrue(Schema::hasColumns('sessions', ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity']));
            $migration->up(); // re-run is a no-op (guarded)

            // An install that ran `php artisan session:table` by hand: the guard leaves its table and rows alone.
            $migration->down();
            Schema::create('sessions', function ($t) {
                $t->string('id')->primary();
                $t->longText('payload');
                $t->integer('last_activity');
            });
            DB::table('sessions')->insert(['id' => 'hand', 'payload' => 'x', 'last_activity' => 1]);
            $migration->up();
            $this->assertSame(1, DB::table('sessions')->where('id', 'hand')->count());
            $this->assertTrue(Schema::hasTable('cache'));
            $this->assertTrue(Schema::hasTable('cache_locks'));
        } finally {
            $migration->down();
            $migration->up();
        }
    }

    public function test_preflight_sees_the_real_tables(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database', 'queue.default' => 'database']);
        $rows = collect(app(HostingPreflight::class)->run('shared', '8.4.3'))->keyBy('check');
        foreach (['Session tables', 'Cache tables', 'Queue tables'] as $check) {
            $this->assertSame('ok', $rows[$check]['status'], $check.': '.$rows[$check]['detail']);
        }

        $migration = require base_path(self::MIGRATION);
        try {
            Schema::drop('sessions');
            $rows = collect(app(HostingPreflight::class)->run('shared', '8.4.3'))->keyBy('check');
            $this->assertSame('fail', $rows['Session tables']['status']);
        } finally {
            $migration->up();
        }
    }
}
