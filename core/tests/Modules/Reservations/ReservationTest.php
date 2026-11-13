<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'Test', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);

        $this->withoutMiddleware();
    }

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Plan '.Str::random(6),
            'description' => 'Test',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'group_id' => (string) $this->makePlan()->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111',
            'title' => $title,
            'sub_title' => 'Test',
            'description' => 'Test',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test St',
            'phone' => '1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Owner can create a reservation */
    public function test_owner_can_create_reservation(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->post(route('reservations.store'), [
                'store_id' => $store->id,
                'name' => 'John Doe',
                'phone' => '555-1234',
                'party_size' => 4,
                'reserved_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('reservations', [
            'store_id' => $store->id,
            'name' => 'John Doe',
            'status' => 'confirmed',
        ]);
    }

    /** upcoming()/past split is judged in the store's timezone (reserved_at is a wall-clock). */
    public function test_upcoming_scope_uses_store_timezone(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($store->id, 'timezone', 'America/New_York');

        // Platform (Asia/Qatar) is 15:00; New York is 08:00.
        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00', 'UTC'));

        // Booked for 10:00 — still ahead in New York (08:00), already past on the platform (15:00).
        Reservation::create([
            'store_id' => $store->id, 'name' => 'TZ Guest', 'party_size' => 2,
            'reserved_at' => '2026-08-09 10:00:00', 'status' => 'confirmed', 'token' => \Str::random(40),
        ]);

        $storeTz = Reservation::forRestaurant($store->id)->upcoming($store->id)->count();
        $platform = Reservation::forRestaurant($store->id)->upcoming()->count();

        $this->assertSame(1, $storeTz, 'store-tz sees the booking as still upcoming');
        $this->assertSame(0, $platform, 'platform-tz would have dropped it as past');

        Carbon::setTestNow();
    }

    /** The "in the future" check is judged in the store's timezone, not the platform's. */
    public function test_reservation_future_check_uses_store_timezone(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($store->id, 'timezone', 'America/New_York');

        // 12:00 UTC = 08:00 in New York (EDT).
        Carbon::setTestNow(Carbon::parse('2026-08-09 12:00:00', 'UTC'));

        // 09:00 NY-local is in the future there — accepted, even though it is
        // already "past" in the platform timezone (a bare after:now would reject).
        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $store->id, 'name' => 'Future Guest', 'party_size' => 2,
            'reserved_at' => '2026-08-09 09:00:00',
        ])->assertRedirect();
        $this->assertDatabaseHas('reservations', ['store_id' => $store->id, 'name' => 'Future Guest']);

        // 07:00 NY-local is genuinely in the past there — rejected, not stored.
        $this->actingAs($owner)->post(route('reservations.store'), [
            'store_id' => $store->id, 'name' => 'Past Guest', 'party_size' => 2,
            'reserved_at' => '2026-08-09 07:00:00',
        ]);
        $this->assertDatabaseMissing('reservations', ['store_id' => $store->id, 'name' => 'Past Guest']);

        Carbon::setTestNow();
    }

    /** Tenant isolation: cannot create reservation for another owner's restaurant */
    public function test_cannot_create_reservation_for_other_restaurant(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $other = $this->makeUser();

        $this->actingAs($other)
            ->post(route('reservations.store'), [
                'store_id' => $store->id,
                'name' => 'Attacker',
                'party_size' => 2,
                'reserved_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertForbidden();
    }

    /** Owner can update reservation status */
    public function test_owner_can_update_reservation_status(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Jane Doe',
            'party_size' => 2,
            'reserved_at' => now()->addDay(),
            'status' => 'pending',
            'token' => Str::random(40),
        ]);

        $this->actingAs($owner)
            ->post(route('reservations.status', $reservation->id), ['status' => 'seated'])
            ->assertRedirect();

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'seated']);
    }

    /** Cannot update status of another owner's reservation */
    public function test_cannot_update_other_owners_reservation(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Test',
            'party_size' => 2,
            'reserved_at' => now()->addDay(),
            'status' => 'pending',
            'token' => Str::random(40),
        ]);

        $other = $this->makeUser();

        $this->actingAs($other)
            ->post(route('reservations.status', $reservation->id), ['status' => 'seated'])
            ->assertNotFound();
    }

    /** Public booking creates a pending reservation */
    public function test_public_booking_creates_pending_reservation(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        // #80: public booking is opt-in per store; without this the endpoint 404s.
        StoreSettings::set($store->id, 'enable_reservations', '1');

        $this->post(route('publicView.reservation.book', $store->slug), [
            'name' => 'Public Guest',
            'phone' => '555-9999',
            'party_size' => 3,
            'reserved_at' => now()->addDay()->toDateTimeString(),
        ])
            ->assertRedirect();

        $this->assertDatabaseHas('reservations', [
            'store_id' => $store->id,
            'name' => 'Public Guest',
            'status' => 'pending',
        ]);
    }

    public function test_public_booking_is_refused_when_the_store_has_not_opted_in(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        // No enable_reservations option: the storefront renders no booking form,
        // so the endpoint must not accept writes either.

        $this->post(route('publicView.reservation.book', $store->slug), [
            'name' => 'Uninvited Guest',
            'phone' => '555-0000',
            'party_size' => 2,
            'reserved_at' => now()->addDay()->toDateTimeString(),
        ])
            ->assertNotFound();

        $this->assertDatabaseMissing('reservations', ['store_id' => $store->id]);
    }

    /** C3: the owner reservations page surfaces each reservation's deposit status + amount. */
    public function test_owner_reservations_page_shows_the_deposit_badge(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        // A future reservation carrying a paid deposit, and one with no deposit.
        Reservation::create([
            'store_id' => $store->id, 'name' => 'Deposit Guest', 'party_size' => 4,
            'reserved_at' => now()->addDay(), 'status' => 'confirmed', 'token' => Str::random(40),
            'deposit_status' => 'paid', 'deposit_amount' => 25,
        ]);
        Reservation::create([
            'store_id' => $store->id, 'name' => 'No Deposit Guest', 'party_size' => 2,
            'reserved_at' => now()->addDay(), 'status' => 'pending', 'token' => Str::random(40),
        ]);

        $html = $this->actingAs($owner)
            ->get(route('reservations.index', ['store_id' => $store->id]))
            ->assertOk()->getContent();

        // The deposit badge renders with the amount (pre-fix: no Deposit column at all).
        $this->assertStringContainsString('data-deposit-badge', $html, 'the deposit badge renders');
        $this->assertStringContainsString('25', $html, 'the deposit amount is shown');
    }
}
