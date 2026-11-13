<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Per-slot pre-order capacity. slotIsFull() is the atomic re-check the create path runs
 * while holding a per-slot Cache::lock, closing the count-then-insert race that let
 * concurrent bookers overfill a limited window. (The lock's true concurrency can't be
 * exercised on the single-process sqlite test driver; this pins the occupancy logic.)
 */
class SlotCapacityTest extends TestCase
{
    use RefreshDatabase;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        // The store clock == the boot app tz here, so the raw wall-clock we insert and the
        // Carbon we query with bucket identically (no per-test tz override, which would not
        // change the Eloquent datetime cast's boot timezone anyway).
        $this->tz = (string) (config('app.timezone') ?: 'UTC');
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function scheduledOrder(Store $store, string $at, string $status = 'pending'): void
    {
        // Model create for the column defaults, then a raw update to write the exact naive
        // wall-clock the real create path stores (validate()->format()) — not the
        // tz-converting Eloquent datetime cast value.
        $o = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => $status,
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        DB::table('orders')->where('id', $o->id)->update(['scheduled_at' => $at]);
    }

    public function test_slot_is_full_reflects_per_slot_capacity(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_capacity', 1);
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_interval', 15);
        $svc = app(SchedulingService::class);

        $slot = Carbon::parse('2026-08-11 18:00:00', $this->tz);
        $this->assertFalse($svc->slotIsFull($store->id, null, $slot), 'empty slot is not full');

        $this->scheduledOrder($store, '2026-08-11 18:00:00');
        $this->assertTrue($svc->slotIsFull($store->id, null, $slot->copy()), 'one order fills a capacity-1 slot');

        // A different slot in the same window stays open.
        $this->assertFalse($svc->slotIsFull($store->id, null, Carbon::parse('2026-08-11 18:30:00', $this->tz)));
    }

    public function test_cancelled_scheduled_order_frees_its_slot(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_capacity', 1);
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_interval', 15);
        $svc = app(SchedulingService::class);
        $slot = Carbon::parse('2026-08-11 18:00:00', $this->tz);

        // A cancelled pre-order is a phantom booking — it must NOT keep holding the slot.
        $this->scheduledOrder($store, '2026-08-11 18:00:00', 'cancelled');
        $this->assertFalse($svc->slotIsFull($store->id, null, $slot->copy()),
            'a cancelled pre-order does not consume capacity');

        // A live order in the same slot still fills the capacity-1 window.
        $this->scheduledOrder($store, '2026-08-11 18:00:00', 'pending');
        $this->assertTrue($svc->slotIsFull($store->id, null, $slot->copy()));
    }

    public function test_unlimited_capacity_is_never_full(): void
    {
        $store = $this->store(); // no scheduling_slot_capacity → 0 = unlimited
        $svc = app(SchedulingService::class);

        $this->scheduledOrder($store, '2026-08-11 18:00:00');
        $this->scheduledOrder($store, '2026-08-11 18:00:00');

        $this->assertFalse($svc->slotIsFull($store->id, null, Carbon::parse('2026-08-11 18:00:00', $this->tz)));
    }

    /**
     * lockSlot() is the shared guard both the API path (OrderApiService::create) and the
     * two storefront pipelines now hold across the INSERT+commit, closing the storefront
     * check-then-insert race. It only serialises when there is something to protect.
     */
    public function test_lock_slot_is_a_noop_when_nothing_to_serialise(): void
    {
        $svc = app(SchedulingService::class);

        // Unlimited-capacity store → no lock.
        $store = $this->store();
        $this->assertNull(
            $svc->lockSlot($store->id, null, Carbon::parse('2026-08-11 18:00:00', $this->tz)),
            'unlimited capacity takes no lock'
        );

        // ASAP order (no slot) → no lock, even on a capacity-limited store.
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_capacity', 1);
        $this->assertNull($svc->lockSlot($store->id, null, null), 'ASAP order takes no lock');
    }

    public function test_lock_slot_acquires_an_open_slot_then_rejects_a_full_one(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_capacity', 1);
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_interval', 15);
        $svc = app(SchedulingService::class);
        $slot = Carbon::parse('2026-08-11 18:00:00', $this->tz);

        // Open slot → a real held lock is returned (release it so we don't wedge the key).
        $lock = $svc->lockSlot($store->id, null, $slot->copy());
        $this->assertInstanceOf(Lock::class, $lock);
        optional($lock)->release();

        // Fill the slot, then the re-check under the lock rejects the second booker —
        // the exact overfill the storefront could previously slip through.
        $this->scheduledOrder($store, '2026-08-11 18:00:00');
        try {
            $svc->lockSlot($store->id, null, $slot->copy());
            $this->fail('a full slot must be rejected');
        } catch (\InvalidArgumentException $e) {
            // Message is locale-translated ("slot full") — assert the reject, not the wording.
            $this->assertNotSame('', trim($e->getMessage()));
        }
    }

    /**
     * Phase 3 F2: the registry typed scheduling_slot_capacity 'bool', so a capacity of 1 was stored as JSON `true`
     * and a typed read (StoreSettings::int) returned the default 0 = unlimited. A number saved through the owner
     * editor's write seam now round-trips as an int and is enforced.
     */
    public function test_numeric_capacity_saved_by_the_owner_editor_round_trips_and_is_enforced(): void
    {
        $store = $this->store();
        app(StoreSettingsService::class)->applyOptions($store, ['scheduling_slot_capacity' => '3', 'scheduling_slot_interval' => '15']);

        $col = json_decode((string) DB::table('stores')->where('id', $store->id)->value('settings'), true);
        $this->assertSame(3, $col['scheduling']['slot_capacity']);
        $this->assertSame(3, StoreSettings::get($store->id, 'scheduling_slot_capacity'));
        $this->assertSame(3, StoreSettings::int($store->id, 'scheduling_slot_capacity'));
        $this->assertSame('3', StoreSettings::legacy($store->id, 'scheduling_slot_capacity'));

        $svc = app(SchedulingService::class);
        $this->assertSame(3, $svc->slotCapacity($store->id));
        $slot = Carbon::parse('2026-08-11 18:00:00', $this->tz);
        $this->scheduledOrder($store, '2026-08-11 18:00:00');
        $this->scheduledOrder($store, '2026-08-11 18:00:00');
        $this->assertFalse($svc->slotIsFull($store->id, null, $slot->copy()), 'two of three places taken');
        $this->scheduledOrder($store, '2026-08-11 18:00:00');
        $this->assertTrue($svc->slotIsFull($store->id, null, $slot->copy()), 'the third order fills a capacity-3 slot');

        // Capacity 1 is a number too (it used to become JSON true).
        app(StoreSettingsService::class)->applyOptions($store, ['scheduling_slot_capacity' => '1']);
        $col = json_decode((string) DB::table('stores')->where('id', $store->id)->value('settings'), true);
        $this->assertSame(1, $col['scheduling']['slot_capacity']);
        $this->assertSame(1, $svc->slotCapacity($store->id));
    }

    /** Values stored before the fix (JSON true for 1, text for other numbers) keep working without a data migration. */
    public function test_capacities_stored_before_the_type_fix_still_read_and_enforce(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'scheduling_slot_interval', 15);
        $svc = app(SchedulingService::class);
        $slot = Carbon::parse('2026-08-11 18:00:00', $this->tz);

        DB::table('stores')->where('id', $store->id)->update(['settings' => json_encode(
            ['scheduling' => ['slot_capacity' => true, 'slot_interval' => 15]]
        )]);
        StoreSettings::flush($store->id);
        $this->assertSame(1, $svc->slotCapacity($store->id), 'a stored true is capacity 1');
        $this->assertSame('1', StoreSettings::legacy($store->id, 'scheduling_slot_capacity'));
        $this->scheduledOrder($store, '2026-08-11 18:00:00');
        $this->assertTrue($svc->slotIsFull($store->id, null, $slot->copy()));

        DB::table('stores')->where('id', $store->id)->update(['settings' => json_encode(
            ['scheduling' => ['slot_capacity' => '2', 'slot_interval' => 15]]
        )]);
        StoreSettings::flush($store->id);
        $this->assertSame(2, $svc->slotCapacity($store->id), 'text kept by the old lenient coercion');
        $this->assertFalse($svc->slotIsFull($store->id, null, $slot->copy()));

        DB::table('stores')->where('id', $store->id)->update(['settings' => json_encode(
            ['scheduling' => ['slot_capacity' => false, 'slot_interval' => 15]]
        )]);
        StoreSettings::flush($store->id);
        $this->assertSame(0, $svc->slotCapacity($store->id), 'a stored false is 0 = unlimited');
        $this->assertFalse($svc->slotIsFull($store->id, null, $slot->copy()));
    }
}
