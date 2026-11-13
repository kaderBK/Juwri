<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\TestCase;

/**
 * P6-339 — date-filter validation on the public v1 orders / reservations lists.
 *
 * Before this change, `?from`, `?to` and `?since` went straight into
 * `where('created_at', ...)`, so:
 *   - an array value (`?from[]=1`) crashed with 500;
 *   - any garbage string (`?since=abc`) was accepted and dropped silently;
 *   - a date-only `?to=2026-10-05` was treated as `Y-m-d 00:00:00`, losing the
 *     rest of that day; this is the bug the new tests prove fixed.
 *
 * The contract this test class enforces:
 *   - 422 with the standard validation envelope for malformed inputs (never 500);
 *   - date-only `?to=2026-10-05` includes an order created at 15:00 STORE TIME
 *     on 2026-10-05 and excludes one on 2026-10-06;
 *   - `?from=2026-10-05&to=2026-10-05` returns the whole store-local day;
 *   - the same logic ships on the reservations list;
 *   - the tenant isolation guarantee (another owner's store → 404) is intact.
 *
 * Auth is the integration-token path (`api/v1/stores/...` is gated by
 * `ApiTokenAuth:<ability>`, not the `kind=app` owner-app lane), mirroring
 * PublicApiTest / ApiPerPageCapTest — the OwnerAppApiTest "create owner, store
 * and token" fixture pattern is reused for the owner / store setup itself.
 */
class ApiDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function owner(): User
    {
        return User::factory()->create([
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function store(User $owner, string $timezone = 'UTC'): Store
    {
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111',
            'title' => 'R',
            'sub_title' => 'T',
            'description' => 'T',
            'main_image' => 'd.png',
            'cover_image' => 'd.png',
            'address' => '1 St',
            'phone' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($timezone !== '') {
            // Store-scoped date filters go through StoreClock::tz($storeId); the
            // tz is read from store_options('timezone') and falls back to the app
            // default. We pin a per-store timezone so the DST-aware day math
            // stays unambiguous for the assertions below.
            StoreOption::updateStoreOption($store->id, 'timezone', $timezone);
        }

        return $store;
    }

    /** Mint an integration ApiToken + return its bearer header. */
    private function bearer(User $owner, array $abilities = ['read', 'restaurants:read', 'orders:read']): array
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id,
            'name' => 'test',
            'token' => hash('sha256', $raw),
            'abilities' => $abilities,
        ]);

        return ['Authorization' => 'Bearer '.$raw];
    }

    /** Build an order with its created_at pinned to a moment in app (UTC) time. */
    private function orderAt(Store $store, string $utc): Order
    {
        return Order::create([
            'store_id' => $store->id,
            'status' => 'new',
            'type' => 'takeaway',
            'created_at' => Carbon::parse($utc, 'UTC'),
        ]);
    }

    private function reservationAt(Store $store, string $utc, string $token): Reservation
    {
        return Reservation::create([
            'store_id' => $store->id,
            'name' => 'G',
            'party_size' => 2,
            'reserved_at' => $utc,
            'status' => 'pending',
            'token' => $token,
            'created_at' => Carbon::parse($utc, 'UTC'),
        ]);
    }

    // ── input validation: garbage stays a 422, never crashes the DB ───────────

    /** An array value previously made it to the WHERE clause and 500'd. */
    public function test_array_from_filter_returns_422_not_500(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->bearer($owner);

        $this->getJson('/api/v1/stores/'.$store->id.'/orders?from%5B%5D=1', $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    /** `since=abc` was previously a silent no-op; now it's a 422. */
    public function test_garbage_since_filter_returns_422(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->bearer($owner);

        $this->getJson('/api/v1/stores/'.$store->id.'/orders?since=abc', $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('since');
    }

    // ── DST-aware day semantics ────────────────────────────────────────────────

    /**
     * `?to=2026-10-05` must include an order written at 15:00 STORE TIME on
     * that same day, and exclude one on the next day. Asia/Kolkata is UTC+5:30
     * (no DST) so 15:00 IST == 09:30 UTC; an order at 2026-10-06 00:30 IST ==
     * 2026-10-05 19:00 UTC must NOT come back under `?to=2026-10-05`.
     */
    public function test_to_date_only_includes_the_full_store_local_day(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'Asia/Kolkata');
        $headers = $this->bearer($owner);

        // 15:00 IST on 2026-10-05 — the day's morning-in-store-time order
        $inside = $this->orderAt($store, '2026-10-05 09:30:00');
        // 00:30 IST on 2026-10-06 — the day AFTER, must be excluded
        $tomorrow = $this->orderAt($store, '2026-10-05 19:00:00');

        $res = $this->getJson(
            '/api/v1/stores/'.$store->id.'/orders?to=2026-10-05',
            $headers
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($inside->id, $ids, '?to=2026-10-05 must include 15:00 IST orders');
        $this->assertNotContains($tomorrow->id, $ids, '?to=2026-10-05 must exclude 2026-10-06 00:30 IST');
    }

    /**
     * `?from=2026-10-05&to=2026-10-05` must include both an early-morning and a
     * late-evening order, both within that store-local day, and exclude an
     * order on either side.
     */
    public function test_from_and_to_same_date_returns_whole_day(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'Asia/Kolkata');
        $headers = $this->bearer($owner);

        // 2026-10-05 covers a wide stretch in IST: 00:30 → 23:30.
        $early = $this->orderAt($store, '2026-10-04 19:00:00'); // 00:30 IST on the 5th
        $late = $this->orderAt($store, '2026-10-05 18:00:00');  // 23:30 IST on the 5th
        // 2026-10-06 00:30 IST, must not be returned
        $next = $this->orderAt($store, '2026-10-05 19:00:00');
        // 2026-10-04 23:30 IST, must not be returned
        $prev = $this->orderAt($store, '2026-10-04 18:00:00');

        $res = $this->getJson(
            '/api/v1/stores/'.$store->id.'/orders?from=2026-10-05&to=2026-10-05',
            $headers
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($early->id, $ids);
        $this->assertContains($late->id, $ids);
        $this->assertNotContains($next->id, $ids, 'order after 23:59:59 IST on 2026-10-05 must be excluded');
        $this->assertNotContains($prev->id, $ids, 'order before 00:00 IST on 2026-10-05 must be excluded');
    }

    // ── parity across the two lists ────────────────────────────────────────────

    /** The reservations list applies the same DST-aware day math. */
    public function test_reservations_to_date_only_includes_the_full_store_local_day(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'Asia/Kolkata');
        $headers = $this->bearer($owner);

        $inside = $this->reservationAt($store, '2026-10-05 09:30:00', Str::random(40));
        $tomorrow = $this->reservationAt($store, '2026-10-05 19:00:00', Str::random(40));

        $res = $this->getJson(
            '/api/v1/stores/'.$store->id.'/reservations?to=2026-10-05',
            $headers
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($tomorrow->id, $ids);
    }

    /** Garbage reservation filters are a 422 too — same trait, same contract. */
    public function test_reservations_array_from_filter_returns_422(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->bearer($owner);

        $this->getJson('/api/v1/stores/'.$store->id.'/reservations?from%5B%5D=1', $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    /**
     * `?since` with a time is an inclusive lower bound on created_at: a row
     * created exactly at the since instant is kept, earlier ones are dropped.
     */
    public function test_reservations_since_filter_keeps_only_rows_created_at_or_after(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->bearer($owner);

        $before = $this->reservationAt($store, '2026-10-05 08:00:00', Str::random(40));
        // exactly at the since instant — must be included (inclusive lower bound)
        $boundary = $this->reservationAt($store, '2026-10-05 12:00:00', Str::random(40));
        $after = $this->reservationAt($store, '2026-10-05 18:00:00', Str::random(40));

        $res = $this->getJson(
            '/api/v1/stores/'.$store->id.'/reservations?since=2026-10-05T12:00:00',
            $headers
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        // newest id first (orderByDesc('id'))
        $this->assertEquals([$after->id, $boundary->id], $ids);
        $this->assertNotContains($before->id, $ids);
    }

    /**
     * A date-only `?since` starts at the store-local day's midnight: 00:30 IST
     * on 2026-10-05 is included, 23:30 IST on 2026-10-04 is not.
     */
    public function test_reservations_since_date_only_uses_store_local_day_start(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'Asia/Kolkata');
        $headers = $this->bearer($owner);

        $inside = $this->reservationAt($store, '2026-10-04 19:00:00', Str::random(40)); // 00:30 IST 10-05
        $before = $this->reservationAt($store, '2026-10-04 18:00:00', Str::random(40)); // 23:30 IST 10-04

        $res = $this->getJson(
            '/api/v1/stores/'.$store->id.'/reservations?since=2026-10-05',
            $headers
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($before->id, $ids);
    }

    /** Garbage `since` on reservations is a 422, keyed under `since`. */
    public function test_reservations_garbage_since_returns_422(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->bearer($owner);

        $this->getJson('/api/v1/stores/'.$store->id.'/reservations?since=abc', $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('since');
    }

    /** `since` and `from` both apply; the later lower bound wins. */
    public function test_reservations_since_and_from_combine(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->bearer($owner);

        $tooEarly = $this->reservationAt($store, '2026-10-05 09:00:00', Str::random(40));
        $between = $this->reservationAt($store, '2026-10-05 12:00:00', Str::random(40));
        $kept = $this->reservationAt($store, '2026-10-05 16:00:00', Str::random(40));

        $res = $this->getJson(
            '/api/v1/stores/'.$store->id.'/reservations'
                .'?from=2026-10-05T10:00:00&since=2026-10-05T14:00:00',
            $headers
        )->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEquals([$kept->id], $ids);
        $this->assertNotContains($tooEarly->id, $ids);
        $this->assertNotContains($between->id, $ids);
    }

    // ── tenant isolation ──────────────────────────────────────────────────────

    /** Token owner can't see another owner's store (the existing 404 contract). */
    public function test_another_owners_store_still_404s(): void
    {
        $owner = $this->owner();
        $theirStore = $this->store($this->owner());
        $headers = $this->bearer($owner);

        $this->getJson('/api/v1/stores/'.$theirStore->id.'/orders', $headers)
            ->assertNotFound();

        // …and the date filter on a non-owned store must not leak the 422 path
        // (it 404s BEFORE the filter is even examined).
        $this->getJson('/api/v1/stores/'.$theirStore->id.'/orders?since=abc', $headers)
            ->assertNotFound();
    }
}
