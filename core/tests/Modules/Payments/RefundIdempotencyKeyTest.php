<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P6-80 — gateway refunds carry the ledger entry id as the provider idempotency key, and a retry after a timeout
 * reuses the SAME key (persisted on the ledger entry), so the provider answers with the first refund instead of
 * making a second one. Before: Stripe `/refunds` and PayPal capture refunds were posted without any key.
 */
class RefundIdempotencyKeyTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @var list<\Throwable|null> per refund call: throw it, or null = answer */
    private array $queue = [];

    /** @var list<array{0: string, 1: string|null}> [url, key] of every refund call — a thrown answer is not in Http::recorded() */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        PaymentGateway::create(['payment_folder' => 'paypal', 'payment_title' => 'PayPal', 'payment_install' => '1']);
        config([
            'settings.stripe_secret_key' => 'sk_test_fake_platform',
            'settings.currency_code' => 'USD',
            'settings.paypal_api_client_id' => 'fake-platform-client',
            'settings.paypal_api_secret' => 'fake-platform-secret',
            'settings.paypal_sandbox_mode' => 'Yes',
        ]);
        // The fake provider dedupes by key like the real one: the same key returns the first refund.
        $seen = [];
        $answer = function (ClientRequest $request, string $header, string $prefix) use (&$seen) {
            $next = array_shift($this->queue);
            $key = $request->header($header)[0] ?? null;
            $this->sent[] = [$request->url(), $key];
            if ($key !== null && ! isset($seen[$key])) {
                $seen[$key] = $prefix.count($seen);
            }
            if ($next instanceof \Throwable) {
                throw $next; // the provider made the refund but the answer was lost
            }

            return Http::response(['id' => $key !== null ? $seen[$key] : $prefix.'nokey', 'status' => 'succeeded']);
        };
        Http::fake([
            'api.stripe.com/v1/refunds' => fn (ClientRequest $r) => $answer($r, 'Idempotency-Key', 're_'),
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/*' => Http::response(['purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1']]]]]]),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP1/refund' => fn (ClientRequest $r) => $answer($r, 'PayPal-Request-Id', 'RF'),
        ]);
    }

    private function tx(string $gateway, float $amount): Transaction
    {
        [, $tx] = $this->makePlanTransaction($amount, 'USD', 'wallet_topup');
        $tx->update(['payment_id' => ($gateway === 'stripe' ? 'pi_fake_' : 'ORDER-').$tx->id, 'status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => $gateway]);

        return $tx->fresh();
    }

    /** @return list<string|null> */
    private function keys(string $path, string $header): array
    {
        return array_values(array_map(fn (array $s) => $s[1], array_filter($this->sent, fn (array $s) => str_ends_with($s[0], $path))));
    }

    /** @return list<array<string, mixed>> */
    private function ledger(Transaction $tx): array
    {
        return json_decode((string) json_encode($tx->fresh()->details->gateway_refunds ?? []), true);
    }

    /** @return array<string, array{0: string, 1: class-string, 2: string, 3: string}> */
    public static function idempotentGateways(): array
    {
        return [
            'stripe' => ['stripe', StripeController::class, '/v1/refunds', 'Idempotency-Key'],
            'paypal' => ['paypal', PaypalController::class, '/captures/CAP1/refund', 'PayPal-Request-Id'],
        ];
    }

    /** @param class-string $controller */
    #[DataProvider('idempotentGateways')]
    public function test_a_retry_after_a_timeout_reuses_the_key_and_records_one_refund(string $gateway, string $controller, string $path, string $header): void
    {
        $tx = $this->tx($gateway, 15.0);
        $this->queue = [new ConnectionException('cURL error 28: Operation timed out'), null];

        $first = $controller::adapter()->refund($tx, 10.0);
        $this->assertFalse($first['ok']);
        $this->assertArrayNotHasKey('refused', $first);
        $entry = $this->ledger($tx)[0];
        $this->assertSame('pending', $entry['status']);

        $retry = $controller::adapter()->refund($tx->fresh(), 10.0);
        $this->assertTrue($retry['ok']);

        $keys = $this->keys($path, $header);
        $this->assertCount(2, $keys);
        $this->assertSame('refund-'.$entry['id'], $keys[0], 'the key is the persisted ledger entry id');
        $this->assertSame($keys[0], $keys[1], 'the retry sends the identical key');
        $ledger = $this->ledger($tx);
        $this->assertCount(1, $ledger, 'one refund recorded, not two');
        $this->assertSame('ok', $ledger[0]['status']);
        $this->assertArrayNotHasKey('unknown', $ledger[0]);
        $this->assertSame(1000, RefundGuard::refundedMinor($tx->fresh()));
        $this->assertSame(1000, (int) $tx->fresh()->details->refunded_minor);
    }

    public function test_partial_refunds_get_distinct_keys_sum_to_the_capture_and_no_further(): void
    {
        $tx = $this->tx('stripe', 15.0);
        $adapter = StripeController::adapter();

        $this->assertTrue($adapter->refund($tx, 10.0)['ok']);
        $this->assertTrue($adapter->refund($tx->fresh(), 5.0)['ok']);
        $over = $adapter->refund($tx->fresh(), 0.01);

        $this->assertTrue($over['refused'] ?? false, 'over-refund refused');
        $keys = $this->keys('/v1/refunds', 'Idempotency-Key');
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
        $this->assertSame(['ok', 'ok'], array_column($this->ledger($tx), 'status'));
        $this->assertSame(1500, RefundGuard::refundedMinor($tx->fresh()));
    }

    public function test_an_over_refund_after_an_unknown_outcome_is_still_refused(): void
    {
        $tx = $this->tx('stripe', 15.0);
        $this->queue = [new ConnectionException('timed out')];

        $this->assertFalse(StripeController::adapter()->refund($tx, 10.0)['ok']);
        // A different amount is a new refund, capped with the unknown one still counted: 10 > 5 left.
        $this->assertTrue(StripeController::adapter()->refund($tx->fresh(), 10.01)['refused'] ?? false);
        $this->assertTrue(StripeController::adapter()->refund($tx->fresh(), 6.0)['refused'] ?? false);

        $this->assertCount(1, $this->keys('/v1/refunds', 'Idempotency-Key'));
        $this->assertSame(1000, RefundGuard::refundedMinor($tx->fresh()));
    }

    public function test_a_gateway_without_idempotency_never_reuses_an_unknown_entry(): void
    {
        $tx = $this->tx('stripe', 15.0);
        RefundGuard::run($tx, 10.0, null, 'paystack', 2, fn () => throw new ConnectionException('timed out'));

        $retry = RefundGuard::run($tx->fresh(), 10.0, null, 'paystack', 2, fn () => ['ok' => true, 'reference' => 'x', 'message' => null]);

        $this->assertTrue($retry['refused'] ?? false, 'a new reservation: 10 + 10 > 15');
        $this->assertSame(['pending'], array_column($this->ledger($tx), 'status'));
    }
}
