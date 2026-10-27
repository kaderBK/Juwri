<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Reservations\Services\Rwg\ReservationAvailabilityService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — C3 ReservationAvailabilityService.
 *
 * Covers math, store tz, overnight window, blackouts, and the publicBook capacity
 * guard (302 redirect, NOT 422). The load-bearing default — covers_per_slot 0 =
 * unlimited — is asserted so a store that never configured capacity is a no-op.
 */
class ReservationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function svc(): ReservationAvailabilityService
    {
        return app(ReservationAvailabilityService::class);
    }

    private function book(Store $store, string $at, int $party, string $status = 'confirmed'): Reservation
    {
        return Reservation::create([
            'store_id' => $store->id, 'name' => 'G', 'party_size' => $party,
            'reserved_at' => $at, 'status' => $status, 'token' => Str::random(40),
        ]);
    }

    public function test_default_covers_is_unlimited_and_never_full(): void
    {
        $store = $this->store();
        // Default (no option) = 0 = unlimited. Pile in a big party — still has capacity.
        $this->book($store, '2026-09-01 19:00:00', 40);

        $this->assertSame(0, $this->svc()->coversPerSlot($store->id));
        $this->assertTrue($this->svc()->hasCapacity($store->id, null, Carbon::parse('2026-09-01 19:00:00'), 20));
        $this->assertFalse($this->svc()->slotIsFull($store->id, null, Carbon::parse('2026-09-01 19:00:00'), 20));
    }

    public function test_covers_capacity_enforced_per_bucket(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '4');

        // 3 covers already booked in the 19:00 bucket (19:00 + 19:15 fall in the same 30-min bucket).
        $this->book($store, '2026-09-01 19:00:00', 2);
        $this->book($store, '2026-09-01 19:15:00', 1);

        // 3 + 1 = 4 <= 4 fits; 3 + 2 = 5 > 4 does not.
        $this->assertTrue($this->svc()->hasCapacity($store->id, null, Carbon::parse('2026-09-01 19:20:00'), 1));
        $this->assertFalse($this->svc()->hasCapacity($store->id, null, Carbon::parse('2026-09-01 19:20:00'), 2));

        // A different bucket (19:30) is empty — full party fits.
        $this->assertTrue($this->svc()->hasCapacity($store->id, null, Carbon::parse('2026-09-01 19:30:00'), 4));
    }

    public function test_cancelled_and_noshow_do_not_consume_covers(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '4');
        $this->book($store, '2026-09-01 19:00:00', 4, 'cancelled');
        $this->book($store, '2026-09-01 19:05:00', 4, 'no_show');

        // Both inactive → bucket is empty → a party of 4 still fits.
        $this->assertTrue($this->svc()->hasCapacity($store->id, null, Carbon::parse('2026-09-01 19:10:00'), 4));
    }

    public function test_slots_respect_store_timezone_and_horizon(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        StoreOption::updateStoreOption($store->id, 'reservation_slot_interval', '60');
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));

        // No branch → always-open window 00:00..23:59; interval 60 → ~16 future hourly slots today.
        $slots = $this->svc()->slotsFor($store->id, null, '2026-09-01');
        $this->assertNotEmpty($slots);
        // All slots are at/after now (08:00) and today.
        foreach ($slots as $s) {
            $this->assertTrue($s['at']->gte(Carbon::parse('2026-09-01 08:00:00', 'UTC')));
        }
        // Beyond horizon (default 30d) → empty.
        $this->assertSame([], $this->svc()->slotsFor($store->id, null, '2026-11-01'));

        Carbon::setTestNow();
    }

    public function test_overnight_branch_window_rolls_past_midnight(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_slot_interval', '60');
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC')); // 2026-09-01 is a Tuesday
        $branch = Branch::create([
            'store_id' => $store->id, 'name' => 'Main', 'active' => 1, 'accepting_orders' => 1,
            'timezone' => 'UTC',
            'hours' => ['tue' => ['open' => '18:00', 'close' => '02:00', 'closed' => false]],
        ]);

        $slots = $this->svc()->slotsFor($store->id, $branch, '2026-09-01');
        $labels = array_column($slots, 'label');
        // Overnight window 18:00→02:00 yields evening + past-midnight slots.
        $this->assertContains('18:00', $labels);
        $this->assertContains('23:00', $labels);
        $this->assertContains('01:00', $labels);

        Carbon::setTestNow();
    }

    public function test_blackout_slots_are_skipped(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        StoreOption::updateStoreOption($store->id, 'reservation_slot_interval', '60');
        StoreOption::updateStoreOption($store->id, 'reservation_blackouts', json_encode([['date' => '2026-09-02']]));
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));

        // The whole blacked-out day yields no slots.
        $this->assertSame([], $this->svc()->slotsFor($store->id, null, '2026-09-02'));

        Carbon::setTestNow();
    }

    public function test_branch_has_hours_gate(): void
    {
        $store = $this->store();
        $withHours = Branch::create([
            'store_id' => $store->id, 'name' => 'H', 'active' => 1, 'accepting_orders' => 1,
            'hours' => ['mon' => ['open' => '09:00', 'close' => '17:00', 'closed' => false]],
        ]);
        $noHours = Branch::create([
            'store_id' => $store->id, 'name' => 'N', 'active' => 1, 'accepting_orders' => 1,
        ]);

        $this->assertTrue($this->svc()->branchHasHours($withHours));
        $this->assertFalse($this->svc()->branchHasHours($noHours));
        $this->assertFalse($this->svc()->branchHasHours(null));
    }

    // --- publicBook capacity guard ---------------------------------------

    public function test_public_book_over_capacity_redirects_302_not_422(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'enable_reservations', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '2');
        // Fill the 20:00 bucket with 2 covers.
        $this->book($store, now()->addDays(2)->setTime(20, 0)->toDateTimeString(), 2);

        $res = $this->post(route('publicView.reservation.book', $store->slug), [
            'name' => 'Late', 'party_size' => 1,
            'reserved_at' => now()->addDays(2)->setTime(20, 10)->toDateTimeString(),
        ]);

        $res->assertStatus(302); // back()->withErrors, NOT 422
        $res->assertSessionHasErrors('party_size');
        // Only the seed booking exists — the over-capacity one was rejected.
        $this->assertSame(1, Reservation::where('store_id', $store->id)->count());
    }

    public function test_public_book_within_capacity_succeeds(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'enable_reservations', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '6');
        $this->book($store, now()->addDays(2)->setTime(20, 0)->toDateTimeString(), 2);

        $res = $this->post(route('publicView.reservation.book', $store->slug), [
            'name' => 'Fits', 'party_size' => 3,
            'reserved_at' => now()->addDays(2)->setTime(20, 15)->toDateTimeString(),
        ]);

        $res->assertStatus(302);
        $res->assertSessionHasNoErrors();
        $this->assertDatabaseHas('reservations', [
            'store_id' => $store->id, 'name' => 'Fits', 'status' => 'pending',
        ]);
    }

    public function test_public_book_unlimited_default_allows_any_party(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'enable_reservations', '1');
        // No covers option → unlimited → the guard is a no-op.
        $this->book($store, now()->addDays(2)->setTime(20, 0)->toDateTimeString(), 40);

        $res = $this->post(route('publicView.reservation.book', $store->slug), [
            'name' => 'Big', 'party_size' => 20,
            'reserved_at' => now()->addDays(2)->setTime(20, 10)->toDateTimeString(),
        ]);

        $res->assertStatus(302);
        $res->assertSessionHasNoErrors();
        $this->assertDatabaseHas('reservations', ['store_id' => $store->id, 'name' => 'Big']);
    }
}
