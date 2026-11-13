<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\Installed;
use App\Models\Reservation;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Http\Middleware\RememberLocale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — P5-18 gap regression coverage.
 *
 * Covers the audit gaps fixed against the RwG Booking Server v3 contract:
 *   G1 store scope on replay/update/status,
 *   G2 create refuses past / beyond-horizon / over-max-party,
 *   G3 guest contact required,
 *   G8 malformed body ⇒ 400 bad_request,
 *   auth/kill-switch on every rwg/v3 method,
 *   store isolation in ListBookings.
 *
 * Contract under test: business failures are HTTP 200
 * `{"booking_failure":{"cause":X}}`; malformed input is 400 `{"error":"bad_request"}`;
 * an unknown/disabled store is 404 `{"error":"not_found"}`. Causes:
 * SLOT_UNAVAILABLE, USER_INFORMATION_INVALID, BOOKING_CANCELLATION_NOT_ALLOWED.
 *
 * The setUp/auth/store/createPayload helpers mirror
 * RwgBookingServerTest so both suites share the same fixture shape.
 */
class RwgBookingServerGapsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The six booking-server methods (verb => correct HTTP method). HealthCheck is a
     * GET; the other five are POSTs.
     *
     * @var array<string,string>
     */
    private const RWG_METHODS = [
        'HealthCheck' => 'GET',
        'BatchAvailabilityLookup' => 'POST',
        'CreateBooking' => 'POST',
        'UpdateBooking' => 'POST',
        'GetBookingStatus' => 'POST',
        'ListBookings' => 'POST',
    ];

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

    /** POST a raw (possibly non-JSON) body with valid Basic auth. */
    private function postRaw(string $uri, string $body): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('goog:s3cret'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /**
     * G1 — a token replayed against a DIFFERENT store is not a replay.
     * Given store A holds a booking for token T, When Google replays CreateBooking for
     * token T naming store B, Then the answer is booking_failure SLOT_UNAVAILABLE and
     * store A's booking is untouched (no row leaks to B).
     */
    public function test_replay_token_for_another_store_is_slot_unavailable(): void
    {
        $storeA = $this->store();
        $storeB = $this->store();
        $token = 'tok-cross-store';

        $first = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($storeA, $token, 2), $this->auth());
        $first->assertStatus(200)->assertJsonPath('booking.booking_id', $token);

        // Replay the same token but claim store B's merchant_id.
        $replay = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($storeB, $token, 2), $this->auth());

        $replay->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
        // The first store's booking is untouched; nothing was created for store B.
        $this->assertSame(1, Reservation::where('rwg_booking_id', $token)->count());
        $this->assertDatabaseHas('reservations', [
            'store_id' => $storeA->id, 'rwg_booking_id' => $token, 'status' => 'confirmed',
        ]);
        $this->assertSame(0, Reservation::where('store_id', $storeB->id)->count());
    }

    /**
     * G1 — replay with the SAME store is idempotent.
     * Given a booking exists for token T on store A, When CreateBooking replays the
     * exact payload, Then the same booking_id is returned and exactly one row exists.
     */
    public function test_replay_token_for_same_store_returns_same_booking(): void
    {
        $store = $this->store();
        $payload = $this->createPayload($store, 'tok-same-store', 2);

        $first = $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());
        $second = $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());

        $first->assertStatus(200)->assertJsonPath('booking.booking_id', 'tok-same-store');
        $second->assertStatus(200)->assertJsonPath('booking.booking_id', 'tok-same-store');
        $this->assertSame(1, Reservation::where('rwg_booking_id', 'tok-same-store')->count());
    }

    /**
     * G1 — a store that switched RwG off is invisible: status/update are 404, list omits it.
     * Given a booking on store A, When store A disables rwg_reservations_enabled, Then
     * GetBookingStatus and UpdateBooking return 404 not_found and ListBookings returns
     * no bookings for that diner.
     */
    public function test_disabled_store_booking_is_not_found_and_not_listed(): void
    {
        $store = $this->store();
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-off', 2), $this->auth());

        StoreOption::updateStoreOption($store->id, 'rwg_reservations_enabled', '0');

        $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => 'tok-off'], $this->auth())
            ->assertStatus(404)->assertJsonPath('error', 'not_found');

        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-off', 'status' => 'CANCELED'],
        ], $this->auth())->assertStatus(404)->assertJsonPath('error', 'not_found');

        $this->postJson('/rwg/v3/ListBookings', [
            'user_information' => ['telephone' => '+97455512'],
        ], $this->auth())->assertStatus(200)->assertJsonCount(0, 'bookings');
    }

    /**
     * G2 — create refuses past / beyond-horizon / over-max-party.
     * Given a store with max party 4 (default 30-day horizon), When CreateBooking is
     * called with (a) a past start, (b) a start 60 days out, (c) a party of 5, Then
     * each is a 200 booking_failure SLOT_UNAVAILABLE and no reservation row is written.
     */
    public function test_create_booking_rejects_past_horizon_and_over_party(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_max_party', '4');

        $past = $this->postJson('/rwg/v3/CreateBooking',
            $this->createPayload($store, 'tok-past', 2, now()->subHour()->getTimestamp()), $this->auth());
        $past->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $beyond = $this->postJson('/rwg/v3/CreateBooking',
            $this->createPayload($store, 'tok-horizon', 2, now()->addDays(60)->setTime(19, 0)->getTimestamp()), $this->auth());
        $beyond->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $overParty = $this->postJson('/rwg/v3/CreateBooking',
            $this->createPayload($store, 'tok-bigparty', 5), $this->auth());
        $overParty->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        // None of the three wrote a row.
        $this->assertSame(0, Reservation::whereIn('rwg_booking_id', ['tok-past', 'tok-horizon', 'tok-bigparty'])->count());
        $this->assertSame(0, Reservation::count());
    }

    /**
     * G2 — BatchAvailabilityLookup applies the same gate as CreateBooking.
     * Given a store with max party 4 (default 30-day horizon), When the batch is asked
     * about a past slot, a slot 60 days out, an over-max party and a valid future slot,
     * Then the first three report available=false and the valid future slot true.
     */
    public function test_batch_availability_reports_past_horizon_and_over_party_unavailable(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_max_party', '4');
        $validStart = now()->addDays(2)->setTime(19, 0)->getTimestamp();

        $res = $this->postJson('/rwg/v3/BatchAvailabilityLookup', [
            'merchant_id' => 'store-'.$store->id,
            'slot_time' => [
                ['start_sec' => now()->subHour()->getTimestamp(), 'resources' => ['party_size' => 2]],
                ['start_sec' => now()->addDays(60)->setTime(19, 0)->getTimestamp(), 'resources' => ['party_size' => 2]],
                ['start_sec' => $validStart, 'resources' => ['party_size' => 5]],
                ['start_sec' => $validStart, 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth());

        $res->assertStatus(200)
            ->assertJsonPath('slot_time_availability.0.available', false) // past
            ->assertJsonPath('slot_time_availability.1.available', false) // beyond horizon
            ->assertJsonPath('slot_time_availability.2.available', false) // party over max
            ->assertJsonPath('slot_time_availability.3.available', true); // valid future slot
    }

    /**
     * G3 — a booking must carry a way to reach the guest.
     * Given user_information with no telephone and no email, When CreateBooking is
     * called, Then it is a 200 booking_failure USER_INFORMATION_INVALID and no row is
     * written.
     */
    public function test_create_booking_without_guest_contact_is_user_information_invalid(): void
    {
        $store = $this->store();
        $payload = $this->createPayload($store, 'tok-no-contact', 2);
        $payload['user_information'] = ['given_name' => 'No', 'family_name' => 'Contact'];

        $res = $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'USER_INFORMATION_INVALID');
        $this->assertSame(0, Reservation::where('rwg_booking_id', 'tok-no-contact')->count());
    }

    /**
     * G8 — a non-JSON or non-object body is a transport-level 400.
     * Given CreateBooking and BatchAvailabilityLookup, When the body is a non-JSON
     * string or a JSON string literal (not an object/array), Then each returns
     * 400 bad_request (never a 200 empty answer).
     */
    public function test_malformed_body_is_400_bad_request(): void
    {
        foreach (['CreateBooking', 'BatchAvailabilityLookup'] as $method) {
            foreach (['this is not json', '"a json string"'] as $body) {
                $this->postRaw('/rwg/v3/'.$method, $body)
                    ->assertStatus(400)
                    ->assertJsonPath('error', 'bad_request');
            }
        }
    }

    /**
     * Auth — every rwg/v3 method refuses without credentials.
     * Given the integration is enabled, When each of the six methods is called with no
     * Authorization header, Then each returns 401 unauthorized.
     */
    public function test_every_rwg_method_requires_auth(): void
    {
        foreach (self::RWG_METHODS as $method => $verb) {
            $res = $this->json($verb, '/rwg/v3/'.$method);

            $this->assertSame(401, $res->getStatusCode(), $method.' must return 401 without credentials');
        }
    }

    /**
     * Kill-switch — every rwg/v3 method is 503 when the integration is disabled.
     * Given rwg is disabled, When each of the six methods is called with valid Basic
     * credentials, Then each returns 503 service_unavailable so Google backs off.
     */
    public function test_every_rwg_method_unavailable_when_disabled(): void
    {
        config(['services.reserve_with_google.enabled' => false]);

        foreach (self::RWG_METHODS as $method => $verb) {
            $res = $this->json($verb, '/rwg/v3/'.$method, [], $this->auth());

            $this->assertSame(503, $res->getStatusCode(), $method.' must return 503 when disabled');
            $res->assertJsonPath('error', 'service_unavailable');
        }
    }

    /**
     * Store isolation — ListBookings never surfaces a store that switched RwG off.
     * Given stores A and B each hold a Google booking for the SAME phone, When store B
     * disables rwg_reservations_enabled, Then ListBookings by that phone returns only
     * store A's booking and GetBookingStatus for B's booking is 404.
     */
    public function test_list_bookings_only_returns_enabled_stores(): void
    {
        $storeA = $this->store();
        $storeB = $this->store();

        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($storeA, 'tok-iso-a', 2), $this->auth())
            ->assertStatus(200);
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($storeB, 'tok-iso-b', 2), $this->auth())
            ->assertStatus(200);

        // Store B opts out of Google after the booking exists.
        StoreOption::updateStoreOption($storeB->id, 'rwg_reservations_enabled', '0');

        $res = $this->postJson('/rwg/v3/ListBookings', [
            'user_information' => ['telephone' => '+97455512'],
        ], $this->auth());

        $res->assertStatus(200)
            ->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.booking_id', 'tok-iso-a');
        $this->assertNotContains('tok-iso-b', array_column($res->json('bookings'), 'booking_id'));

        $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => 'tok-iso-b'], $this->auth())
            ->assertStatus(404)->assertJsonPath('error', 'not_found');
    }
}
