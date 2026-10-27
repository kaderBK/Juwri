<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\GiftCard;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Models\WaitlistEntry;
use App\Services\Catering\CateringService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Platform E2E · B11 — the hospitality features seam for owner management of
 * customer requests: reservations, waitlist, catering, and gift cards.
 *
 * The `platform/b-hospitality.js` journey:
 *   1. Owner logs in to dashboard
 *   2. Visits reservations page and confirms pending requests
 *   3. Visits waitlist page and seats a party (with terminal-state guard)
 *   4. Visits catering page and accepts requests (deposit link)
 *   5. Visits gift cards page
 *   6. Issues a new gift card with amount
 *   7. Adjusts an existing gift card balance (idempotent via nonce)
 *   8. Views expired gift cards with expiry pill
 *
 * Discriminating tests:
 *   - Reservation creation and owner confirmation workflow
 *   - Waitlist party status transitions (pending → seated)
 *   - Terminal-state guard: cannot seat same party twice
 *   - Catering request acceptance and deposit link generation
 *   - Gift card issuance with amount tracking
 *   - Gift card balance adjustments and ledger entries
 *   - Gift card expiry tracking and display
 *   - Idempotency: double-submit on gift card adjust via nonce
 *
 * LIVE CONTRACT (schema/model/route drift from the original suite):
 *   - Reservations: `App\Models\Reservation` fields are `name`/`phone`/`email`
 *     (never `customer_name`/`customer_email`). The owner action route is
 *     `reservations.status` (POST `{id}/status`, body `status`) — there is no
 *     `reservations.confirm`. `feature:reservations` ships ON by default.
 *   - Waitlist: the model is `App\Models\WaitlistEntry` (there is no
 *     `App\Models\Waitlist`), field `name` (not `customer_name`), no
 *     `joined_at` column. The owner route is `waitlist.seat` under
 *     `restaurant/{storeId}/waitlist/{entry}/seat` — both route params are
 *     required (never a bare `id`). The terminal-state guard aborts 422, not
 *     403/409 (`Juwri\Modules\Reservations\Http\Controllers\User\WaitlistController::seat`).
 *     `feature:waitlist` ships ON by default.
 *   - Catering: the owner route lives under `restaurants.catering.*`
 *     (`restaurants.catering.accept`, not `catering.accept`), gated by
 *     `feature:catering` which ships DARK — tests must flip
 *     `config(['settings.admin_allow_catering' => true])`. Accepting a request
 *     mints a real `Order` via `CateringService::accept()`
 *     (`App\Services\Catering\CateringService`), so a request needs a real
 *     `Menu` item to price the order; the deposit link is only armed when
 *     `catering_deposit_enabled`/`catering_deposit_amount` store_options are set
 *     and is flashed as session `catering_deposit_link` (never a JSON body key).
 *   - Gift cards: routes are `restaurants.giftcards.issue`/`.void`/`.adjust`/
 *     `.index` (never a bare `giftcards.*`, and `issue` not `store`). A card's
 *     spendable value is NEVER a `balance` column — `App\Models\GiftCard` has
 *     no such column; it is derived from the double-entry ledger via
 *     `$card->balance()` (a method, not an attribute). The adjust endpoint's
 *     fields are `delta` (signed amount) and `idempotency_token` (not
 *     `adjustment`/`nonce`). `feature:gift_cards` ships ON by default.
 *     All four gift-card actions (`issue`/`void`/`adjust`/`index`-write) return
 *     a 302 `back()`, never a 200 JSON body.
 */
class PlatformE2eB11HospitalityTest extends PlatformE2eTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals();

        // `catering` ships DARK (registry default OFF); reservations/waitlist/
        // gift_cards all ship ON by default and need no flip.
        config(['settings.admin_allow_catering' => true]);
    }

    /**
     * Test 1: Reservation creation and owner confirmation workflow.
     */
    public function test_reservation_creation_and_confirmation(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $reservation = Reservation::create([
            'store_id' => $store->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'party_size' => 4,
            'reserved_at' => now()->addHours(2),
            'status' => 'pending',
        ]);

        // Act: Owner confirms the reservation via the real status-update route.
        $response = $this->actingAs($owner)
            ->post(route('reservations.status', ['id' => $reservation->id]), [
                'status' => 'confirmed',
            ]);

        $response->assertRedirect();

        // Assert: reservation status updated
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    /**
     * Test 2: Waitlist party status transitions (pending → seated).
     */
    public function test_waitlist_party_seating_transition(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $entry = WaitlistEntry::create([
            'store_id' => $store->id,
            'name' => 'Test Party',
            'party_size' => 2,
            'status' => 'waiting',
        ]);

        // Act: Owner seats the party. Both route params are required.
        $response = $this->actingAs($owner)
            ->post(route('waitlist.seat', ['storeId' => $store->id, 'entry' => $entry->id]));

        $response->assertRedirect();

        // Assert: status updated to seated
        $this->assertSame('seated', $entry->fresh()->status);
    }

    /**
     * Test 3: Terminal-state guard — cannot seat same party twice.
     */
    public function test_waitlist_terminal_state_guard_prevents_double_seat(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $entry = WaitlistEntry::create([
            'store_id' => $store->id,
            'name' => 'Test Party',
            'party_size' => 2,
            'status' => 'seated',
        ]);

        // Act: Try to seat an already-seated party — WaitlistController::seat
        // aborts 422 for any entry not in ['waiting', 'notified'].
        $response = $this->actingAs($owner)
            ->post(route('waitlist.seat', ['storeId' => $store->id, 'entry' => $entry->id]));

        $response->assertStatus(422);

        $this->assertSame('seated', $entry->fresh()->status);
    }

    /**
     * Test 4: Catering request acceptance and deposit link generation.
     * accept() mints a real Order (App\Services\Catering\CateringService), so
     * the store needs a priced Menu item; the deposit link is only armed when
     * the store's deposit store_options are configured, and is flashed as the
     * `catering_deposit_link` session value (App\Http\Controllers\User\
     * CateringController::accept).
     */
    public function test_catering_request_acceptance_with_deposit_link(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        StoreOption::updateStoreOption($store->id, 'catering_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'catering_deposit_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'catering_deposit_amount', 75);

        Menu::create([
            'store_id' => $store->id,
            'name' => 'Catering Platter',
            'price' => 50,
            'active' => 1,
        ]);

        $catering = (new CateringService)->createRequest($store->id, [
            'name' => 'Test Catering',
            'event_at' => now()->addDays(20)->toDateTimeString(),
            'headcount' => 30,
        ]);
        $this->assertSame('requested', $catering->status);

        // A request must be quoted before it can be accepted (CateringController::accept
        // does not itself require a prior quote, but the real dashboard flow always
        // quotes first — exercise the full lifecycle rather than skip a step).
        $this->actingAs($owner)
            ->post(route('stores.catering.quote', ['id' => $catering->id, 'store_id' => $store->id]), [
                'quote_total' => 500,
            ])
            ->assertRedirect();
        $this->assertSame('quoted', $catering->fresh()->status);

        // Act: Owner accepts the catering request
        $response = $this->actingAs($owner)
            ->post(route('stores.catering.accept', ['id' => $catering->id, 'store_id' => $store->id]));

        $response->assertRedirect();

        // Assert: status updated, an Order was minted, and the deposit link is flashed
        $catering->refresh();
        $this->assertSame('accepted', $catering->status);
        $this->assertNotNull($catering->order_id);
        $this->assertSame('pending', $catering->deposit_status);

        $response->assertSessionHas('catering_deposit_link');
        $this->assertStringContainsString($catering->token, (string) session('catering_deposit_link'));
    }

    /**
     * Test 5: Gift card issuance with amount tracking. A card's spendable
     * value lives in the ledger — read via `$card->balance()`, never a
     * `balance` column/attribute.
     */
    public function test_gift_card_issuance_with_amount(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // Act: Owner issues a gift card
        $response = $this->actingAs($owner)
            ->post(route('stores.giftcards.issue', ['store_id' => $store->id]), [
                'amount' => 100,
            ]);

        $response->assertRedirect();

        // Assert: gift card created with correct amount
        $giftCard = GiftCard::where('store_id', $store->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($giftCard);
        $this->assertSame('active', $giftCard->status);
        $this->assertSame(100, (int) $giftCard->balance());
    }

    /**
     * Test 6: Gift card balance adjustments and ledger entries.
     */
    public function test_gift_card_balance_adjustment(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $giftCard = GiftCardService::issueManual($store->id, 100);

        // Act: Adjust balance (deduct 10 via the signed `delta` field)
        $response = $this->actingAs($owner)
            ->post(route('stores.giftcards.adjust', ['id' => $giftCard->id, 'store_id' => $store->id]), [
                'delta' => -10,
            ]);

        $response->assertRedirect();

        // Assert: balance updated
        $this->assertSame(90, (int) $giftCard->fresh()->balance());
    }

    /**
     * Test 7: Gift card expiry tracking and display. A card past its
     * expires_at stays 'active' until the `bio:aggregate`-style daily sweep
     * (`GiftCardService::expireLapsed`) flips it — exercised here directly so
     * the index page's expiry pill has something real to show.
     */
    public function test_gift_card_expiry_tracking(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $expiredCard = GiftCardService::issueManual($store->id, 50);
        $expiredCard->forceFill(['expires_at' => now()->subDay()])->save();

        $activeCard = GiftCardService::issueManual($store->id, 100);
        $activeCard->forceFill(['expires_at' => now()->addMonths(6)])->save();

        GiftCardService::expireLapsed();

        // Act: Get gift cards page
        $response = $this->actingAs($owner)
            ->get(route('stores.giftcards.index', ['store_id' => $store->id]));

        $response->assertOk();

        // Assert: both cards shown, expired card marked via its status pill
        $content = $response->getContent();
        $this->assertStringContainsString($expiredCard->code, $content);
        $this->assertStringContainsString($activeCard->code, $content);
        $this->assertSame('expired', $expiredCard->fresh()->status);
        $this->assertSame('active', $activeCard->fresh()->status);
        $this->assertStringContainsString('data-status-pill="expired"', $content);
        $this->assertStringContainsString('data-status-pill="active"', $content);
    }

    /**
     * Test 8: Idempotency on gift card adjust via the `idempotency_token` field.
     * Double-submit with same token should return same result (no double-deduct):
     * GiftCardService::adjust() keys the ledger ref on the token, so a repeated
     * POST is a ledger no-op (App\Services\GiftCardService::adjust).
     */
    public function test_gift_card_adjust_idempotency_via_nonce(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $giftCard = GiftCardService::issueManual($store->id, 100);

        $token = hash('sha256', 'test-nonce-'.time());

        // Act 1: First adjustment with the token
        $response1 = $this->actingAs($owner)
            ->post(route('stores.giftcards.adjust', ['id' => $giftCard->id, 'store_id' => $store->id]), [
                'delta' => -20,
                'idempotency_token' => $token,
            ]);

        $response1->assertRedirect();
        $balance1 = $giftCard->fresh()->balance();

        // Act 2: Same POST again (simulating double-submit)
        $response2 = $this->actingAs($owner)
            ->post(route('stores.giftcards.adjust', ['id' => $giftCard->id, 'store_id' => $store->id]), [
                'delta' => -20,
                'idempotency_token' => $token,
            ]);

        $response2->assertRedirect();
        $balance2 = $giftCard->fresh()->balance();

        // Assert: balance unchanged on second submit (idempotent)
        $this->assertSame($balance1, $balance2);
        $this->assertSame(80, (int) $balance2);
    }
}
