<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TwoCheckoutController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — 2Checkout adapter over a faked HTTP client (no network, fake keys).
 */
class TwoCheckoutAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'two_checkout', 'payment_title' => '2Checkout', 'payment_install' => '1']);
        config([
            'settings.checkout_account_number' => '901000001',
            'settings.checkout_public_key' => 'FAKE-PLATFORM-PUB',
            'settings.checkout_private_key' => 'FAKE-PLATFORM-PRIV',
            'settings.2checkout_sandbox_mode' => 'production',
            'settings.currency_code' => 'USD',
        ]);
    }

    private function orderTx(float $amount = 25.0): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('two_checkout', $amount, [], 'USD');
        foreach (['install' => '1', 'sandbox_mode' => 'Yes', 'account_number' => '901000002', 'public_key' => 'FAKE-STORE-PUB', 'private_key' => 'FAKE-STORE-PRIV'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, 'restaurant_2checkout_'.$k, $v);
        }
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        return [$store, $order, $tx];
    }

    private function approved(Transaction $tx, string $total = '25.00'): array
    {
        return ['validationErrors' => null, 'exception' => null, 'response' => [
            'responseCode' => 'APPROVED', 'responseMsg' => 'Successfully authorized the provided credit card',
            'orderNumber' => '9093'.$tx->id, 'merchantOrderId' => (string) $tx->id, 'total' => $total, 'currencyCode' => 'USD',
        ]];
    }

    public function test_initiate_renders_the_card_page_without_the_private_key(): void
    {
        [$store, , $tx] = $this->orderTx();

        TestResponse::fromBaseResponse(TwoCheckoutController::pay($tx))->assertOk()->assertViewIs('owner.billing.gateways.two-checkout')
            ->assertViewHas('checkout_seller_id', '901000002')->assertViewHas('checkout_public_key', 'FAKE-STORE-PUB')
            ->assertViewHas('sandbox', true)->assertViewHas('cancel_url', route('publicView', $store->slug))
            ->assertViewMissing('checkout_private_key')->assertDontSee('FAKE-STORE-PRIV');
    }

    public function test_return_charges_server_side_and_settles_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx();
        Http::fake(['www.2checkout.com/checkout/api/1/901000002/rs/authService' => Http::sequence()
            ->push(['exception' => ['errorMsg' => 'Bad card', 'errorCode' => '607'], 'response' => null], 400)
            ->push($this->approved($tx, '2.50'))
            ->push($this->approved($tx))]);
        $url = route('ipn', 'two_checkout');

        $this->post($url, ['order_id' => $tx->id])->assertRedirect(); // no token
        $this->post($url, ['order_id' => $tx->id, '2checkoutToken' => 'tok'])->assertRedirect();
        $this->assertStringContainsString('Bad card', (string) session('app_alert_message'));
        $this->post($url, ['order_id' => $tx->id, '2checkoutToken' => 'tok'])->assertRedirect(); // approved for less
        $this->assertNotSettled($order, $tx);

        $this->post($url, ['order_id' => $tx->id, '2checkoutToken' => 'tok'])->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame('9093'.$tx->id, Transaction::find($tx->id)->payment_id);
        Http::assertSent(fn (ClientRequest $r) => $r['privateKey'] === 'FAKE-STORE-PRIV' && $r['merchantOrderId'] === (string) $tx->id
            && $r['total'] === '25.00' && $r['currency'] === 'USD' && $r['demo'] === true && $r['token'] === 'tok');

        $this->post($url, ['order_id' => $tx->id, '2checkoutToken' => 'tok'])->assertRedirect(route('subscription'));
        $this->assertSettledOnce($order, $tx);
        Http::assertSentCount(3);
    }

    /**
     * P6-100: a duplicate card-form post (double submit / replayed IPN) racing a confirmation that already settled
     * the transaction must not settle it again — the settlement is a claim on a still-pending row.
     */
    public function test_duplicate_return_racing_a_settled_payment_settles_once(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [, $order, $tx] = $this->orderTx();
        Http::fake(['www.2checkout.com/checkout/api/1/901000002/rs/authService' => function () use ($tx) {
            // The other copy of the same payment wins while this one waits on 2Checkout.
            Settlement::settle(Transaction::find($tx->id), 'two_checkout', 'winner', claim: true);

            return Http::response($this->approved($tx));
        }]);

        $this->post(route('ipn', 'two_checkout'), ['order_id' => $tx->id, '2checkoutToken' => 'tok-dup'])->assertRedirect();

        $this->assertSettledOnce($order, $tx);
        $this->assertSame('winner', Transaction::find($tx->id)->payment_id);
        $this->assertSame(1, DB::table('outbox_messages')->where('dedupe_key', Settlement::CREDIT.':'.$tx->id)->count());
    }

    public function test_webhook_is_acknowledged_instead_of_a_500(): void
    {
        $this->post(route('webhook', 'two_checkout'), ['sale_id' => '1'])->assertOk();
        $this->assertFalse(TwoCheckoutController::adapter()->supportsRefund());
    }
}
