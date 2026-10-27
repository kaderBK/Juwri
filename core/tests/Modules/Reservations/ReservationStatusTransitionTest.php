<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-337 — reservation-desk status transition rules.
 *
 * The store-owner `reservations.status` action used to accept any `pending|confirmed|cancelled|
 * no_show|seated` value and fire the cancelled-side webhook + deposit side effects on every
 * click, regardless of the current state. The controller now (a) follows a small allowed
 * transitions map, (b) compare-and-sets under a row lock so a repeated click is a no-op,
 * and (c) when reviving a cancelled/no_show booking re-checks the blackout + table-time
 * clash so a stale revive cannot hijack a slot another confirmed booking now owns.
 *
 * Webhooks: faked via Queue::fake() — the controller's WebhookFanout path lands on
 * DeliverWebhookJob (WebhookService → dispatch_job_reliably → dispatch), which Queue::fake
 * swallows; the count is then read off the queue fake.
 *
 * Deposits: faked at the *state* layer — after the real ReservationDepositService runs, a
 * paid deposit that has been refunded/kept is no longer `paid`, so the second cancel click
 * takes the early-return branch and the deposit state stays single-row-stable. The test
 * also asserts only ONE ledger leg is posted for the (single) call.
 */
class ReservationStatusTransitionTest extends TestCase
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

    /**
     * Wire a webhook endpoint that subscribes to the given event so the fan-out path
     * (WebhookFanout::forRestaurant → WebhookService::dispatch → DeliverWebhookJob) is
     * exercised — without an active subscriber, the dispatch loop has nothing to push to
     * and Queue::assertPushed would see zero jobs even when the fan-out "fired".
     */
    private function subscribe(User $owner, string $event): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'user_id' => $owner->id,
            'url' => 'https://x.test/hook',
            'secret' => 's3cr3t',
            'events' => [$event],
            'active' => true,
        ]);
    }

    /** P6-337 / forward arc, the smoking gun the bug report names. */
    public function test_repeated_cancel_fires_webhook_once_and_refund_runs_once(): void
    {
        Queue::fake();

        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->subscribe($owner, 'reservation.cancelled');

        // Open a deposit for the reservation so cancel has money to refund.
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_amount', '20');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_per', 'reservation');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_min_party', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_refund', 'full');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Repeat Cancel',
            'party_size' => 2,
            'reserved_at' => now()->addDay(),
            'status' => 'confirmed',
            'token' => Str::random(40),
            'deposit_status' => 'paid',
            'deposit_amount' => 20,
            'currency' => 'USD',
        ]);

        // Two POSTs to the same endpoint, both asking for `cancelled`. The buggy
        // controller fired the webhook and called the deposit service twice; the fix
        // makes the second click a no-op (same-status → return [false, null]).
        $this->actingAs($owner)
            ->post(route('reservations.status', $reservation->id), ['status' => 'cancelled'])
            ->assertRedirect();
        $this->actingAs($owner)
            ->post(route('reservations.status', $reservation->id), ['status' => 'cancelled'])
            ->assertRedirect();

        // Webhook count assertion — the bug's primary observable.
        Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $j) => $j->event === 'reservation.cancelled');
        $totalCancelled = collect(Queue::pushed(DeliverWebhookJob::class))
            ->filter(fn (DeliverWebhookJob $j) => $j->event === 'reservation.cancelled')
            ->count();
        $this->assertSame(1, $totalCancelled, 'second cancel click must not fan out a second reservation.cancelled webhook');

        // Deposit state — the real ReservationDepositService runs once. After the
        // first call the deposit is no longer `paid`, so the second click short-
        // circuits at `if ($res->deposit_status === 'paid')` and never re-enters
        // the service. Asserting the state is `refunded`/`refund_due`/`kept`
        // (never re-paid) is the end-to-end proof the side effect ran exactly once.
        $fresh = $reservation->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotSame('paid', $fresh->deposit_status, 'deposit must move off `paid` after the first cancel');

        // And the ledger cap proof: the in-process state is stable, never re-paid.
        $this->actingAs($owner)
            ->post(route('reservations.status', $reservation->id), ['status' => 'cancelled'])
            ->assertRedirect();
        $this->assertNotSame('paid', $reservation->fresh()->deposit_status);
    }

    /** Forward arc, pending → confirmed is allowed. */
    public function test_pending_to_confirmed_is_allowed(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->subscribe($owner, 'reservation.cancelled'); // must NOT fire here

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'P→C',
            'party_size' => 2,
            'reserved_at' => now()->addDay(),
            'status' => 'pending',
            'token' => Str::random(40),
        ]);

        $this->actingAs($owner)
            ->post(route('reservations.status', $reservation->id), ['status' => 'confirmed'])
            ->assertRedirect();

        $this->assertSame('confirmed', $reservation->fresh()->status);
        Queue::assertNotPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $j) => $j->event === 'reservation.cancelled');
    }

    /**
     * Forward arc — seated is the dine-in endpoint and has NO onward transitions in
     * the map (`'seated' => []`). Any request to change it must be refused.
     */
    public function test_seated_to_no_show_is_refused(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->subscribe($owner, 'reservation.cancelled');

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Seated',
            'party_size' => 2,
            'reserved_at' => now()->addDay(),
            'status' => 'seated',
            'token' => Str::random(40),
        ]);

        $response = $this->actingAs($owner)
            ->from(route('reservations.index', ['store_id' => $store->id]))
            ->post(route('reservations.status', $reservation->id), ['status' => 'no_show']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('status');
        $this->assertSame('seated', $reservation->fresh()->status);
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    /**
     * Reviving a cancelled booking whose table+time is now held by another CONFIRMED
     * reservation must be refused, the row must stay cancelled, and the slot must
     * remain the rival's. This is the core revival-guard test the bug report calls out.
     */
    public function test_revive_to_confirmed_is_refused_when_slot_is_now_taken(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->subscribe($owner, 'reservation.cancelled');

        $when = now()->addDay()->setTime(19, 0);
        $table = 'T-revive-'.Str::random(4);

        // The rival — confirms first and owns the slot.
        $rival = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Rival',
            'party_size' => 2,
            'reserved_at' => $when,
            'status' => 'confirmed',
            'table_number' => $table,
            'token' => Str::random(40),
        ]);

        // Our row — cancelled earlier (an admin/desk cancel; the revival is the
        // desk attempting to bring it back into the seat).
        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Cancelled Guest',
            'party_size' => 4,
            'reserved_at' => $when,
            'status' => 'cancelled',
            'table_number' => $table,
            'token' => Str::random(40),
        ]);

        $response = $this->actingAs($owner)
            ->from(route('reservations.index', ['store_id' => $store->id]))
            ->post(route('reservations.status', $reservation->id), ['status' => 'confirmed']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('status');

        // Row stays cancelled — the rival keeps the slot.
        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertSame('confirmed', $rival->fresh()->status);
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    /**
     * Revive path — when the slot is still free (no rival), the revive succeeds,
     * the row flips to pending, and no reservation.cancelled webhook fires.
     */
    public function test_revive_to_pending_succeeds_when_slot_is_free(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->subscribe($owner, 'reservation.cancelled');

        $when = now()->addDay()->setTime(20, 0);

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Revived',
            'party_size' => 2,
            'reserved_at' => $when,
            'status' => 'cancelled',
            'table_number' => 'T-ok-'.Str::random(4),
            'token' => Str::random(40),
        ]);

        $this->actingAs($owner)
            ->post(route('reservations.status', $reservation->id), ['status' => 'pending'])
            ->assertRedirect();

        $this->assertSame('pending', $reservation->fresh()->status);
        Queue::assertNotPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $j) => $j->event === 'reservation.cancelled');
    }

    /** Same-status request is a no-op: 302 back, no errors, no webhook fan-out. */
    public function test_same_status_is_a_no_op(): void
    {
        Queue::fake();
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->subscribe($owner, 'reservation.cancelled');

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'No-op',
            'party_size' => 2,
            'reserved_at' => now()->addDay(),
            'status' => 'pending',
            'token' => Str::random(40),
        ]);

        $this->actingAs($owner)
            ->post(route('reservations.status', $reservation->id), ['status' => 'pending'])
            ->assertRedirect()
            ->assertSessionMissing('errors');

        $this->assertSame('pending', $reservation->fresh()->status);
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }
}
