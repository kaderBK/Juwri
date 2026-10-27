<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\PosShift;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-006: the row lock that serialises a POS drawer close against a handoff.
 *
 * A genuine two-connection race is not expressible under RefreshDatabase: every
 * write in the test runs on the one connection inside its wrapping transaction,
 * and sqlite drops FOR UPDATE entirely. So this proves the lock in the two ways
 * that ARE deterministic on every driver:
 *
 *  - the SQL/transaction path: closeShift()/handoffShift() re-read the shift row
 *    inside their own DB::transaction, and on MySQL/MariaDB/PostgreSQL that
 *    select carries FOR UPDATE;
 *  - the behaviour the lock exists for: a second caller holding a stale "open"
 *    copy still sees the first caller's committed close/handoff, so the first
 *    count and closed_at survive and only one successor drawer is opened.
 */
class PosShiftCloseHandoffLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    /**
     * Record every query (and the transaction level it ran at) while $callback
     * runs. The listener is ignored after the callback so later assertions and
     * teardown cannot pollute the capture.
     *
     * @return array{queries: list<array{sql: string, level: int}>, baseline: int}
     */
    private function recordQueries(callable $callback): array
    {
        $queries = [];
        $baseline = DB::connection()->transactionLevel();
        $collect = true;

        DB::listen(function ($query) use (&$queries, &$collect): void {
            if (! $collect) {
                return;
            }
            $queries[] = [
                'sql' => $query->sql,
                'level' => DB::connection($query->connectionName)->transactionLevel(),
            ];
        });

        try {
            $callback();
        } finally {
            $collect = false;
        }

        return ['queries' => $queries, 'baseline' => $baseline];
    }

    /**
     * Assert the operation issued a SELECT on pos_shifts inside its own
     * transaction, and that the select asked the engine for a row lock where the
     * driver supports one.
     *
     * @param  array{queries: list<array{sql: string, level: int}>, baseline: int}  $recorded
     */
    private function assertShiftRowLocked(array $recorded, string $operation): void
    {
        $selects = array_values(array_filter(
            $recorded['queries'],
            fn (array $q): bool => (bool) preg_match('/\bselect\b.*\bfrom\b[^\n]*pos_shifts/i', $q['sql'])
        ));
        $this->assertNotEmpty(
            $selects,
            $operation.' must re-read pos_shifts. Queries: '.json_encode(array_column($recorded['queries'], 'sql'))
        );

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $locked = array_values(array_filter(
                $selects,
                fn (array $q): bool => (bool) preg_match('/\bfor\s+update\b/i', $q['sql'])
            ));
            $this->assertNotEmpty(
                $locked,
                $operation.' must read the shift row FOR UPDATE on '.$driver.'. Queries: '.json_encode(array_column($selects, 'sql'))
            );
            $levels = array_column($locked, 'level');
        } else {
            /* SQLite compiles lockForUpdate() to nothing, so there is no SQL
               proof to assert. The lock's precondition still holds: the re-read
               must be inside closeShift()/handoffShift()'s own nested
               transaction, not merely the RefreshDatabase wrapper. */
            $levels = array_column($selects, 'level');
        }

        $this->assertGreaterThan(
            $recorded['baseline'],
            max($levels),
            $operation.' must do its locked re-read inside a DB::transaction (baseline level '.$recorded['baseline'].')'
        );
    }

    public function test_close_shift_locks_the_row_inside_its_transaction(): void
    {
        $r = $this->store();
        $staff = $this->staff($r);
        $shift = PosService::openShift($r, $staff, null, 100);
        $caller = PosShift::findOrFail($shift->id);

        $recorded = $this->recordQueries(fn () => PosService::closeShift($caller, 100.0));

        $this->assertShiftRowLocked($recorded, 'closeShift()');
    }

    public function test_handoff_shift_locks_the_row_inside_its_transaction(): void
    {
        $r = $this->store();
        $from = $this->staff($r);
        $to = $this->staff($r, 'cashier');
        PosService::openShift($r, $from, null, 100);

        $recorded = $this->recordQueries(fn () => PosService::handoffShift($r, $to, 100.0, null));

        $this->assertShiftRowLocked($recorded, 'handoffShift()');
    }

    /**
     * The lock's re-read is what saves the first close: the second caller still
     * holds an "open" model loaded before the first close, but closeShift() reads
     * the row under the lock, sees it closed, and returns the first Z-report
     * untouched instead of overwriting its count and closed_at.
     */
    public function test_second_close_with_a_stale_copy_keeps_the_first_result(): void
    {
        $r = $this->store();
        $staff = $this->staff($r);
        $shift = PosService::openShift($r, $staff, null, 100);

        $firstCaller = PosShift::findOrFail($shift->id);
        $secondCaller = PosShift::findOrFail($shift->id);
        $this->assertSame('open', $firstCaller->status);
        $this->assertSame('open', $secondCaller->status);

        $first = PosService::closeShift($firstCaller, 100.0);
        $this->travel(10)->seconds();
        $this->assertSame('open', $secondCaller->status, 'the second caller still holds its stale open copy');

        $second = PosService::closeShift($secondCaller, 5.0);

        $this->assertArrayNotHasKey('already_closed', $first);
        $this->assertTrue($second['already_closed']);
        $this->assertEquals(100, (float) $second['counted_cash']);
        $this->assertEquals($first['closed_at']->toDateTimeString(), $second['closed_at']->toDateTimeString());

        $row = $shift->fresh();
        $this->assertSame('closed', $row->status);
        $this->assertEquals(100, (float) $row->counted_cash, 'the second count must not overwrite the first');
        $this->assertEquals(0, (float) $row->over_short);
        $this->assertEquals($first['closed_at']->toDateTimeString(), $row->closed_at->toDateTimeString());
        $this->assertSame('closed', $secondCaller->status, 'the stale copy is refreshed to the stored close');
    }

    /**
     * Two callers loaded the same open shift before either handoff ran. The
     * second handoff must see the first one's successor under the lock and return
     * it, not close that successor and open a second drawer.
     */
    public function test_second_handoff_with_stale_copies_opens_one_successor_and_returns_the_first_result(): void
    {
        $r = $this->store();
        $from = $this->staff($r);
        $to = $this->staff($r, 'cashier');
        $shift = PosService::openShift($r, $from, null, 100);

        $firstCaller = PosShift::findOrFail($shift->id);
        $secondCaller = PosShift::findOrFail($shift->id);
        $this->assertSame('open', $firstCaller->status);
        $this->assertSame('open', $secondCaller->status);

        $first = PosService::handoffShift($r, $to, 100.0, null);
        $this->travel(10)->seconds();
        $this->assertSame('open', $secondCaller->status, 'the second caller still holds its stale open copy');

        $second = PosService::handoffShift($r, $to, 5.0, null);

        $this->assertSame($first['shift']->id, $second['shift']->id, 'both callers get the same successor');
        $this->assertEquals(100, (float) $first['closed']['counted_cash']);
        $this->assertEquals(100, (float) $second['closed']['counted_cash'], 'the second count must not overwrite the first');
        $this->assertEquals(
            $first['closed']['closed_at']->toDateTimeString(),
            $second['closed']['closed_at']->toDateTimeString()
        );

        $original = $shift->fresh();
        $this->assertSame('closed', $original->status);
        $this->assertEquals(100, (float) $original->counted_cash);

        $successor = $second['shift']->fresh();
        $this->assertSame('open', $successor->status);
        $this->assertSame((int) $shift->id, (int) $successor->handoff_from_shift_id);
        $this->assertEquals(100, (float) $successor->opening_float);
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertSame(2, PosShift::where('store_id', $r->id)->count(), 'one closed shift plus exactly one successor');
    }

    /** A replay carrying the first handoff's idempotency key returns that successor, never a second close/open. */
    public function test_handoff_replay_with_the_same_key_returns_the_same_successor(): void
    {
        $r = $this->store();
        $from = $this->staff($r);
        $to = $this->staff($r, 'cashier');
        PosService::openShift($r, $from, null, 100);
        $key = (string) Str::uuid();

        $first = PosService::handoffShift($r, $to, 100.0, null, $key);
        $this->travel(10)->seconds();
        $replay = PosService::handoffShift($r, $to, 5.0, null, $key);

        $this->assertSame($first['shift']->id, $replay['shift']->id);
        $this->assertEquals(100, (float) $replay['closed']['counted_cash'], 'the replay keeps the first count');
        $this->assertEquals(
            $first['closed']['closed_at']->toDateTimeString(),
            $replay['closed']['closed_at']->toDateTimeString()
        );

        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'open')->count());
        $this->assertSame(1, PosShift::where('store_id', $r->id)->where('status', 'closed')->count());
        $this->assertSame(2, PosShift::where('store_id', $r->id)->count(), 'the replay must not open a third shift');
    }
}
