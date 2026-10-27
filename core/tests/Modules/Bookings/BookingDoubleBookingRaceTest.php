<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\SlotEngine;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\Support\CommittedStoreFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-10 done-definition: "two holds, one slot" on a real server database (MySQL 8.4 and PostgreSQL 18; skipped on
 * sqlite). The guard is UNIQUE(staff_id, cell_at) on booking_slot_cells, so it holds across connections and
 * processes with no application lock:
 *  - separate PHP processes released at the same instant: exactly one hold per staff member wins;
 *  - an uncommitted overlapping hold on another connection makes the second wait and give the time up (lock
 *    timeout), a committed one makes it fail on the unique key, and the time is bookable again once released.
 *
 * Fixture rows are COMMITTED (no per-test transaction, other connections must see them) and deleted in tearDown.
 */
class BookingDoubleBookingRaceTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    /** @var list<int> */
    private array $stores = [];

    /** @return array<int, string|null> */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Cross-connection race: runs on MySQL and PostgreSQL (the sqlite suite covers the unique guard in HoldServiceTest).');
        }
    }

    protected function tearDown(): void
    {
        $stores = $this->stores;
        $this->stores = [];
        if ($stores !== [] && in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            foreach (['booking_slot_cells', 'booking_holds', 'booking_lines', 'bookings', 'booking_closed_days', 'booking_hours', 'booking_staff_services', 'booking_services', 'booking_staff'] as $table) {
                DB::table($table)->whereIn('store_id', $stores)->delete();
            }
            // Every other committed row of these stores (their default branches too), the stores, accounts and owners.
            CommittedStoreFixtures::purge($stores);
            TestPlans::delete(...$this->bookingPlans);
        }
        parent::tearDown();
    }

    /** @return array{0: Store, 1: mixed, 2: list<mixed>, 3: CarbonImmutable} */
    private function store(int $staffCount = 1): array
    {
        $store = $this->servicesStore('UTC', ['booking_max_days' => 30]);
        $this->stores[] = (int) $store->id;
        $service = $this->service($store);
        $staff = [];
        for ($i = 0; $i < $staffCount; $i++) {
            $staff[] = $this->staffMember($store, [$service], 'S'.$i, $i);
        }
        $this->weeklyHours($store, '00:00', '24:00');

        return [$store, $service, $staff, CarbonImmutable::now('UTC')->addDays(2)->setTime(12, 0)];
    }

    /**
     * Start $n child processes that all ask for the same hold at the same instant.
     *
     * @return list<string> their answers
     */
    private function race(int $n, Store $store, int $serviceId, string $staff, CarbonImmutable $start): array
    {
        $conn = config('database.connections.'.config('database.default'));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) ($conn['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($conn['port'] ?? ''),
            'DB_DATABASE' => (string) $conn['database'],
            'DB_USERNAME' => (string) ($conn['username'] ?? ''),
            'DB_PASSWORD' => (string) ($conn['password'] ?? ''),
            'DB_PREFIX' => (string) ($conn['prefix'] ?? ''),
        ]);
        $go = sprintf('%.6F', microtime(true) + 4.0);
        $procs = [];
        for ($i = 0; $i < $n; $i++) {
            $cmd = [PHP_BINARY, __DIR__.'/Support/hold_race_child.php', (string) $store->id, (string) $serviceId, $staff, (string) $start->getTimestamp(), $go];
            $pipes = [];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $stdout = trim((string) stream_get_contents($pipes[1]));
            $stderr = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $out[] = $stdout !== '' ? $stdout : 'error: '.substr($stderr, 0, 500);
        }
        sort($out);

        return $out;
    }

    public function test_two_processes_racing_for_one_slot_get_exactly_one_hold(): void
    {
        [$store, $service, $staff, $start] = $this->store();
        $answers = $this->race(2, $store, (int) $service->id, (string) $staff[0]->id, $start);

        $this->assertSame(['ok '.$staff[0]->id, 'slot_taken'], $answers);
        $this->assertSame(12, DB::table('booking_slot_cells')->where('staff_id', $staff[0]->id)->count());
        $this->assertSame(1, DB::table('booking_holds')->where('store_id', $store->id)->count());
    }

    public function test_any_staff_spreads_concurrent_holds_and_never_double_books(): void
    {
        [$store, $service, $staff, $start] = $this->store(2);
        $answers = $this->race(3, $store, (int) $service->id, 'any', $start);

        $this->assertSame(['ok '.$staff[0]->id, 'ok '.$staff[1]->id, 'slot_taken'], $answers);
        foreach ($staff as $member) {
            $this->assertSame(12, DB::table('booking_slot_cells')->where('staff_id', $member->id)->count());
        }
    }

    public function test_an_uncommitted_then_committed_overlap_on_another_connection_refuses_the_hold(): void
    {
        [$store, $service, $staff, $start] = $this->store();
        $driver = DB::connection()->getDriverName();
        $name = config('database.default');
        config(['database.connections.'.$name.'_probe' => config('database.connections.'.$name)]);
        $probe = DB::connection($name.'_probe');
        $driver === 'mysql'
            ? DB::statement('SET SESSION innodb_lock_wait_timeout = 1')
            : DB::statement("SET lock_timeout = '1s'");

        $engine = app(SlotEngine::class);
        $rows = array_map(fn (int $c) => [
            'account_id' => $store->account_id, 'store_id' => $store->id, 'staff_id' => $staff[0]->id,
            'cell_at' => CarbonImmutable::createFromTimestampUTC($c)->format('Y-m-d H:i:s'), 'booking_id' => null, 'hold_id' => null, 'expires_at' => null,
        ], $engine->cells($start->addMinutes(30), $start->addMinutes(45)));
        $hold = fn () => app(HoldService::class)->hold($store, [$service->id], $staff[0]->id, $start, 'venue', null);
        $code = function (callable $fn): string {
            try {
                $fn();
            } catch (BookingException $e) {
                return $e->errorCode;
            }

            return 'ok';
        };

        try {
            if ($driver === 'pgsql') {
                $probe->statement("SET app.tenant_bypass = 'on'");
            }
            $probe->beginTransaction();
            $probe->table('booking_slot_cells')->insert($rows); // another booking writing 12:30–12:45, not committed yet

            $this->assertSame('slot_taken', $code($hold)); // waits on the uncommitted key, then gives the time up

            $probe->commit();
            $this->assertSame('slot_taken', $code($hold)); // committed: the unique key refuses it

            $probe->table('booking_slot_cells')->where('staff_id', $staff[0]->id)->delete();
            $this->assertSame('ok', $code($hold)); // free again
        } finally {
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }
            DB::purge($name.'_probe');
            $driver === 'mysql'
                ? DB::statement('SET SESSION innodb_lock_wait_timeout = 50')
                : DB::statement('SET lock_timeout = 0');
        }
        $this->assertSame(12, DB::table('booking_slot_cells')->where('staff_id', $staff[0]->id)->whereNotNull('hold_id')->count());
    }

    public function test_hours_rows_are_committed_for_other_connections(): void
    {
        [$store] = $this->store();
        $name = config('database.default');
        config(['database.connections.'.$name.'_probe2' => config('database.connections.'.$name)]);
        $probe = DB::connection($name.'_probe2');
        if (DB::connection()->getDriverName() === 'pgsql') {
            $probe->statement("SET app.tenant_bypass = 'on'");
        }
        $this->assertSame(BookingHour::query()->where('store_id', $store->id)->count(), $probe->table('booking_hours')->where('store_id', $store->id)->count());
        DB::purge($name.'_probe2');
    }
}
