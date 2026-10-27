<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Reservation;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Reservations\Services\ReservationBookingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * HPMS E1 — reservation hardening: clash guard wired into both create paths,
 * per-store buffer override, blackout dates/ranges, public branch persistence.
 */
class ReservationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'Plan '.Str::random(6), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enablePublic(int $storeId): void
    {
        StoreSettings::set($storeId, 'enable_reservations', '1');
    }

    /** store(): a second booking on the same table inside the buffer is refused. */
    public function test_store_rejects_table_clash(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $r->id, 'name' => 'A', 'party_size' => 2,
            'reserved_at' => '2027-01-10 19:00:00', 'table_number' => 'T1',
        ])->assertRedirect();

        $this->actingAs($owner)->from(route('reservations.index'))->post(route('reservations.store'), [
            'store_id' => $r->id, 'name' => 'B', 'party_size' => 2,
            'reserved_at' => '2027-01-10 19:30:00', 'table_number' => 'T1',
        ])->assertSessionHasErrors('table_number');

        $this->assertSame(1, Reservation::where('store_id', $r->id)->where('table_number', 'T1')->count());
    }

    /** publicBook(): the same table clash is enforced on the public path. */
    public function test_public_book_rejects_table_clash(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $this->enablePublic($r->id);

        Reservation::create(['store_id' => $r->id, 'name' => 'A', 'party_size' => 2,
            'reserved_at' => '2027-02-10 20:00:00', 'status' => 'confirmed', 'table_number' => 'P3', 'token' => Str::random(40)]);

        $this->from('/')->post(route('publicView.reservation.book', $r->slug), [
            'name' => 'B', 'party_size' => 2, 'reserved_at' => '2027-02-10 20:30:00', 'table_number' => 'P3',
        ])->assertSessionHasErrors('table_number');

        $this->assertSame(1, Reservation::where('store_id', $r->id)->where('table_number', 'P3')->count());
    }

    /** publicBook(): a tableless booking never clashes (skip). */
    public function test_public_tableless_skips_table_clash(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $this->enablePublic($r->id);

        foreach (['G1', 'G2'] as $n) {
            $this->post(route('publicView.reservation.book', $r->slug), [
                'name' => $n, 'party_size' => 2, 'reserved_at' => '2027-03-10 20:00:00',
            ])->assertRedirect();
        }
        $this->assertSame(2, Reservation::where('store_id', $r->id)->count());
    }

    /** A tighter buffer lets a nearby booking through that the default would reject. */
    public function test_buffer_override_narrows_the_window(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($r->id, 'reservation_buffer_minutes', '10');

        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $r->id, 'name' => 'A', 'party_size' => 2,
            'reserved_at' => '2027-04-10 19:00:00', 'table_number' => 'B1',
        ])->assertRedirect();

        // 30 min apart — inside the default 90 window but outside the 10-min override.
        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $r->id, 'name' => 'B', 'party_size' => 2,
            'reserved_at' => '2027-04-10 19:30:00', 'table_number' => 'B1',
        ])->assertRedirect();

        $this->assertSame(2, Reservation::where('store_id', $r->id)->where('table_number', 'B1')->count());
    }

    /** A whole-day blackout is enforced on both paths. */
    public function test_blackout_date_blocks(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $this->enablePublic($r->id);
        StoreOption::updateStoreOption($r->id, 'reservation_blackouts', json_encode([['date' => '2027-12-25']]));

        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $r->id, 'name' => 'Xmas', 'party_size' => 2, 'reserved_at' => '2027-12-25 19:00:00',
        ])->assertSessionHasErrors('reserved_at');

        $this->post(route('publicView.reservation.book', $r->slug), [
            'name' => 'Xmas2', 'party_size' => 2, 'reserved_at' => '2027-12-25 12:00:00',
        ])->assertSessionHasErrors('reserved_at');

        $this->assertSame(0, Reservation::where('store_id', $r->id)->count());

        // A different day is fine.
        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $r->id, 'name' => 'OK', 'party_size' => 2, 'reserved_at' => '2027-12-26 19:00:00',
        ])->assertRedirect();
        $this->assertSame(1, Reservation::where('store_id', $r->id)->count());
    }

    /** A {from,to} blackout window is enforced. */
    public function test_blackout_range_blocks(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($r->id, 'reservation_blackouts', json_encode([
            ['from' => '2027-06-30 18:00', 'to' => '2027-07-01 02:00'],
        ]));

        $this->assertTrue(ReservationBookingService::blackoutHit($r->id, '2027-06-30 23:30:00'));
        $this->assertFalse(ReservationBookingService::blackoutHit($r->id, '2027-06-30 12:00:00'));
    }

    /** publicBook persists a branch that belongs to the store; a foreign one is dropped. */
    public function test_public_book_persists_branch_id(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $this->enablePublic($r->id);
        $mine = Branch::create(['store_id' => $r->id, 'name' => 'Mine']);

        $otherOwner = $this->makeUser();
        $otherStore = $this->makeRestaurant($otherOwner);
        $foreign = Branch::create(['store_id' => $otherStore->id, 'name' => 'Foreign']);

        $this->post(route('publicView.reservation.book', $r->slug), [
            'name' => 'Branched', 'party_size' => 2, 'reserved_at' => '2027-05-10 19:00:00', 'branch_id' => $mine->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('reservations', ['store_id' => $r->id, 'name' => 'Branched', 'branch_id' => $mine->id]);

        $this->post(route('publicView.reservation.book', $r->slug), [
            'name' => 'Foreigner', 'party_size' => 2, 'reserved_at' => '2027-05-11 19:00:00', 'branch_id' => $foreign->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('reservations', ['store_id' => $r->id, 'name' => 'Foreigner', 'branch_id' => null]);
    }

    /** saveSettings persists buffer + blackouts; malformed JSON is rejected. */
    public function test_save_settings_persists_and_validates(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('reservations.settings'), [
            'store_id' => $r->id, 'reservation_buffer_minutes' => '45',
            'blackouts' => json_encode([['date' => '2027-12-25']]),
        ])->assertRedirect();

        $this->assertSame(45, ReservationBookingService::bufferMinutes($r->id));
        $this->assertSame([['date' => '2027-12-25']], ReservationBookingService::blackouts($r->id));

        $this->actingAs($owner)->from(route('reservations.index'))->post(route('reservations.settings'), [
            'store_id' => $r->id, 'blackouts' => 'not json',
        ])->assertSessionHasErrors('blackouts');
    }
}
