<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\ReservationDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Services\LedgerService;
use Mockery\MockInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-016: the owner's `reservations.deposit.manual-refund` endpoint — it confirms a deposit the
 * provider already refunded out-of-band (`refund_due`) and books the reversal ledger legs
 * exactly once. Scoping (another store's reservation is 404), the state guard (only `refund_due`
 * is accepted, an already-`refunded` repeat is a no-op success), and the auth wall are pinned here.
 */
class ReservationDepositManualRefundTest extends TestCase
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

    /** Deposit-configured store: 10 per guest × party 4 = 40, full refund policy. */
    /** @param array<string, mixed> $opts */
    private function storeFixture(array $opts = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (array_merge([
            'reservation_deposit_enabled' => '1', 'reservation_deposit_amount' => 10,
            'reservation_deposit_per' => 'guest', 'reservation_deposit_min_party' => 4,
            'reservation_deposit_refund' => 'full', 'currency_code' => 'USD',
        ], $opts) as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        return $r;
    }

    /** Create + pay a deposit reservation. */
    /**
     * @param  array<string, mixed>  $opts
     * @return array<int, mixed>
     */
    private function paidReservation(array $opts = []): array
    {
        $r = $this->storeFixture($opts);
        $res = Reservation::create([
            'store_id' => $r->id, 'name' => 'Guest', 'party_size' => 4,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);
        $txn = ReservationDepositService::initiate($res, 'stripe');
        ReservationDepositService::markPaid($res, $txn);

        return [$r, $res->fresh(), $txn];
    }

    /** A paid deposit forced into the `refund_due` state the endpoint accepts. */
    /**
     * @param  array<string, mixed>  $opts
     * @return array<int, mixed>
     */
    private function refundDueReservation(array $opts = []): array
    {
        [$r, $res] = $this->paidReservation($opts);
        $res->forceFill(['deposit_status' => 'refund_due'])->save();

        return [$r, $res->fresh()];
    }

    /** Merchant balance for the store in the deposit's currency. */
    private function merchantBalance(Store $r): float
    {
        $account = LedgerService::accountFor('merchant', (int) $r->user_id, 'merchant', 'USD');

        return LedgerService::balanceFor($account);
    }

    /** Ledger legs booked for this reservation's refund (one posting = two legs). */
    private function refundLedgerRows(Reservation $res): int
    {
        return DB::table('ledger_entries')->where('ref', 'like', 'resdep:refund:'.$res->id.':%')->count();
    }

    /**
     * Bind a Gateways double whose every method returns null through shouldIgnoreMissing();
     * the manual-confirm path never calls the gateway, but the deposit lifecycle it depends on
     * must not reach a real adapter.
     */
    private function fakeGateways(): MockInterface
    {
        return $this->mock(Gateways::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
    }

    /** 1. Happy path: the owner confirms a `refund_due` deposit; the reversal books once. */
    public function test_owner_confirms_refund_due_and_books_the_reversal_once(): void
    {
        $this->fakeGateways();
        [$r, $res] = $this->refundDueReservation(['reservation_deposit_refund' => 'full']);
        $owner = User::findOrFail($r->user_id);

        $this->actingAs($owner)
            ->post(route('reservations.deposit.manual-refund', $res->id))
            ->assertRedirect();

        $this->assertSame('refunded', $res->fresh()->deposit_status);
        $this->assertSame(2, $this->refundLedgerRows($res), 'one refund posting = two ledger legs');
        $this->assertEqualsWithDelta(0.0, $this->merchantBalance($r), 0.001);

        $this->assertSame(1, DB::table('activity_logs')
            ->where('action', 'reservation.deposit_refunded')
            ->where('actor_user_id', $owner->id)
            ->count());
    }

    /** 2. Tenant scope: another store's owner cannot confirm the first store's reservation. */
    public function test_another_stores_owner_cannot_confirm_the_first_stores_reservation(): void
    {
        $this->fakeGateways();
        [$r, $res] = $this->refundDueReservation();
        $otherOwner = $this->makeUser();

        $response = $this->actingAs($otherOwner)
            ->post(route('reservations.deposit.manual-refund', $res->id));

        $response->assertNotFound();
        $this->assertSame('refund_due', $res->fresh()->deposit_status);
        $this->assertSame(0, $this->refundLedgerRows($res));
        $this->assertEqualsWithDelta(40.0, $this->merchantBalance($r), 0.001);
    }

    /** 3. State guard: a `paid` (not `refund_due`) deposit is refused and stays paid. */
    public function test_paid_deposit_is_refused_and_stays_paid(): void
    {
        $this->fakeGateways();
        [$r, $res] = $this->paidReservation(['reservation_deposit_refund' => 'full']);
        $owner = User::findOrFail($r->user_id);

        $response = $this->actingAs($owner)
            ->post(route('reservations.deposit.manual-refund', $res->id));

        $response->assertRedirect();
        $response->assertSessionHasErrors('deposit');
        $this->assertSame('paid', $res->fresh()->deposit_status);
        $this->assertSame(0, $this->refundLedgerRows($res));
        $this->assertEqualsWithDelta(40.0, $this->merchantBalance($r), 0.001);
    }

    /** 4. Double click: the repeat is a no-op success; the ledger and activity stay single. */
    public function test_double_post_books_once_and_reports_success(): void
    {
        $this->fakeGateways();
        [$r, $res] = $this->refundDueReservation();
        $owner = User::findOrFail($r->user_id);

        $this->actingAs($owner)
            ->post(route('reservations.deposit.manual-refund', $res->id))
            ->assertRedirect();

        $second = $this->actingAs($owner)
            ->post(route('reservations.deposit.manual-refund', $res->id));

        $second->assertRedirect();
        $second->assertSessionHasNoErrors();
        $this->assertSame('refunded', $res->fresh()->deposit_status);
        $this->assertSame(2, $this->refundLedgerRows($res), 'second click must not book a second posting');
        $this->assertSame(1, DB::table('activity_logs')
            ->where('action', 'reservation.deposit_refunded')
            ->where('actor_user_id', $owner->id)
            ->count());
    }

    /**
     * 5. Auth wall: setUp() strips middleware for the rest of the class; re-enable it and POST
     * as a guest — the route must not reach its 2xx success path and the deposit stays `refund_due`.
     */
    public function test_guest_with_middleware_enabled_cannot_confirm(): void
    {
        [, $res] = $this->refundDueReservation();

        $this->withMiddleware();
        $response = $this->post(route('reservations.deposit.manual-refund', $res->id));

        $status = $response->getStatusCode();
        $this->assertFalse($status >= 200 && $status < 300, "a guest reached the success path (HTTP {$status})");
        $this->assertSame('refund_due', $res->fresh()->deposit_status);
        $this->assertSame(0, $this->refundLedgerRows($res));
    }

    /** 6. The button renders only for a `refund_due` deposit. */
    public function test_button_shows_only_for_refund_due(): void
    {
        [$r, $res] = $this->paidReservation();
        $owner = User::findOrFail($r->user_id);
        $url = route('reservations.deposit.manual-refund', $res->id);

        $this->actingAs($owner)->get(route('reservations.index'))->assertOk()->assertDontSee($url, false);
        $res->forceFill(['deposit_status' => 'refund_due'])->save();
        $this->actingAs($owner)->get(route('reservations.index'))->assertOk()->assertSee($url, false);
    }
}
