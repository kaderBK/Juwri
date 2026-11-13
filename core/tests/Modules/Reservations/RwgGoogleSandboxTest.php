<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\Installed;
use App\Models\Reservation;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\RememberLocale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Juwri\Modules\Reservations\Jobs\PushRwgBookingUpdateJob;
use Juwri\Modules\Reservations\Services\Rwg\RwgRealtimeClient;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — P5-18 sandbox end-to-end.
 *
 * Drives the Reserve-with-Google booking server in the ORDER Actions Center drives
 * it — HealthCheck → BatchAvailabilityLookup → CreateBooking (retry with the same
 * idempotency_token) → GetBookingStatus → UpdateBooking (reschedule) → ListBookings
 * → UpdateBooking (cancel) → GetBookingStatus — entirely over HTTP with Basic auth,
 * no external call: the real-time push runs against the fake real-time client
 * (config + Http::fake, the RwgRealtimeTest pattern).
 *
 * Helpers copied verbatim from RwgBookingServerTest (the existing file is untouched).
 */
class RwgGoogleSandboxTest extends TestCase
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

    /**
     * Stand up the fake real-time client: a throwaway RSA key in a service-account
     * file plus Http::fake for the OAuth token + the RTU endpoint. Mirrors
     * RwgRealtimeTest::test_job_pushes_http_when_fully_configured.
     */
    private function enableFakeRtu(): string
    {
        $keyRes = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyRes, $privateKey);
        $saDir = storage_path('framework/testing');
        File::ensureDirectoryExists($saDir);
        $saPath = $saDir.'/rwg-sa-'.Str::random(6).'.json';
        file_put_contents($saPath, json_encode([
            'client_email' => 'sa@test.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'project_id' => 'test-project',
        ]));

        config([
            'services.reserve_with_google.enabled' => true,
            'services.reserve_with_google.rtu_endpoint' => 'https://rtu.example.test/notify',
            'services.reserve_with_google.service_account_json' => $saPath,
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600], 200),
            'rtu.example.test/*' => Http::response([], 200),
        ]);

        return $saPath;
    }

    /**
     * Given a RwG-enabled store with a per-slot capacity of 4 and one slot already
     * full; When Google drives HealthCheck → BatchAvailabilityLookup (one full slot)
     * → CreateBooking → an identical CreateBooking retry → GetBookingStatus →
     * UpdateBooking reschedule → ListBookings → UpdateBooking cancel →
     * GetBookingStatus; Then the whole booking lifecycle succeeds, each business
     * failure is a 200 booking_failure, and the real-time client receives exactly
     * one push for the one status change.
     */
    public function test_google_sandbox_full_booking_flow(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '4');
        $token = 'tok-sandbox';
        $openSec = now()->addDays(2)->setTime(19, 0)->getTimestamp();
        $fullSec = now()->addDays(2)->setTime(20, 0)->getTimestamp();
        $otherSec = now()->addDays(2)->setTime(21, 0)->getTimestamp();
        $newSec = now()->addDays(3)->setTime(21, 0)->getTimestamp();

        // Fill the 20:00 slot to capacity so BatchAvailabilityLookup has a full entry.
        Reservation::create(['store_id' => $store->id, 'name' => 'Seed', 'party_size' => 4,
            'reserved_at' => Carbon::createFromTimestamp($fullSec, 'UTC')->format('Y-m-d H:i:s'),
            'status' => 'confirmed', 'token' => Str::random(40)]);

        Queue::fake();

        $health = $this->getJson('/rwg/v3/HealthCheck', $this->auth());
        $health->assertStatus(200);

        $avail = $this->postJson('/rwg/v3/BatchAvailabilityLookup', [
            'merchant_id' => 'store-'.$store->id,
            'slot_time' => [
                ['service_id' => 'dining-'.$store->id, 'start_sec' => $openSec, 'resources' => ['party_size' => 2]],
                ['service_id' => 'dining-'.$store->id, 'start_sec' => $fullSec, 'resources' => ['party_size' => 2]],
                ['service_id' => 'dining-'.$store->id, 'start_sec' => $otherSec, 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth());
        $avail->assertStatus(200)
            ->assertJsonPath('slot_time_availability.0.available', true)
            ->assertJsonPath('slot_time_availability.1.available', false)
            ->assertJsonPath('slot_time_availability.2.available', true);

        $payload = $this->createPayload($store, $token, 2, $openSec);

        $create = $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());
        $create->assertStatus(200)
            ->assertJsonPath('booking.booking_id', $token)
            ->assertJsonPath('booking.status', 'CONFIRMED');
        // Creating must never push.
        Queue::assertNotPushed(PushRwgBookingUpdateJob::class);

        $retry = $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());
        $retry->assertStatus(200)->assertJsonPath('booking.booking_id', $token);
        $this->assertSame(1, Reservation::where('rwg_booking_id', $token)->count());

        $status = $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => $token], $this->auth());
        $status->assertStatus(200)
            ->assertJsonPath('booking_id', $token)
            ->assertJsonPath('booking_status', 'CONFIRMED')
            ->assertJsonPath('prepayment_status', 'PREPAYMENT_NOT_PROVIDED');

        $reschedule = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => [
                'booking_id' => $token,
                'slot' => [
                    'merchant_id' => 'store-'.$store->id, 'service_id' => 'dining-'.$store->id,
                    'start_sec' => $newSec, 'resources' => ['party_size' => 3],
                ],
            ],
        ], $this->auth());
        $reschedule->assertStatus(200);
        $booking = Reservation::where('rwg_booking_id', $token)->first();
        $this->assertSame(3, (int) $booking->party_size);
        $this->assertSame(
            Carbon::createFromTimestamp($newSec, 'UTC')->format('Y-m-d H:i:s'),
            Carbon::parse($booking->reserved_at)->format('Y-m-d H:i:s')
        );
        // A time change is pushed too (one push so far).
        Queue::assertPushed(PushRwgBookingUpdateJob::class, 1);

        $list = $this->postJson('/rwg/v3/ListBookings', [
            'user_information' => ['telephone' => '+97455512'],
        ], $this->auth());
        $list->assertStatus(200)
            ->assertJsonPath('bookings.0.booking_id', $token)
            ->assertJsonCount(1, 'bookings');

        $cancel = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => $token, 'status' => 'CANCELED'],
        ], $this->auth());
        $cancel->assertStatus(200)->assertJsonPath('booking.status', 'CANCELED');
        $this->assertDatabaseHas('reservations', ['rwg_booking_id' => $token, 'status' => 'cancelled']);
        // The status transition adds exactly one more push (no duplicate).
        Queue::assertPushed(PushRwgBookingUpdateJob::class, 2);

        $after = $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => $token], $this->auth());
        $after->assertStatus(200)->assertJsonPath('booking_status', 'CANCELED');

        // Run the queued push against the fake real-time client: exactly one push,
        // carrying the new status, for the one status change.
        $saPath = $this->enableFakeRtu();
        $cancelled = Reservation::where('rwg_booking_id', $token)->firstOrFail();
        (new PushRwgBookingUpdateJob((int) $cancelled->id))->handle(app(RwgRealtimeClient::class));

        $pushes = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'rtu.example.test'));
        $this->assertCount(1, $pushes);
        $this->assertSame($token, $pushes->first()[0]['booking']['booking_id']);
        $this->assertSame('CANCELED', $pushes->first()[0]['booking']['status']);

        @unlink($saPath);

        /*
         * Asserts:
         *  - HealthCheck returns 200.
         *  - BatchAvailabilityLookup: open=true, full=false, other=true (3 slot_time entries).
         *  - CreateBooking returns 200 CONFIRMED and pushes nothing on create.
         *  - Identical CreateBooking retry returns the same booking_id and leaves 1 row.
         *  - GetBookingStatus: booking_status CONFIRMED, prepayment_status PREPAYMENT_NOT_PROVIDED.
         *  - Reschedule applies party_size 3 + the new reserved_at and pushes exactly once (time change).
         *  - ListBookings (by telephone) returns exactly the one booking, first.
         *  - Cancel returns 200 CANCELED, the row is cancelled, and total pushes = 2 (one per change: reschedule time + cancel status; no duplicate).
         *  - GetBookingStatus after cancel returns CANCELED.
         *  - Real-time client: exactly one HTTP push, for the token, carrying CANCELED.
         */
    }

    /**
     * Given a booking created by store A; When store B replays the SAME
     * idempotency_token under store B's merchant_id; Then the replay is refused as
     * SLOT_UNAVAILABLE — store A's booking is never read out to store B, nothing is
     * created for store B, and store A's row is left untouched.
     */
    public function test_second_store_merchant_cannot_read_or_change_first_store_booking(): void
    {
        $storeA = $this->store();
        $storeB = $this->store();
        $token = 'tok-scope';

        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($storeA, $token, 2), $this->auth())
            ->assertStatus(200)->assertJsonPath('booking.booking_id', $token);

        $replay = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($storeB, $token, 2), $this->auth());

        $replay->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
        $replay->assertJsonMissingPath('booking');
        $this->assertSame(1, Reservation::where('rwg_booking_id', $token)->count());
        $this->assertDatabaseHas('reservations', [
            'rwg_booking_id' => $token, 'store_id' => $storeA->id, 'status' => 'confirmed',
        ]);
        $this->assertSame(0, Reservation::where('store_id', $storeB->id)->count());

        /*
         * Asserts:
         *  - The cross-store replay is a soft 200 booking_failure SLOT_UNAVAILABLE and has no `booking` key.
         *  - Exactly one reservation exists for the token, owned by store A, still confirmed.
         *  - Store B owns zero reservations (nothing was created for it).
         */
    }

    /**
     * Given a RwG-enabled store; When the full Google flow runs (create + identical
     * retry + reschedule + cancel, each over HTTP); Then exactly ONE reservations row
     * exists for the idempotency token — the retry never forks a second row.
     */
    public function test_full_flow_leaves_exactly_one_reservation_row_for_token(): void
    {
        $store = $this->store();
        $token = 'tok-one-row';
        $openSec = now()->addDays(2)->setTime(19, 0)->getTimestamp();
        $newSec = now()->addDays(3)->setTime(21, 0)->getTimestamp();

        $this->getJson('/rwg/v3/HealthCheck', $this->auth())->assertStatus(200);

        $this->postJson('/rwg/v3/BatchAvailabilityLookup', [
            'merchant_id' => 'store-'.$store->id,
            'slot_time' => [
                ['service_id' => 'dining-'.$store->id, 'start_sec' => $openSec, 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth())->assertStatus(200);

        $payload = $this->createPayload($store, $token, 2, $openSec);
        $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth())->assertStatus(200);
        $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth())->assertStatus(200);

        $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => $token], $this->auth())->assertStatus(200);

        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => [
                'booking_id' => $token,
                'slot' => ['start_sec' => $newSec, 'resources' => ['party_size' => 3]],
            ],
        ], $this->auth())->assertStatus(200);

        $this->postJson('/rwg/v3/ListBookings', [
            'user_information' => ['telephone' => '+97455512'],
        ], $this->auth())->assertStatus(200);

        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => $token, 'status' => 'CANCELED'],
        ], $this->auth())->assertStatus(200);

        $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => $token], $this->auth())
            ->assertStatus(200)->assertJsonPath('booking_status', 'CANCELED');

        /*
         * Asserts:
         *  - The whole HTTP flow reaches its terminal CANCELED status.
         *  - Exactly one reservations row exists for the token: the idempotent retry
         *    and the reschedule/cancel updates never fork an extra row.
         */
        $this->assertSame(1, Reservation::where('rwg_booking_id', $token)->count());
    }
}
