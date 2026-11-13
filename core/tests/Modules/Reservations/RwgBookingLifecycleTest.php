<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\Installed;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Models\WebhookEndpoint;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\RememberLocale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Reservations\Jobs\PushRwgBookingUpdateJob;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — P5-18 gap fixes (G4–G7): booking-server lifecycle.
 *
 * Cancel window + terminal-state guards (G4), reschedule capacity/cancelled
 * guards + real-time push (G5), prepayment_status on status poll (G6), and
 * ListBookings ordering/cap (G7). The business-failure contract is HTTP 200
 * {"booking_failure":{"cause":X}}; malformed input is 400 {"error":"bad_request"};
 * an unknown or disabled store is 404 {"error":"not_found"}.
 */
class RwgBookingLifecycleTest extends TestCase
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

    /** Create a Google booking through the API and return the persisted row. */
    private function book(Store $store, string $token, int $party = 2, ?int $startSec = null): Reservation
    {
        $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, $token, $party, $startSec), $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_id', $token);

        return Reservation::where('rwg_booking_id', $token)->firstOrFail();
    }

    /**
     * Give the store owner an active webhook endpoint subscribed to $event, so a
     * WebhookFanout call fans out to exactly one queued DeliverWebhookJob. Pair with
     * Queue::fake() to count the fan-out without any real delivery.
     */
    private function subscribeWebhook(Store $store, string $event): void
    {
        WebhookEndpoint::create([
            'user_id' => (int) $store->user_id,
            'url' => 'https://hooks.example.test/juwri',
            'secret' => 'whsec_test',
            'events' => [$event],
            'active' => true,
        ]);
    }

    /** Unix seconds for a store-local slot $days ahead at $hour:00 (app tz = UTC in tests). */
    private function at(int $days, int $hour): int
    {
        return now()->addDays($days)->setTime($hour, 0)->getTimestamp();
    }

    /**
     * Given a store whose cancel window is ten days,
     * When  UpdateBooking cancels a booking two days out,
     * Then  it is refused and the row stays confirmed.
     */
    public function test_cancel_inside_window_is_refused(): void
    {
        $store = $this->store();
        $this->book($store, 'tok-window-in');
        StoreOption::updateStoreOption($store->id, 'reservation_cancel_window_minutes', '14400');

        $res = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-window-in', 'status' => 'CANCELED'],
        ], $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'BOOKING_CANCELLATION_NOT_ALLOWED');
        $this->assertDatabaseHas('reservations', ['rwg_booking_id' => 'tok-window-in', 'status' => 'confirmed']);
        // Asserts: HTTP 200; cause BOOKING_CANCELLATION_NOT_ALLOWED; status unchanged (confirmed).
    }

    /**
     * Given a store with no cancel window (default 0 = up to the start time),
     * When  UpdateBooking cancels a future booking,
     * Then  it returns CANCELED and the row flips to cancelled.
     *
     * (The controller fires one `reservation.cancelled` webhook; this asserts the
     * observed status only — the webhook count is covered by test_cancel_twice_is_idempotent.)
     */
    public function test_cancel_outside_window_succeeds(): void
    {
        $store = $this->store();
        $this->book($store, 'tok-window-out');

        $res = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-window-out', 'status' => 'CANCELED'],
        ], $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking.status', 'CANCELED');
        $this->assertDatabaseHas('reservations', ['rwg_booking_id' => 'tok-window-out', 'status' => 'cancelled']);
        // Asserts: HTTP 200; booking.status CANCELED; row status cancelled.
    }

    /**
     * Given a booking whose store subscribes to the cancel webhook,
     * When  UpdateBooking cancels it and then cancels it a second time,
     * Then  exactly ONE reservation.cancelled webhook fires and the second response
     *       still shows CANCELED with the row untouched (no second write).
     */
    public function test_cancel_twice_is_idempotent(): void
    {
        Queue::fake();
        $store = $this->store();
        $this->subscribeWebhook($store, 'reservation.cancelled');
        $booking = $this->book($store, 'tok-twice');

        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-twice', 'status' => 'CANCELED'],
        ], $this->auth())->assertStatus(200)->assertJsonPath('booking.status', 'CANCELED');

        $updatedAt = $booking->fresh()->updated_at->toDateTimeString();

        $second = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-twice', 'status' => 'CANCELED'],
        ], $this->auth());

        $second->assertStatus(200)->assertJsonPath('booking.status', 'CANCELED');
        $this->assertSame($updatedAt, $booking->fresh()->updated_at->toDateTimeString());
        $this->assertSame(1, Reservation::where('rwg_booking_id', 'tok-twice')->count());

        // The second cancel must not fan out a second webhook: exactly one delivery job.
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $j) => $j->event === 'reservation.cancelled');
        // Asserts: second call returns CANCELED; updated_at unchanged; exactly one row;
        // exactly one reservation.cancelled webhook (idempotent — no second fan-out).
    }

    /**
     * Given bookings in the terminal seated / no-show states,
     * When  UpdateBooking tries to cancel them,
     * Then  both are refused and their statuses are unchanged.
     */
    public function test_cancel_seated_or_no_show_booking_is_refused(): void
    {
        $store = $this->store();
        $this->book($store, 'tok-seated');
        $this->book($store, 'tok-noshow');
        // Terminal states set out-of-band (mass update — no model events).
        Reservation::where('rwg_booking_id', 'tok-seated')->update(['status' => 'seated']);
        Reservation::where('rwg_booking_id', 'tok-noshow')->update(['status' => 'no_show']);

        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-seated', 'status' => 'CANCELED'],
        ], $this->auth())->assertStatus(200)->assertJsonPath('booking_failure.cause', 'BOOKING_CANCELLATION_NOT_ALLOWED');

        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-noshow', 'status' => 'CANCELED'],
        ], $this->auth())->assertStatus(200)->assertJsonPath('booking_failure.cause', 'BOOKING_CANCELLATION_NOT_ALLOWED');

        $this->assertDatabaseHas('reservations', ['rwg_booking_id' => 'tok-seated', 'status' => 'seated']);
        $this->assertDatabaseHas('reservations', ['rwg_booking_id' => 'tok-noshow', 'status' => 'no_show']);
        // Asserts: both refused with BOOKING_CANCELLATION_NOT_ALLOWED; statuses stay seated / no_show.
    }

    /**
     * Given a slot filled to capacity by the booking itself,
     * When  UpdateBooking reschedules it within that same slot,
     * Then  the move succeeds (the booking's own covers are excluded).
     */
    public function test_reschedule_within_own_full_slot_succeeds(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '2');
        $startSec = $this->at(2, 19);
        $booking = $this->book($store, 'tok-own-slot', 2, $startSec);

        $res = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => [
                'booking_id' => 'tok-own-slot',
                'slot' => ['start_sec' => $startSec, 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking.booking_id', 'tok-own-slot');
        $fresh = $booking->fresh();
        $this->assertSame(2, (int) $fresh->party_size);
        $this->assertSame(
            Carbon::createFromTimestamp($startSec, 'UTC')->format('Y-m-d H:i:s'),
            Carbon::parse($fresh->reserved_at)->format('Y-m-d H:i:s')
        );
        // Asserts: HTTP 200; booking id echoed; party + slot time unchanged (own covers ignored).
    }

    /**
     * Given a booking and another time slot that is already full,
     * When  UpdateBooking reschedules the booking into that full slot,
     * Then  it fails SLOT_UNAVAILABLE and the original time is kept.
     */
    public function test_reschedule_into_full_other_slot_fails_and_keeps_original(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '2');
        $t1 = $this->at(2, 19);
        $t2 = $this->at(3, 20);
        $this->book($store, 'tok-fills-t2', 2, $t2); // fills T2
        $mover = $this->book($store, 'tok-mover', 2, $t1);

        $res = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => [
                'booking_id' => 'tok-mover',
                'slot' => ['start_sec' => $t2, 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
        $this->assertSame(
            Carbon::createFromTimestamp($t1, 'UTC')->format('Y-m-d H:i:s'),
            Carbon::parse($mover->fresh()->reserved_at)->format('Y-m-d H:i:s')
        );
        // Asserts: HTTP 200; cause SLOT_UNAVAILABLE; mover keeps its original reserved_at.
    }

    /**
     * Given a cancelled booking,
     * When  UpdateBooking tries to reschedule it,
     * Then  it is refused (a cancelled booking can never be revived).
     */
    public function test_reschedule_cancelled_booking_is_refused(): void
    {
        Queue::fake();
        $store = $this->store();
        $this->book($store, 'tok-cancel-move');
        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => ['booking_id' => 'tok-cancel-move', 'status' => 'CANCELED'],
        ], $this->auth())->assertStatus(200)->assertJsonPath('booking.status', 'CANCELED');

        $res = $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => [
                'booking_id' => 'tok-cancel-move',
                'slot' => ['start_sec' => $this->at(4, 20), 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth());

        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
        $this->assertDatabaseHas('reservations', ['rwg_booking_id' => 'tok-cancel-move', 'status' => 'cancelled']);
        // Asserts: HTTP 200; cause SLOT_UNAVAILABLE; the booking stays cancelled.
    }

    /**
     * Given a two-cover slot and two SEQUENTIAL CreateBooking calls for a party of two
     * (this exercises the capacity check, not a race — the requests do not overlap),
     * When  both aim at the same time with different tokens,
     * Then  exactly one succeeds (the other SLOT_UNAVAILABLE) and one row exists.
     */
    public function test_sequential_create_into_one_slot_admits_exactly_one(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '2');
        $startSec = $this->at(2, 19);

        $first = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-race-a', 2, $startSec), $this->auth());
        $second = $this->postJson('/rwg/v3/CreateBooking', $this->createPayload($store, 'tok-race-b', 2, $startSec), $this->auth());

        $first->assertStatus(200)->assertJsonPath('booking.status', 'CONFIRMED');
        $second->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
        $this->assertSame(1, Reservation::where('store_id', $store->id)->count());
        // Asserts: first CONFIRMED; second SLOT_UNAVAILABLE; exactly one reservation row.
    }

    /**
     * Given a live Google booking,
     * When  UpdateBooking moves it to a new time,
     * Then  a PushRwgBookingUpdateJob is dispatched for that booking.
     */
    public function test_reschedule_dispatches_rtu_push(): void
    {
        Queue::fake();
        $store = $this->store();
        $booking = $this->book($store, 'tok-push');
        $newSec = $this->at(4, 20);

        $this->postJson('/rwg/v3/UpdateBooking', [
            'booking' => [
                'booking_id' => 'tok-push',
                'slot' => ['start_sec' => $newSec, 'resources' => ['party_size' => 2]],
            ],
        ], $this->auth())->assertStatus(200);

        Queue::assertPushed(PushRwgBookingUpdateJob::class, 1);
        Queue::assertPushed(PushRwgBookingUpdateJob::class,
            fn (PushRwgBookingUpdateJob $j) => $j->reservationId === $booking->id);
        // Asserts: exactly one PushRwgBookingUpdateJob dispatched for this reservation.
    }

    /**
     * Given a live Google booking,
     * When  GetBookingStatus polls it,
     * Then  the response carries booking_status and prepayment_status.
     */
    public function test_get_booking_status_includes_prepayment_status(): void
    {
        $store = $this->store();
        $this->book($store, 'tok-prepay');

        $res = $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => 'tok-prepay'], $this->auth());

        $res->assertStatus(200)
            ->assertJsonPath('booking_id', 'tok-prepay')
            ->assertJsonPath('booking_status', 'CONFIRMED')
            ->assertJsonPath('prepayment_status', 'PREPAYMENT_NOT_PROVIDED');
        // Asserts: booking_id echoed; booking_status CONFIRMED; prepayment_status PREPAYMENT_NOT_PROVIDED.
    }

    /**
     * Given 55 Google bookings for one diner (newest first by reserved_at),
     * When  ListBookings is called for that contact,
     * Then  it returns the 50 newest, ordered newest first.
     */
    public function test_list_bookings_newest_first_and_capped_at_fifty(): void
    {
        $store = $this->store();
        $base = now()->subDay()->startOfDay();
        (function () use ($store, $base): void {
            for ($i = 0; $i < 55; $i++) {
                Reservation::create([
                    'store_id' => $store->id, 'name' => 'G', 'phone' => '+97455500', 'party_size' => 2,
                    'reserved_at' => $base->copy()->subMinutes($i)->format('Y-m-d H:i:s'),
                    'status' => 'confirmed', 'token' => Str::random(40),
                    'rwg_booking_id' => 'tok-list-'.$i, 'source' => 'google',
                ]);
            }
        })();

        $res = $this->postJson('/rwg/v3/ListBookings', [
            'user_information' => ['telephone' => '+97455500'],
        ], $this->auth());

        $res->assertStatus(200)
            ->assertJsonCount(50, 'bookings')
            ->assertJsonPath('bookings.0.booking_id', 'tok-list-0')
            ->assertJsonPath('bookings.49.booking_id', 'tok-list-49');
        // Asserts: HTTP 200; exactly 50 rows; newest (tok-list-0) first; 50th newest (tok-list-49) last.
    }
}
