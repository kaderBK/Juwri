<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Grocery;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Ordering\Models\DeliverySlotRule;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Ordering\Services\Slots\DeliverySlots;
use Juwri\Modules\Ordering\Services\Slots\SchedulingSlots;
use Juwri\Modules\Ordering\Services\Slots\SlotHolds;
use Tests\Support\CommittedStoreFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-09 done-definition: the delivery-slot capacity guard on a real server database (MySQL 8.4 and PostgreSQL 18;
 * skipped on sqlite, where DeliverySlotHoldsTest covers the guard). UNIQUE(store_id, branch_key, slot_at, seat_no) on
 * slot_seats lets only one taker commit each seat, across connections and processes, with no application lock:
 *  - five PHP processes released at the same instant on a slot with 2 seats: exactly 2 holds, 3 slot_full;
 *  - a seat written but not committed on another connection makes a hold wait, then either take the next free seat
 *    or give up; once committed and the slot is full, the hold is refused.
 *
 * Fixture rows are COMMITTED (other connections must see them) and deleted in tearDown.
 */
class DeliverySlotCapacityRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $stores = [];

    /** @var list<mixed> */
    private array $plans = [];

    /** @return array<int, string|null> */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Cross-connection race: runs on MySQL and PostgreSQL (DeliverySlotHoldsTest covers the guard on sqlite).');
        }
    }

    protected function tearDown(): void
    {
        $stores = $this->stores;
        $this->stores = [];
        if ($stores !== [] && in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            foreach (['slot_seats', 'slot_holds', 'delivery_slot_rules', 'orders'] as $table) {
                DB::table($table)->whereIn('store_id', $stores)->delete();
            }
            CommittedStoreFixtures::purge($stores); // default branches and every other committed row of these stores
            TestPlans::delete(...$this->plans);
        }
        parent::tearDown();
    }

    /** @return array{0: Store, 1: string} a store whose rule offers tomorrow 12:00 UTC with 2 seats, and that slot's id */
    private function store(): array
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $this->plans[] = $plan;
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'gro-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'Mart '.Str::random(4),
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 Olaya St',
            'phone' => '1', 'store_type' => 'grocery', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->stores[] = (int) $store->id;
        StoreSettings::setMany((int) $store->id, ['timezone' => 'UTC', 'currency_code' => 'SAR', 'restaurant_delivery_order' => true]);
        DeliverySlotRule::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => null, 'active' => true,
            'windows' => [['00:00', '24:00']], 'capacity_per_hour' => 2, 'lead_minutes' => 0, 'days_ahead' => 3, 'currency_code' => 'SAR',
        ]);
        $store = $store->fresh() ?? $store;
        $at = CarbonImmutable::now('UTC')->addDay()->setTime(12, 0);

        return [$store, app(DeliverySlots::class)->encode($store, 'delivery', 0, $at)];
    }

    /** @return list<string> the children's answers, sorted */
    private function race(int $n, Store $store, string $slotId): array
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
            $cmd = [PHP_BINARY, __DIR__.'/Support/slot_race_child.php', (string) $store->id, $slotId, 'holder-'.$i, $go];
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

    public function test_five_processes_racing_for_two_seats_get_exactly_two_holds(): void
    {
        [$store, $slotId] = $this->store();
        $answers = $this->race(5, $store, $slotId);

        $this->assertSame(['ok', 'ok', 'slot_full', 'slot_full', 'slot_full'], $answers);
        $this->assertSame(2, DB::table('slot_seats')->where('store_id', $store->id)->count());
        $this->assertSame(2, DB::table('slot_holds')->where('store_id', $store->id)->count());
    }

    /**
     * Lane C: a food schedule-ahead slot (SchedulingService pre-orders, capacity 3) that already has one scheduled
     * order: five processes holding at once get exactly the two places left.
     */
    public function test_five_processes_racing_for_a_food_pre_order_slot_get_only_the_places_left(): void
    {
        [$store] = $this->store();
        DeliverySlotRule::query()->where('store_id', $store->id)->delete(); // a cafe: no delivery-slot rule
        StoreSettings::setMany((int) $store->id, [
            'restaurant_takeaway_order' => true, 'allow_order_scheduling' => true, 'scheduling_types' => 'takeaway',
            'scheduling_slot_interval' => 15, 'scheduling_lead_minutes' => 0, 'scheduling_slot_capacity' => 3, 'scheduling_max_days' => 3,
        ]);
        $at = CarbonImmutable::now('UTC')->addDay()->setTime(12, 0);
        DB::table('orders')->insert(['store_id' => $store->id, 'account_id' => $store->account_id, 'status' => 'pending', 'is_paid' => 0,
            'type' => 'takeaway', 'customer_name' => 'Guest', 'phone_number' => '1', 'scheduled_at' => $at->format('Y-m-d H:i:s'),
            'created_at' => now(), 'updated_at' => now()]);
        $slotId = app(SchedulingSlots::class)->encode($store, 'takeaway', 0, $at);
        $this->assertNotNull(app(SlotHolds::class)->findSlot($store, null, $slotId));

        $this->assertSame(['ok', 'ok', 'slot_full', 'slot_full', 'slot_full'], $this->race(5, $store, $slotId));
        $this->assertSame(2, DB::table('slot_seats')->where('store_id', $store->id)->count());
        $this->assertSame(3, app(SchedulingService::class)->slotUsage((int) $store->id, null, $at, $at->addMinutes(15)->subSecond())[$at->format('Y-m-d H:i')] ?? 0);
    }

    public function test_an_uncommitted_then_committed_seat_on_another_connection_is_respected(): void
    {
        [$store, $slotId] = $this->store();
        $driver = DB::connection()->getDriverName();
        $name = config('database.default');
        config(['database.connections.'.$name.'_probe' => config('database.connections.'.$name)]);
        $probe = DB::connection($name.'_probe');
        $driver === 'mysql'
            ? DB::statement('SET SESSION innodb_lock_wait_timeout = 1')
            : DB::statement("SET lock_timeout = '1s'");
        $slotAt = CarbonImmutable::now('UTC')->addDay()->setTime(12, 0)->format('Y-m-d H:i:s');
        $seat = fn (int $n) => ['account_id' => (int) $store->account_id, 'store_id' => (int) $store->id, 'branch_key' => 0,
            'slot_at' => $slotAt, 'seat_no' => $n, 'hold_id' => null, 'order_id' => 990000 + $n, 'expires_at' => null];
        $hold = function () use ($store, $slotId): string {
            try {
                app(SlotHolds::class)->hold($store, null, $slotId, null, null);
            } catch (SlotException $e) {
                return $e->errorCode;
            }

            return 'ok';
        };

        try {
            if ($driver === 'pgsql') {
                $probe->statement("SET app.tenant_bypass = 'on'");
            }
            $probe->table('slot_seats')->insert($seat(1)); // one order already owns seat 1 (committed)
            $probe->beginTransaction();
            $probe->table('slot_seats')->insert($seat(2)); // another order is writing seat 2, not committed yet

            // seat 2 looks free to us but its key is locked: the hold waits, retries and gives the time up
            $this->assertSame('slot_full', $hold());

            $probe->commit(); // now both seats are taken for good
            $this->assertSame('slot_full', $hold());

            $probe->table('slot_seats')->where('store_id', $store->id)->where('seat_no', 2)->delete();
            $this->assertSame('ok', $hold()); // a seat is free again
        } finally {
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }
            DB::purge($name.'_probe');
            $driver === 'mysql'
                ? DB::statement('SET SESSION innodb_lock_wait_timeout = 50')
                : DB::statement('SET lock_timeout = 0');
        }
        $this->assertSame(2, DB::table('slot_seats')->where('store_id', $store->id)->count());
    }
}
