<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\CateringRequest;
use App\Models\StoreOption;
use App\Services\Catering\CateringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Juwri\Modules\Payments\Support\Fulfilment;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B8 catering-deposit CAPTURE live path. Before this the deposit had no live caller and
 * no CheckoutController capture branch — a configured catering deposit was never taken.
 * These lock down: accept() arms an async pay-link, the customer pays it, and gateway
 * confirmation captures the deposit to the merchant ledger.
 */
class CateringDepositCaptureTest extends TestCase
{
    use RefreshDatabase;

    private CateringService $svc;

    private Store $r;

    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_catering' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();

        $this->svc = new CateringService;
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($this->r->id, 'catering_enabled', '1');
        StoreOption::updateStoreOption($this->r->id, 'currency_code', 'USD');
        $this->menu = Menu::create(['store_id' => $this->r->id, 'name' => 'Platter', 'price' => 50, 'active' => 1]);

        $this->withoutMiddleware();
    }

    private function enableDeposit(float $amount = 100): void
    {
        StoreOption::updateStoreOption($this->r->id, 'catering_deposit_enabled', '1');
        StoreOption::updateStoreOption($this->r->id, 'catering_deposit_amount', $amount);
    }

    /** P1-23: the store's OWN gateway (a deposit is paid to the store, never to the platform). */
    private function storeStripe(): void
    {
        StoreOption::updateStoreOption($this->r->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($this->r->id, 'restaurant_stripe_title', 'Card (store Stripe)');
        StoreOption::updateStoreOption($this->r->id, 'restaurant_stripe_secret_key', 'sk_test_store_own');
    }

    private function request(): CateringRequest
    {
        return $this->svc->createRequest($this->r->id, [
            'name' => 'Sam', 'phone' => '123', 'event_at' => now()->addDays(30)->toDateTimeString(), 'headcount' => 20,
        ]);
    }

    /** An accepted request with the deposit armed (token + pending), no order needed. */
    private function armedRequest(): CateringRequest
    {
        $this->enableDeposit(100);
        $req = $this->request();

        return $this->svc->armDeposit($req)->fresh();
    }

    public function test_accept_arms_the_deposit_when_enabled(): void
    {
        Bus::fake();
        $this->enableDeposit(100);
        $req = $this->request();

        $this->svc->accept($req, [['item_id' => $this->menu->id, 'quantity' => 4]]);

        $req->refresh();
        $this->assertSame('pending', $req->deposit_status, 'accept must arm the deposit');
        $this->assertEqualsWithDelta(100.0, (float) $req->deposit_amount, 0.001);
        $this->assertNotEmpty($req->token, 'a capability token must be stamped for the pay link');
    }

    public function test_accept_does_not_arm_a_deposit_when_disabled(): void
    {
        Bus::fake();
        $req = $this->request(); // deposit options not set

        $this->svc->accept($req, [['item_id' => $this->menu->id, 'quantity' => 4]]);

        $req->refresh();
        $this->assertNull($req->deposit_status);
        $this->assertNull($req->token);
    }

    public function test_deposit_pay_page_renders_amount_and_gateways(): void
    {
        $req = $this->armedRequest();
        $this->storeStripe();
        PaymentGateway::create(['payment_folder' => 'wire_transfer', 'payment_title' => 'Wire Transfer', 'payment_install' => '1']);

        $this->get(route('publicView.catering.deposit', ['slug' => $this->r->slug, 'token' => $req->token]))->assertOk()->assertSeeHtml('100.00')
            ->assertSeeHtml('Card (store Stripe)')->assertDontSeeHtml('Wire Transfer'); // P1-23: never a platform gateway
    }

    public function test_deposit_charge_rejects_an_uninstalled_gateway(): void
    {
        $req = $this->armedRequest();

        $this->post(route('publicView.catering.deposit.charge', ['slug' => $this->r->slug, 'token' => $req->token]), [
            'payment_method' => 999999,
        ])->assertRedirect();

        $this->assertSame(0, Transaction::where('transaction_method', 'catering_deposit')->count());
    }

    public function test_deposit_charge_opens_a_transaction_and_links_it(): void
    {
        $req = $this->armedRequest();
        $this->storeStripe();
        config(['settings.stripe_secret_key' => 'sk_test_PLATFORM']);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_store_1', 'url' => 'https://checkout.stripe.test/cs_store_1', 'payment_status' => 'unpaid', 'status' => 'open'])]);
        $platform = PaymentGateway::create(['payment_folder' => 'wire_transfer', 'payment_title' => 'Wire Transfer', 'payment_install' => '1']);
        $this->post(route('publicView.catering.deposit.charge', ['slug' => $this->r->slug, 'token' => $req->token]), ['payment_method' => $platform->id])
            ->assertRedirect();
        $this->assertSame(0, Transaction::where('transaction_method', 'catering_deposit')->count(), 'a platform gateway is refused');

        $this->post(route('publicView.catering.deposit.charge', ['slug' => $this->r->slug, 'token' => $req->token]), [
            'payment_method' => 'stripe',
        ])->assertRedirect('https://checkout.stripe.test/cs_store_1');
        Http::assertSent(fn (ClientRequest $q) => $q->hasHeader('Authorization', 'Bearer sk_test_store_own'));
        Http::assertNotSent(fn (ClientRequest $q) => $q->hasHeader('Authorization', 'Bearer sk_test_PLATFORM'));

        $req->refresh();
        $this->assertSame('pending', $req->deposit_status);
        $this->assertNotNull($req->deposit_transaction_id);
        $txn = Transaction::find($req->deposit_transaction_id);
        $this->assertNotNull($txn);
        $this->assertSame('catering_deposit', $txn->transaction_method);
        $this->assertSame((int) $this->r->id, (int) $txn->store_id);
        $this->assertEqualsWithDelta(100.0, (float) $txn->amount, 0.001);
    }

    public function test_gateway_confirmation_captures_the_deposit_to_the_ledger(): void
    {
        $req = $this->armedRequest();
        $txn = $this->svc->initiateDeposit($req, 'stripe'); // what depositCharge does
        $this->assertNotNull($txn);

        // Shared confirmation choke point (gateway success) — the new catering_deposit branch.
        Fulfilment::fulfil($txn->fresh());

        $this->assertSame('paid', $req->fresh()->deposit_status);
        $merchant = LedgerService::accountFor('merchant', (int) $this->r->user_id, 'merchant', 'USD');
        $this->assertEqualsWithDelta(100.0, LedgerService::balanceFor($merchant), 0.001);
    }
}
