<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\ReservationDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Support\Fulfilment;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T5 reservation-deposit CAPTURE live path. Before this the deposit charge had no live
 * caller — publicBook created a pending reservation and never took the configured
 * deposit. These lock down that a booking which requires a deposit now routes the diner
 * to the deposit step, opens a charge, and confirms on gateway success.
 */
class ReservationDepositCaptureTest extends TestCase
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
            'enable_reservations' => '1',
            'reservation_deposit_enabled' => '1', 'reservation_deposit_amount' => 10,
            'reservation_deposit_per' => 'guest', 'reservation_deposit_min_party' => 4,
            'reservation_deposit_refund' => 'full', 'currency_code' => 'USD',
        ], $opts) as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        return $r;
    }

    /** P1-23: the store's OWN gateway (a deposit is paid to the store, never to the platform). */
    private function storeStripe(Store $r): void
    {
        StoreOption::updateStoreOption($r->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($r->id, 'restaurant_stripe_title', 'Card (store Stripe)');
        StoreOption::updateStoreOption($r->id, 'restaurant_stripe_secret_key', 'sk_test_store_own');
    }

    private function pendingReservation(Store $r, int $party = 4): Reservation
    {
        return Reservation::create([
            'store_id' => $r->id, 'name' => 'Guest', 'party_size' => $party,
            'reserved_at' => now()->addDay(), 'status' => 'pending', 'token' => Str::random(40),
        ]);
    }

    public function test_booking_requiring_a_deposit_redirects_to_the_deposit_page(): void
    {
        $r = $this->storeFixture();

        $resp = $this->post(route('publicView.reservation.book', $r->slug), [
            'name' => 'Guest', 'party_size' => 4, 'reserved_at' => now()->addDay()->toDateTimeString(),
        ]);

        $res = Reservation::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotNull($res);
        $resp->assertRedirect(route('publicView.reservation.deposit', ['slug' => $r->slug, 'token' => $res->token]));
        // Not charged yet — the deposit is taken on the next step.
        $this->assertSame('none', $res->deposit_status);
        $this->assertSame('pending', $res->status);
    }

    public function test_booking_below_min_party_does_not_require_a_deposit(): void
    {
        $r = $this->storeFixture(); // min party 4

        $resp = $this->post(route('publicView.reservation.book', $r->slug), [
            'name' => 'Guest', 'party_size' => 2, 'reserved_at' => now()->addDay()->toDateTimeString(),
        ]);

        $res = Reservation::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotNull($res);
        $this->assertStringNotContainsString('/reservation/deposit/', (string) $resp->headers->get('Location'));
        $this->assertSame('none', $res->deposit_status);
    }

    public function test_deposit_pay_page_renders_amount_and_gateways(): void
    {
        $r = $this->storeFixture();
        $this->storeStripe($r);
        // A gateway the PLATFORM installed is never offered: the diner would pay the platform (P1-23).
        PaymentGateway::create(['payment_folder' => 'wire_transfer', 'payment_title' => 'Wire Transfer', 'payment_install' => '1']);
        $res = $this->pendingReservation($r, 4); // 4 × 10 = 40

        $this->get(route('publicView.reservation.deposit', ['slug' => $r->slug, 'token' => $res->token]))->assertOk()->assertSeeHtml('40.00')
            ->assertSeeHtml('Card (store Stripe)')->assertDontSeeHtml('Wire Transfer');
    }

    public function test_deposit_charge_rejects_an_uninstalled_gateway(): void
    {
        $r = $this->storeFixture();
        $res = $this->pendingReservation($r, 4);
        $platform = PaymentGateway::create(['payment_folder' => 'wire_transfer', 'payment_title' => 'Wire Transfer', 'payment_install' => '1']);

        $this->post(route('publicView.reservation.deposit.charge', ['slug' => $r->slug, 'token' => $res->token]), [
            'payment_method' => 999999, // no such gateway
        ])->assertRedirect();
        foreach ([$platform->id, 'wire_transfer', 'stripe'] as $notTheStores) { // stripe: the store has not switched it on
            $this->post(route('publicView.reservation.deposit.charge', ['slug' => $r->slug, 'token' => $res->token]), ['payment_method' => $notTheStores])
                ->assertRedirect();
        }

        $this->assertSame('none', $res->fresh()->deposit_status);
        $this->assertSame(0, Transaction::where('transaction_method', 'reservation_deposit')->count());
    }

    public function test_deposit_charge_opens_a_transaction_and_links_it(): void
    {
        $r = $this->storeFixture();
        $this->storeStripe($r);
        config(['settings.stripe_secret_key' => 'sk_test_PLATFORM']);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_store_1', 'url' => 'https://checkout.stripe.test/cs_store_1', 'payment_status' => 'unpaid', 'status' => 'open'])]);
        $res = $this->pendingReservation($r, 4);

        $this->post(route('publicView.reservation.deposit.charge', ['slug' => $r->slug, 'token' => $res->token]), [
            'payment_method' => 'stripe',
        ])->assertRedirect('https://checkout.stripe.test/cs_store_1');

        // P1-23: charged with the STORE's key, never the platform's — the deposit goes to the store's account.
        Http::assertSent(fn (ClientRequest $q) => $q->hasHeader('Authorization', 'Bearer sk_test_store_own'));
        Http::assertNotSent(fn (ClientRequest $q) => $q->hasHeader('Authorization', 'Bearer sk_test_PLATFORM'));

        $res->refresh();
        $this->assertSame('pending', $res->deposit_status);
        $this->assertNotNull($res->deposit_transaction_id);
        $txn = Transaction::find($res->deposit_transaction_id);
        $this->assertNotNull($txn);
        $this->assertSame('reservation_deposit', $txn->transaction_method);
        $this->assertSame((int) $r->id, (int) $txn->store_id);
        $this->assertEqualsWithDelta(40.0, (float) $txn->amount, 0.001);
    }

    public function test_gateway_confirmation_captures_the_deposit_and_confirms_the_booking(): void
    {
        $r = $this->storeFixture();
        $res = $this->pendingReservation($r, 4);

        // What depositCharge does, then the shared confirmation choke point (gateway success).
        $txn = ReservationDepositService::initiate($res, 'stripe');
        Fulfilment::fulfil($txn->fresh());

        $res->refresh();
        $this->assertSame('paid', $res->deposit_status);
        $this->assertSame('confirmed', $res->status);

        $merchant = LedgerService::accountFor('merchant', (int) $r->user_id, 'merchant', 'USD');
        $this->assertEqualsWithDelta(40.0, LedgerService::balanceFor($merchant), 0.001);
    }
}
