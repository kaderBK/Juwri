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
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — P5-24 "Google merchant = branch" booking server.
 *
 * The feed publishes `store-N` for a one-eligible-branch store and `store-N-bB` per
 * branch when a store has two or more eligible branches (active + coordinates). The
 * booking server must accept exactly what the feed published: resolve the branch,
 * refuse a merchant the feed never published (cross-store / inactive / coordless
 * branch, bare id on a multi-branch store, branch id on a one-branch store), enforce
 * that branch's hours + timezone in availability/create, and store branch_id on the
 * created reservation. A one-branch (or branch-less) store keeps the historic
 * `store-N` / `dining-N` byte-identical.
 *
 * The setUp/auth/store fixtures mirror RwgBookingServerGapsTest so the suite shares
 * the same shape.
 */
class RwgBranchBookingServerTest extends TestCase
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

    /** A full open week, with the given per-day overrides merged in. */
    private function weekOpen(array $overrides = []): array
    {
        $hours = [];
        foreach (Branch::DAYS as $day) {
            $hours[$day] = ['open' => '00:00', 'close' => '23:59', 'closed' => false];
        }

        return array_replace($hours, $overrides);
    }

    /** An eligible branch (active + coordinates) carrying hours (override a day to close it / narrow it). */
    private function branch(Store $store, array $dayOverrides = []): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => 'B'.Str::random(4), 'active' => 1, 'accepting_orders' => 1,
            'timezone' => 'UTC', 'latitude' => 25.2854, 'longitude' => 51.5310,
            'hours' => $this->weekOpen($dayOverrides),
        ]);
    }

    /** The Branch::DAYS key for the weekday the given start_sec falls on (in UTC). */
    private function dayKey(int $startSec): string
    {
        return Branch::DAYS[Carbon::createFromTimestamp($startSec, 'UTC')->dayOfWeekIso - 1];
    }

    /** A future slot two days out at the given hour (store tz = UTC in tests). */
    private function at(int $days, int $hour): int
    {
        return now()->addDays($days)->setTime($hour, 0)->getTimestamp();
    }

    private function createPayload(Store $store, string $merchantId, string $token, int $party = 2, ?int $startSec = null): array
    {
        $startSec ??= $this->at(2, 19);

        return [
            'idempotency_token' => $token,
            'slot' => [
                'merchant_id' => $merchantId,
                'service_id' => str_replace('store-', 'dining-', $merchantId),
                'start_sec' => $startSec, 'duration_sec' => 1800,
                'resources' => ['party_size' => $party],
            ],
            'user_information' => [
                'given_name' => 'Jane', 'family_name' => 'Doe',
                'telephone' => '+97455512', 'email' => 'jane@example.com',
            ],
        ];
    }

    /** Create a booking and return the raw response. */
    private function create(array $payload)
    {
        return $this->postJson('/rwg/v3/CreateBooking', $payload, $this->auth());
    }

    /** Ask BatchAvailabilityLookup about one slot for one merchant. */
    private function available(string $merchantId, int $startSec, int $party = 2)
    {
        return $this->postJson('/rwg/v3/BatchAvailabilityLookup', [
            'merchant_id' => $merchantId,
            'slot_time' => [['start_sec' => $startSec, 'resources' => ['party_size' => $party]]],
        ], $this->auth());
    }

    /**
     * Given a store with two eligible branches,
     * When  CreateBooking names the second branch's per-branch merchant id,
     * Then  the booking is created on THAT branch and echoes the branch merchant id.
     */
    public function test_multi_branch_booking_is_created_on_that_branch(): void
    {
        $store = $this->store();
        $this->branch($store); // first eligible branch
        $b2 = $this->branch($store); // second eligible branch → per-branch merchants
        $merchantId = 'store-'.$store->id.'-b'.$b2->id;
        $startSec = $this->at(2, 19);

        $res = $this->create($this->createPayload($store, $merchantId, 'tok-b2', 2, $startSec));

        $res->assertStatus(200)
            ->assertJsonPath('booking.booking_id', 'tok-b2')
            ->assertJsonPath('booking.status', 'CONFIRMED')
            ->assertJsonPath('booking.slot.merchant_id', $merchantId)
            ->assertJsonPath('booking.slot.service_id', 'dining-'.$store->id.'-b'.$b2->id);

        $this->assertDatabaseHas('reservations', [
            'rwg_booking_id' => 'tok-b2', 'store_id' => $store->id, 'branch_id' => $b2->id, 'status' => 'confirmed',
        ]);
    }

    /**
     * Given a store with two eligible branches,
     * When  CreateBooking names the bare `store-N` id the feed did NOT publish for it,
     * Then  it is refused (SLOT_UNAVAILABLE) and no row is written; availability agrees.
     */
    public function test_multi_branch_store_rejects_bare_store_merchant(): void
    {
        $store = $this->store();
        $this->branch($store);
        $this->branch($store);
        $startSec = $this->at(2, 19);
        $merchantId = 'store-'.$store->id;

        $this->create($this->createPayload($store, $merchantId, 'tok-bare', 2, $startSec))
            ->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $this->available($merchantId, $startSec)
            ->assertStatus(200)->assertJsonPath('slot_time_availability.0.available', false);

        $this->assertSame(0, Reservation::where('rwg_booking_id', 'tok-bare')->count());
    }

    /**
     * Given a store with exactly one eligible branch,
     * When  CreateBooking names a `store-N-bB` id the feed did NOT publish for it,
     * Then  it is refused and availability reports the slot unavailable.
     */
    public function test_one_branch_store_rejects_branch_merchant(): void
    {
        $store = $this->store();
        $branch = $this->branch($store);
        $startSec = $this->at(2, 19);
        $merchantId = 'store-'.$store->id.'-b'.$branch->id;

        $this->create($this->createPayload($store, $merchantId, 'tok-single-b', 2, $startSec))
            ->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $this->available($merchantId, $startSec)
            ->assertStatus(200)->assertJsonPath('slot_time_availability.0.available', false);

        $this->assertSame(0, Reservation::where('rwg_booking_id', 'tok-single-b')->count());
    }

    /**
     * Given a store with exactly one eligible branch,
     * When  CreateBooking names the bare `store-N` id the feed publishes for it,
     * Then  it succeeds, stores branch_id, and keeps the unchanged store merchant id.
     */
    public function test_one_branch_store_keeps_unchanged_store_merchant(): void
    {
        $store = $this->store();
        $branch = $this->branch($store);
        $merchantId = 'store-'.$store->id;

        $res = $this->create($this->createPayload($store, $merchantId, 'tok-single', 2, $this->at(2, 19)));

        $res->assertStatus(200)
            ->assertJsonPath('booking.booking_id', 'tok-single')
            ->assertJsonPath('booking.slot.merchant_id', $merchantId)
            ->assertJsonPath('booking.slot.service_id', 'dining-'.$store->id);

        $this->assertDatabaseHas('reservations', [
            'rwg_booking_id' => 'tok-single', 'store_id' => $store->id, 'branch_id' => $branch->id, 'status' => 'confirmed',
        ]);
    }

    /**
     * Given a multi-branch store whose second branch opens only 09:00–10:00 on the slot's day,
     * When  a 20:00 booking is requested for that branch,
     * Then  create AND availability both refuse it (outside the branch's hours) and no row is written.
     */
    public function test_branch_closed_hours_refuse_create_and_availability(): void
    {
        $store = $this->store();
        $this->branch($store);
        $startSec = $this->at(2, 20);
        $day = $this->dayKey($startSec);
        $b2 = $this->branch($store, [$day => ['open' => '09:00', 'close' => '10:00', 'closed' => false]]);
        $merchantId = 'store-'.$store->id.'-b'.$b2->id;

        $this->create($this->createPayload($store, $merchantId, 'tok-hours', 2, $startSec))
            ->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $this->available($merchantId, $startSec)
            ->assertStatus(200)->assertJsonPath('slot_time_availability.0.available', false);

        $this->assertSame(0, Reservation::where('rwg_booking_id', 'tok-hours')->count());
    }

    /**
     * Given a multi-branch store whose second branch is CLOSED on the slot's day,
     * When  that branch is booked,
     * Then  create AND availability both refuse it (closed day) and no row is written.
     */
    public function test_branch_closed_day_refuses_create_and_availability(): void
    {
        $store = $this->store();
        $this->branch($store);
        $startSec = $this->at(2, 19);
        $day = $this->dayKey($startSec);
        $b2 = $this->branch($store, [$day => ['open' => '00:00', 'close' => '23:59', 'closed' => true]]);
        $merchantId = 'store-'.$store->id.'-b'.$b2->id;

        $this->create($this->createPayload($store, $merchantId, 'tok-closed-day', 2, $startSec))
            ->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $this->available($merchantId, $startSec)
            ->assertStatus(200)->assertJsonPath('slot_time_availability.0.available', false);

        $this->assertSame(0, Reservation::where('rwg_booking_id', 'tok-closed-day')->count());
    }

    /**
     * Given store A (multi-branch) and store B,
     * When  CreateBooking names `store-A-b<branch of store B>`,
     * Then  it is refused (a cross-store branch id is never accepted) and availability agrees.
     */
    public function test_cross_store_branch_merchant_id_is_refused(): void
    {
        $storeA = $this->store();
        $this->branch($storeA);
        $this->branch($storeA);
        $storeB = $this->store();
        $foreign = $this->branch($storeB);
        $startSec = $this->at(2, 19);
        $merchantId = 'store-'.$storeA->id.'-b'.$foreign->id;

        $this->create($this->createPayload($storeA, $merchantId, 'tok-cross-branch', 2, $startSec))
            ->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $this->available($merchantId, $startSec)
            ->assertStatus(200)->assertJsonPath('slot_time_availability.0.available', false);

        $this->assertSame(0, Reservation::all()->count());
    }

    /**
     * Given a multi-branch store and an INACTIVE branch of that store,
     * When  that branch's merchant id is booked,
     * Then  it is refused (only active, geocoded branches are eligible) and no row is written.
     */
    public function test_inactive_branch_is_not_eligible(): void
    {
        $store = $this->store();
        $this->branch($store);
        $inactive = Branch::create([
            'store_id' => $store->id, 'name' => 'Inactive', 'active' => 0, 'accepting_orders' => 1,
            'timezone' => 'UTC', 'latitude' => 25.2854, 'longitude' => 51.5310, 'hours' => $this->weekOpen(),
        ]);
        $startSec = $this->at(2, 19);
        $merchantId = 'store-'.$store->id.'-b'.$inactive->id;

        $this->create($this->createPayload($store, $merchantId, 'tok-inactive', 2, $startSec))
            ->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');

        $this->assertSame(0, Reservation::where('rwg_booking_id', 'tok-inactive')->count());
    }

    /**
     * Given a multi-branch store,
     * When  GetBookingStatus polls a branch booking,
     * Then  the stored branch merchant id is echoed back (list/status keep working).
     */
    public function test_get_booking_status_echoes_branch_merchant(): void
    {
        $store = $this->store();
        $this->branch($store);
        $b2 = $this->branch($store);
        $merchantId = 'store-'.$store->id.'-b'.$b2->id;
        $token = 'tok-status-b';

        $this->create($this->createPayload($store, $merchantId, $token, 2, $this->at(2, 19)))
            ->assertStatus(200)->assertJsonPath('booking.slot.merchant_id', $merchantId);

        $this->postJson('/rwg/v3/GetBookingStatus', ['booking_id' => $token], $this->auth())
            ->assertStatus(200)->assertJsonPath('booking_status', 'CONFIRMED');

        $list = $this->postJson('/rwg/v3/ListBookings', [
            'user_information' => ['telephone' => '+97455512'],
        ], $this->auth());

        $list->assertStatus(200)
            ->assertJsonPath('bookings.0.booking_id', $token)
            ->assertJsonPath('bookings.0.slot.merchant_id', $merchantId)
            ->assertJsonPath('bookings.0.slot.service_id', 'dining-'.$store->id.'-b'.$b2->id);
    }
}
