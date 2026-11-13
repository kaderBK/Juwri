<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MollieController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use Mollie\Api\Exceptions\RetryableNetworkRequestException;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreatePaymentRefundRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Laravel\Facades\Mollie;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06b — Mollie adapter checks beyond MollieGatewayTest (the contract kept
 * from the old controller): amount check, still-open return, refund.
 */
class MollieAdapterTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'test_fakeMollieStoreKey0000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        PaymentGateway::create(['payment_folder' => 'mollie', 'payment_title' => 'Mollie', 'payment_install' => '1']);
    }

    private function payment(string $status, string $value = '100.00'): MockResponse
    {
        return MockResponse::ok(['resource' => 'payment', 'id' => 'tr_TEST9', 'status' => $status,
            'amount' => ['currency' => 'EUR', 'value' => $value],
            '_links' => ['checkout' => ['href' => 'https://pay.mollie.test/x', 'type' => 'text/html']]]);
    }

    public function test_a_paid_payment_for_another_amount_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('mollie', 100.0, ['api_key' => self::STORE_KEY]);
        $tx->update(['payment_id' => 'tr_TEST9']);
        Mollie::fake([GetPaymentRequest::class => $this->payment('paid', '1.00')]);

        $this->post(route('webhook', 'mollie'), ['id' => 'tr_TEST9'])->assertOk();
        $this->assertNotSettled($order, $tx);
        $this->assertSame('not_paid:paid', PlatformSettings::stored('mollie_webhook_last_status'));
    }

    public function test_a_still_open_return_says_processing_not_failed(): void
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('mollie', 100.0, ['api_key' => self::STORE_KEY]);
        $tx->update(['payment_id' => 'tr_TEST9']);
        Mollie::fake([GetPaymentRequest::class => $this->payment('open')]);

        $this->get(route('ipn', 'mollie').'?order_id='.$tx->id)->assertRedirect(route('publicView', $store->slug).'?return=success');
        $this->assertNotSettled($order, $tx);
    }

    public function test_refund(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'EUR', 'wallet_topup');
        config(['settings.mollie_api_key' => self::STORE_KEY, 'settings.currency_code' => 'EUR']);
        $tx->update(['payment_id' => 'tr_TEST9', 'status' => Transaction::STATUS_SUCCESS]);
        Mollie::fake([CreatePaymentRefundRequest::class => MockResponse::created(['resource' => 'refund', 'id' => 're_TEST1', 'status' => 'pending',
            'amount' => ['currency' => 'EUR', 'value' => '40.00'], 'paymentId' => 'tr_TEST9'])]);

        $this->assertSame(['ok' => true, 'reference' => 're_TEST1', 'message' => null], MollieController::adapter()->refund($tx->fresh(), 40.0));
    }

    /** Bug batch 8 item 4: a network failure (no answer) is UNKNOWN — the reservation stays pending, no double refund. */
    public function test_a_refund_timeout_keeps_the_reservation_pending(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'EUR', 'wallet_topup');
        config(['settings.mollie_api_key' => self::STORE_KEY, 'settings.currency_code' => 'EUR']);
        $tx->update(['payment_id' => 'tr_TEST9', 'status' => Transaction::STATUS_SUCCESS]);
        Mollie::fake([CreatePaymentRefundRequest::class => fn (PendingRequest $r) => throw new RetryableNetworkRequestException($r, 'cURL error 28: Operation timed out')]);
        Mollie::api()->setRetryStrategy(new LinearRetryStrategy(0, 0)); // the SDK's own retries exhausted: one fake answer

        $result = MollieController::adapter()->refund($tx->fresh(), 40.0);

        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('refused', $result);
        $this->assertSame((string) ___('The payment provider did not confirm this refund. Check your payment provider dashboard before trying again.'), $result['message']);
        $this->assertSame(4000, RefundGuard::refundedMinor($tx->fresh()), 'still counted against the cap');

        // Only 60.00 is left: refunding the full 100.00 again is refused before any provider call.
        $again = MollieController::adapter()->refund($tx->fresh(), 100.0);
        $this->assertTrue($again['refused'] ?? false);
    }

    /** P6-80: a retry after a timeout sends the same Idempotency-Key (the ledger entry id); one refund recorded. */
    public function test_a_refund_retry_reuses_the_idempotency_key(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'EUR', 'wallet_topup');
        config(['settings.mollie_api_key' => self::STORE_KEY, 'settings.currency_code' => 'EUR']);
        $tx->update(['payment_id' => 'tr_TEST9', 'status' => Transaction::STATUS_SUCCESS]);
        $keys = [];
        Mollie::fake([CreatePaymentRefundRequest::class => function (PendingRequest $r) use (&$keys) {
            $keys[] = $r->headers()->get('Idempotency-Key');
            throw new RetryableNetworkRequestException($r, 'cURL error 28: Operation timed out');
        }]);
        Mollie::api()->setRetryStrategy(new LinearRetryStrategy(0, 0));

        $this->assertFalse(MollieController::adapter()->refund($tx->fresh(), 40.0)['ok']);
        $entryId = $tx->fresh()->details->gateway_refunds[0]->id;
        Mollie::fake([CreatePaymentRefundRequest::class => function (PendingRequest $r) use (&$keys) {
            $keys[] = $r->headers()->get('Idempotency-Key');

            return MockResponse::created(['resource' => 'refund', 'id' => 're_TEST1', 'status' => 'pending',
                'amount' => ['currency' => 'EUR', 'value' => '40.00'], 'paymentId' => 'tr_TEST9']);
        }]);
        $retry = MollieController::adapter()->refund($tx->fresh(), 40.0);
        $this->assertTrue($retry['ok'], (string) json_encode([$retry, $keys]));

        $this->assertSame(['refund-'.$entryId, 'refund-'.$entryId], $keys);
        $this->assertCount(1, (array) $tx->fresh()->details->gateway_refunds);
        $this->assertSame(4000, RefundGuard::refundedMinor($tx->fresh()));
    }

    /** An explicit API error (Mollie answered 422) stays a refusal and releases the reservation. */
    public function test_an_api_error_is_a_refusal_that_releases_the_reservation(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'EUR', 'wallet_topup');
        config(['settings.mollie_api_key' => self::STORE_KEY, 'settings.currency_code' => 'EUR']);
        $tx->update(['payment_id' => 'tr_TEST9', 'status' => Transaction::STATUS_SUCCESS]);
        Mollie::fake([CreatePaymentRefundRequest::class => MockResponse::unprocessableEntity('The amount is higher than the refundable amount', 'amount')]);

        $result = MollieController::adapter()->refund($tx->fresh(), 40.0);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('refundable amount', (string) $result['message']);
        $this->assertSame(0, RefundGuard::refundedMinor($tx->fresh()));
    }
}
