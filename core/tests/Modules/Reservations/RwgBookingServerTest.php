<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\Installed;
use App\Models\Reservation;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\RememberLocale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — C5 booking-server methods.
 *
 * CreateBooking (idempotent on rwg_booking_id, deposit-REJECT, capacity),
 * BatchAvailabilityLookup, GetBookingStatus, UpdateBooking (cancel/reschedule),
 * ListBookings — all behind RwgAuth.
 */
class RwgBookingServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([
            Installed::class,
            CheckUserIsBanned::class,
            RememberLocale::class,
            LaravelLocalizationRoutes::class,
            LaravelLocalizationRedirectFilter::class,
        ]);
        config([
            'services.reserve_with_google.enabled' => true,
            'services.reserve_with_google.basic_user' => 'goog',
            'services.reserve_with_google.basic_pass' => 's3cret',
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Basic '.base64_encode('goog:s3cret')];
    }

    private function store(bool $enableRwg = true): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '+974123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($enableRwg) {
            StoreOption::updateStoreOption($store->id, 'rwg_reservations_enabled', '1');
            StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        }

        return $store;
    }

    private function createPayload(Store $store, string $token, int $party = 2, ?int $startSec = null): array
    {
        $startSec ??= now()->addDays(2)->setTime(19, 0)->getTimestamp();

        return [
            'idempotency_token' => $token,
            'slot' => [
                'merchant_id' => 'store-'.$store->id, 'service_id' => 'dining-'.$store->id,
                'start_sec' => $startSec, 'duration_sec' => 1800,
                'resources' => ['party_size' => $party],
            ],
            'user_information' => [
                'given_name' => 'Jane', 'family_name' => 'Doe',
                'telephone' => '+97455512', 'email' => 'jane@example.com',
            ],
        ];
    }

    public function test_create_booking_creates_confirmed_google_reservation(): void
    {
        $store = $this->store();

        $res = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-1', 2), $this->auth());

        $res->assertStatus(200)
            ->assertJsonPath('booking.booking_id', 'tok-1')
            ->assertJsonPath('booking.status', 'CONFIRMED')
            ->assertJsonPath('booking.slot.merchant_id', 'store-'.$store->id);

        $this->assertDatabaseHas('reservations', [
            'store_id' => $store->id, 'rwg_booking_id' => 'tok-1',
            'source' => 'google', 'status' => 'confirmed', 'party_size' => 2, 'name' => 'Jane Doe',
        ]);
    }

    public function test_create_booking_is_idempotent_on_token(): void
    {
        $store = $this->store();
        $payload = $this->createPayload($store, 'tok-dupe', 2);

        $first = $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());
        $second = $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());

        $first->assertStatus(200)->assertJsonPath('booking.booking_id', 'tok-dupe');
        $second->assertStatus(200)->assertJsonPath('booking.booking_id', 'tok-dupe');
        // Exactly ONE reservation for the token.
        $this->assertSame(1, Reservation::where('rwg_booking_id', 'tok-dupe')->count());
    }

    public function test_create_booking_rejects_oversized_idempotency_token(): void
    {
        // M3: rwg_booking_id is VARCHAR(64); an over-length token must be a clean 400,
        // never a data-too-long 500 (or a silent truncate + prefix collision).
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, str_repeat('x', 65), 2), $this->auth())
            ->assertStatus(400)->assertJsonPath('error', 'idempotency_token_too_long');
        $this->assertSame(0, Reservation::whereNotNull('rwg_booking_id')->count());
    }

    public function test_create_booking_unknown_merchant_fails_soft(): void
    {
        $res = $this->postJson('/rwg/v3/CreateBooking', [
            'idempotency_token' => 'tok-x',
            'slot' => ['merchant_id' => 'store-999999', 'start_sec' => now()->addDay()->getTimestamp()],
            'user_information' => ['given_name' => 'A', 'family_name' => 'B'],
        ], $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
        $this->assertSame(0, Reservation::count());
    }

    public function test_create_booking_rejects_deposit_required_store(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_amount', '10');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_min_party', '1');

        $res = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-dep', 4), $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'PAYMENT_ERROR');
        $this->assertSame(0, Reservation::where('rwg_booking_id', 'tok-dep')->count());
    }

    public function test_create_booking_over_capacity_fails(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '2');
        $startSec = now()->addDays(2)->setTime(19, 0)->getTimestamp();
        // Fill the slot to capacity via an existing booking.
        Reservation::create(['store_id' => $store->id, 'name' => 'Seed', 'party_size' => 2,
            'reserved_at' => Carbon::createFromTimestamp($startSec, 'UTC')->format('Y-m-d H:i:s'),
            'status' => 'confirmed', 'token' => Str::random(40)]);

        $res = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-full', 2, $startSec), $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
    }

    public function test_batch_availability_lookup(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '4');
        $openSec = now()->addDays(2)->setTime(19, 0)->getTimestamp();
        $fullSec = now()->addDays(2)->setTime(20, 0)->getTimestamp();
        Reservation::create(['store_id' => $store->id, 'name' => 'X', 'party_size' => 4,
            'reserved_at' => Carbon::createFromTimestamp($fullSec, 'UTC')->format('Y-m-d H:i:s'),
            'status' => 'confirmed', 'token' => Str::random(40)]);

        $res = $this->postJson('/rwg/v3/BatchAvailabilityLookup', [
            'merchant_id' => 'store-'.$store->id,
            'slot_time' => [
                ['service_id' => 'dining-'.$store->id, 'start_sec' => $openSec, 'resources' => ['party_size' => 2]],
                ['service_id' => 'dining-'.$store->id, 'start_sec' => $fullSec, 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth());

        $res->assertStatus(200)
            ->assertJsonPath('slot_time_availability.0.available', true)
            ->assertJsonPath('slot_time_availability.1.available', false);
    }

    public function test_get_booking_status(): void
    {
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-gs', 2), $this->auth());

        $res = $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => 'tok-gs'], $this->auth());
        $res->assertStatus(200)->assertJsonPath('booking_status', 'CONFIRMED');

        $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => 'nope'], $this->auth())->assertStatus(404);
    }

    public function test_update_booking_cancel(): void
    {
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-cancel', 2), $this->auth());

        $res = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-cancel', 'status' => 'CANCELED'],
        ], $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking.status', 'CANCELED');
        $this->assertDatabaseHas('reservations', ['rwg_booking_id' => 'tok-cancel', 'status' => 'cancelled']);
    }

    public function test_update_booking_reschedule(): void
    {
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-resch', 2), $this->auth());
        $newSec = now()->addDays(3)->setTime(21, 0)->getTimestamp();

        $res = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => [
                'booking_id' => 'tok-resch',
                'slot' => ['start_sec' => $newSec, 'resources' => ['party_size' => 5]],
            ],
        ], $this->auth());

        $res->assertStatus(200);
        $r = Reservation::where('rwg_booking_id', 'tok-resch')->first();
        $this->assertSame(5, (int) $r->party_size);
        $this->assertSame(
            Carbon::createFromTimestamp($newSec, 'UTC')->format('Y-m-d H:i:s'),
            Carbon::parse($r->reserved_at)->format('Y-m-d H:i:s')
        );
    }

    public function test_list_bookings_by_phone(): void
    {
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-list', 2), $this->auth());

        $res = $this->postJson('/rwg/v3/ListBookings', [
            'user_information' => ['telephone' => '+97455512'],
        ], $this->auth());

        $res->assertStatus(200)
            ->assertJsonPath('bookings.0.booking_id', 'tok-list')
            ->assertJsonCount(1, 'bookings');
    }

    public function test_list_bookings_no_identifier_returns_empty(): void
    {
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-empty', 2), $this->auth());

        $this->postJson('/rwg/v3/ListBookings', ['user_information' => []], $this->auth())
            ->assertStatus(200)->assertJsonCount(0, 'bookings');
    }

    public function test_booking_methods_require_auth(): void
    {
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-auth', 2))
            ->assertStatus(401);
    }
}
