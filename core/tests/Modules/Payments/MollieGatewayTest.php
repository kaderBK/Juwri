<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MollieController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Fake\SequenceMockResponse;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-H4 — Mollie was broken end to end (a fatal `payments()` call that no Exception
 * catch could see, and no server-side webhook, so a buyer who closed the tab was
 * charged while the platform recorded nothing). These pin the repaired pay()/ipn()
 * SDK calls and the new per-payment webhook that settles server-side.
 */
class MollieGatewayTest extends TestCase
{
    use GatewayTestHelpers, RefreshDatabase;

    private function withoutInstalled(): self
    {
        $this->withoutMiddleware([Installed::class]);

        return $this;
    }

    private function seedGateway(string $folder): void
    {
        PaymentGateway::create([
            'payment_folder' => $folder, 'payment_title' => ucfirst($folder), 'payment_install' => '1',
        ]);
    }

    private function paymentResource(string $id, string $status): array
    {
        return [
            'resource' => 'payment', 'id' => $id, 'status' => $status,
            'amount' => ['currency' => 'EUR', 'value' => '100.00'],
            '_links' => ['checkout' => ['href' => 'https://pay.mollie.test/x', 'type' => 'text/html']],
        ];
    }

    /** @return mixed the private static webhookUrl() return under the current config */
    private function webhookUrl(): mixed
    {
        $m = new \ReflectionMethod(MollieController::class, 'webhookUrl');
        $m->setAccessible(true);

        return $m->invoke(null);
    }

    public function test_pay_repairs_the_sdk_call_and_redirects_to_checkout(): void
    {
        // membership pay() reads the currency from platform settings, not the transaction.
        config(['settings.mollie_api_key' => 'test_fakeMollieKeyForTests000000000000', 'settings.currency_code' => 'EUR']);
        [, $tx] = $this->makePlanTransaction(100.0, 'EUR', 'membership');

        Mollie::fake([
            CreatePaymentRequest::class => MockResponse::created($this->paymentResource('tr_TEST1', 'open'), 'payment'),
        ]);

        $response = MollieController::pay($tx);

        $this->assertSame('https://pay.mollie.test/x', $response->getTargetUrl());
        $this->assertSame('tr_TEST1', $tx->fresh()->payment_id);
    }

    public function test_webhook_url_is_sent_when_enabled_and_suppressed_by_the_kill_switch(): void
    {
        // Default ON → the derived route is sent (env is `testing`, not `local`).
        config(['settings.mollie_webhook_enabled' => '1', 'settings.mollie_webhook_url' => '']);
        $this->assertSame(route('webhook', 'mollie'), $this->webhookUrl());

        // Kill switch → null, so pay() omits webhookUrl and settles browser-return only.
        config(['settings.mollie_webhook_enabled' => '0']);
        $this->assertNull($this->webhookUrl());

        // Override wins when set.
        config(['settings.mollie_webhook_enabled' => '1', 'settings.mollie_webhook_url' => 'https://tunnel.example/hook']);
        $this->assertSame('https://tunnel.example/hook', $this->webhookUrl());
    }

    public function test_webhook_route_settles_an_order_without_the_browser(): void
    {
        $this->seedGateway('mollie');
        [$store, $order, $tx] = $this->makeOrderTransaction('mollie', 100.0, ['api_key' => 'test_fakeMollieKeyForTests000000000000'], 'EUR'); // the Mollie payment fixtures are EUR
        $tx->update(['payment_id' => 'tr_TEST1']);

        Mollie::fake([
            GetPaymentRequest::class => MockResponse::ok($this->paymentResource('tr_TEST1', 'paid'), 'payment'),
        ]);

        $this->withoutInstalled()
            ->post(route('webhook', 'mollie'), ['id' => 'tr_TEST1'])
            ->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    /** Gateway-currency bug fix: a paid Mollie payment in another currency than the transaction's does not settle. */
    public function test_webhook_does_not_settle_a_payment_in_another_currency(): void
    {
        $this->seedGateway('mollie');
        [$store, $order, $tx] = $this->makeOrderTransaction('mollie', 100.0, ['api_key' => 'test_fakeMollieKeyForTests000000000000'], 'USD');
        $tx->update(['payment_id' => 'tr_TEST1']);

        Mollie::fake([
            GetPaymentRequest::class => MockResponse::ok($this->paymentResource('tr_TEST1', 'paid'), 'payment'), // EUR 100.00
        ]);

        $this->withoutInstalled()->post(route('webhook', 'mollie'), ['id' => 'tr_TEST1'])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_after_settlement_is_a_noop(): void
    {
        $this->seedGateway('mollie');
        [$store, $order, $tx] = $this->makeOrderTransaction('mollie', 100.0, ['api_key' => 'test_fakeMollieKeyForTests000000000000'], 'EUR'); // the Mollie payment fixtures are EUR
        $tx->update(['payment_id' => 'tr_TEST1']);

        Mollie::fake([
            GetPaymentRequest::class => new SequenceMockResponse(
                MockResponse::ok($this->paymentResource('tr_TEST1', 'paid'), 'payment'),
                MockResponse::ok($this->paymentResource('tr_TEST1', 'paid'), 'payment'),
            ),
        ]);

        $this->withoutInstalled()->post(route('webhook', 'mollie'), ['id' => 'tr_TEST1'])->assertOk();
        $this->assertSame(1, (int) $order->fresh()->is_paid);

        // Replay: paySuccess consumed the transaction, so the second call finds nothing and is a 200 no-op.
        $this->withoutInstalled()->post(route('webhook', 'mollie'), ['id' => 'tr_TEST1'])->assertOk();
        $this->assertSame(1, (int) $order->fresh()->is_paid);
    }

    public function test_webhook_does_not_settle_an_unpaid_payment(): void
    {
        $this->seedGateway('mollie');
        [$store, $order, $tx] = $this->makeOrderTransaction('mollie', 100.0, ['api_key' => 'test_fakeMollieKeyForTests000000000000'], 'EUR'); // the Mollie payment fixtures are EUR
        $tx->update(['payment_id' => 'tr_TEST1']);

        Mollie::fake([
            GetPaymentRequest::class => MockResponse::ok($this->paymentResource('tr_TEST1', 'open'), 'payment'),
        ]);

        $this->withoutInstalled()->post(route('webhook', 'mollie'), ['id' => 'tr_TEST1'])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_unknown_payment_id_is_a_200_noop(): void
    {
        $this->seedGateway('mollie');
        Mollie::fake([]); // no request should be attempted

        $this->withoutInstalled()->post(route('webhook', 'mollie'), ['id' => 'tr_NOPE'])->assertOk();
    }

    public function test_browser_return_after_the_webhook_settled_shows_success_not_an_error(): void
    {
        $this->seedGateway('mollie');
        [$store, $order, $tx] = $this->makeOrderTransaction('mollie', 100.0, ['api_key' => 'test_fakeMollieKeyForTests000000000000'], 'EUR'); // the Mollie payment fixtures are EUR
        $tx->update(['payment_id' => 'tr_TEST1']);
        $txId = $tx->id;

        Mollie::fake([
            GetPaymentRequest::class => MockResponse::ok($this->paymentResource('tr_TEST1', 'paid'), 'payment'),
        ]);

        // Webhook settles first — order is paid, the transaction row is consumed by paySuccess.
        $this->withoutInstalled()->post(route('webhook', 'mollie'), ['id' => 'tr_TEST1'])->assertOk();
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($txId)->status); // MC-02: consumed, not deleted

        // Now the buyer's browser returns. It must land on order tracking, NOT the "Invalid transaction" error.
        $this->withoutInstalled()
            ->get(route('ipn', 'mollie').'?order_id='.$txId.'&t='.$order->track_token)
            ->assertRedirect(route('store.orderTrack', $order->track_token));
    }
}
