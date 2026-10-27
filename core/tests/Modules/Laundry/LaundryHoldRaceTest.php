<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Laundry;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Tests\Modules\Laundry\Concerns\LaundryHoldFixtures;
use Tests\Support\CommittedStoreFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-11 done-definition: "two holds, one place" on a real server database (MySQL 8.4 and PostgreSQL 18; skipped on
 * sqlite). Separate PHP processes released at the same instant ask for the same slot; the store row lock around
 * the capacity check lets exactly `capacity` of them win, for the pickup slot and for the delivery-back slot.
 *
 * Fixture rows are COMMITTED (no per-test transaction: the other processes must see them) and deleted in tearDown.
 */
class LaundryHoldRaceTest extends TestCase
{
    use LaundryHoldFixtures;
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
            $this->markTestSkipped('Cross-process race: runs on MySQL and PostgreSQL (the sqlite suite covers the capacity rules in LaundryHoldTest).');
        }
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        $stores = $this->stores;
        $this->stores = [];
        if ($stores !== [] && in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            // Every committed row of these stores (their default branches too: left behind, they made the legacy
            // importer's guard refuse in a later test of the same process).
            CommittedStoreFixtures::purge($stores);
            TestPlans::delete(...$this->laundryPlans);
        }
        parent::tearDown();
    }

    /**
     * Start $n child processes that all ask for the same hold at the same instant.
     *
     * @return list<string> their answers, sorted
     */
    private function race(int $n, Store $store, string $mode, string $pickup, ?string $return): array
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
            $cmd = [PHP_BINARY, __DIR__.'/Support/laundry_hold_race_child.php', (string) $store->id, $mode, $pickup, $return ?? '-', $go];
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

    private function committedStore(int $capacity): Store
    {
        $store = $this->laundryStore(['scheduling_slot_capacity' => $capacity, 'scheduling_max_days' => 14]);
        $this->stores[] = (int) $store->id;

        return $store;
    }

    private function slot(int $days, string $time): string
    {
        return CarbonImmutable::now('UTC')->addDays($days)->format('Y-m-d').'T'.$time;
    }

    public function test_two_processes_racing_for_the_last_pickup_place_get_exactly_one_hold(): void
    {
        $store = $this->committedStore(1);
        $answers = $this->race(2, $store, 'pickup_slot', $this->slot(2, '10:00'), null);

        $this->assertSame(['ok', 'slot_taken'], $answers);
        $this->assertSame(1, DB::table('laundry_slot_holds')->where('store_id', $store->id)->count());
    }

    public function test_four_processes_for_a_slot_with_two_places_get_exactly_two_holds(): void
    {
        $store = $this->committedStore(2);
        $answers = $this->race(4, $store, 'pickup_slot', $this->slot(2, '10:00'), null);

        $this->assertSame(['ok', 'ok', 'slot_taken', 'slot_taken'], $answers);
        $this->assertSame(2, DB::table('laundry_slot_holds')->where('store_id', $store->id)->count());
    }

    public function test_racers_with_different_pickups_but_one_delivery_back_slot_get_exactly_one_hold(): void
    {
        $store = $this->committedStore(1);
        // two groups with different pickup slots racing for the same delivery-back slot, all at once
        $a = $this->race(1, $store, 'delivery', $this->slot(2, '08:00'), $this->slot(3, '10:00'));
        $b = $this->race(1, $store, 'delivery', $this->slot(2, '09:00'), $this->slot(3, '10:00'));

        $this->assertSame(['ok'], $a);
        $this->assertSame(['return_slot_taken'], $b);
        $this->assertSame(1, DB::table('laundry_slot_holds')->where('store_id', $store->id)->count());
    }

    public function test_concurrent_holds_for_one_delivery_back_slot_with_different_pickups_never_overfill_it(): void
    {
        $store = $this->committedStore(1);
        // same delivery-back slot, the pickup differs per process would need separate argv: three processes share the
        // pickup here, so one wins the pickup place and the rest are refused before the delivery-back slot is touched
        $answers = $this->race(3, $store, 'delivery', $this->slot(2, '10:00'), $this->slot(3, '10:00'));

        $this->assertSame(['ok', 'slot_taken', 'slot_taken'], $answers);
        $this->assertSame(1, DB::table('laundry_slot_holds')->where('store_id', $store->id)->whereNotNull('return_at')->count());
    }
}
