<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\IyzicoController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MidtransController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\MollieController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaypalController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaystackController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaytabsController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaytmController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PayumoneyController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\RazorpayController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\GatewayAdapter;
use Juwri\Modules\Payments\Services\Gateways\StripeAdapter;
use Juwri\Modules\Payments\Services\Refunds\Drivers\AdapterRefunder;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;
use Juwri\Modules\Payments\Services\Refunds\RefundGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\PricedOrderLine;
use Tests\TestCase;

/**
 * Bug batch 6 (owner rules 2026-10-01: never refund a number in another currency; exact amounts) — every gateway
 * refund goes through RefundGuard: the transaction's own currency only, never more than captured minus earlier
 * refunds (tracked in minor units on the transaction), exact at the gateway's decimals; refused before any provider
 * call otherwise.
 *
 * Characterised first (same file, before the fix): an over-refund (20 of 15 USD) was sent as 2000; two 10 USD
 * refunds of a 15 USD payment were both sent; an unsettled payment was refunded; 5.005 USD was sent as 501 and
 * 1.234 USD as 123; KWD 1.234 on Stripe as 1230; an order refund of 12.5 in the order's USD against a KWD payment as
 * 12500 (KWD 12.500); PayTabs sent 25.00 of a 10 AED payment. All of these are refused now.
 */
class GatewayRefundGuardTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @var list<PromiseInterface> Stripe refund answers to give first (then success). */
    private array $stripeQueue = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        PaymentGateway::create(['payment_folder' => 'paytabs', 'payment_title' => 'PayTabs', 'payment_install' => '1']);
        config([
            'settings.stripe_secret_key' => 'sk_test_fake_platform',
            'settings.currency_code' => 'USD',
            'settings.paytabs_profile_id' => '1001',
            'settings.paytabs_secret_key' => 'SFAKE-PLATFORM',
            'settings.paytabs_region' => 'ARE',
        ]);
        Http::fake([
            'api.stripe.com/v1/refunds' => function () {
                $next = array_shift($this->stripeQueue);
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next ?? Http::response(['id' => 're_fake', 'status' => 'succeeded']);
            },
            'secure.paytabs.com/payment/request' => Http::response(['tran_ref' => 'TSTR', 'payment_result' => ['response_status' => 'A']]),
        ]);
    }

    private function stripeTx(float $amount, string $currency = 'USD', ?string $status = Transaction::STATUS_SUCCESS): Transaction
    {
        [, $tx] = $this->makePlanTransaction($amount, $currency, 'wallet_topup');
        $tx->update(['payment_id' => 'pi_fake_'.$tx->id, 'status' => $status, 'transaction_gatway' => 'stripe']);

        return $tx->fresh();
    }

    /** @return list<int> the Stripe refund amounts sent */
    private function stripeAmounts(): array
    {
        return Http::recorded(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/refunds'))
            ->map(fn (array $pair) => (int) $pair[0]['amount'])->values()->all();
    }

    private function refused(array $result): void
    {
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['refused'] ?? false);
        $this->assertNull($result['reference']);
        $this->assertNotSame('', (string) $result['message']);
    }

    /** @return list<array<string, mixed>> */
    private function ledger(Transaction $tx): array
    {
        return json_decode((string) json_encode($tx->fresh()->details->gateway_refunds ?? []), true);
    }

    public function test_more_than_the_captured_amount_is_refused_before_the_provider(): void
    {
        $tx = $this->stripeTx(15.0);

        $result = StripeController::adapter()->refund($tx, 20.0);

        $this->refused($result);
        $this->assertStringContainsString('15.00 USD', $result['message']);
        $this->assertSame([], $this->stripeAmounts());
        $this->assertSame(0, RefundGuard::refundedMinor($tx->fresh()));
    }

    public function test_partial_refunds_sum_exactly_to_the_capture_and_no_further(): void
    {
        $tx = $this->stripeTx(15.0);
        $adapter = StripeController::adapter();

        $this->assertSame(['ok' => true, 'reference' => 're_fake', 'message' => null], $adapter->refund($tx, 10.0));
        $this->refused($adapter->refund($tx->fresh(), 10.0));
        $this->assertTrue($adapter->refund($tx->fresh(), 4.99)['ok']);
        $this->assertTrue($adapter->refund($tx->fresh(), 0.01)['ok']);
        $this->refused($adapter->refund($tx->fresh(), 0.01));

        $this->assertSame([1000, 499, 1], $this->stripeAmounts());
        $this->assertSame(1500, RefundGuard::refundedMinor($tx->fresh()));
        $this->assertSame(1500, (int) $tx->fresh()->details->refunded_minor);
        $ledger = $this->ledger($tx);
        $this->assertCount(3, $ledger);
        $this->assertSame(['ok', 'ok', 'ok'], array_column($ledger, 'status'));
        $this->assertSame(['USD', 'USD', 'USD'], array_column($ledger, 'currency'));
        $this->assertSame([1000, 499, 1], array_column($ledger, 'amount_minor'));
        $this->assertSame('re_fake', $ledger[0]['reference']);
    }

    public function test_an_unsettled_payment_is_not_refunded(): void
    {
        $this->refused(StripeController::adapter()->refund($this->stripeTx(15.0, 'USD', null), 5.0));
        $this->assertSame([], $this->stripeAmounts());
    }

    public function test_amounts_finer_than_the_currency_are_refused_not_rounded(): void
    {
        $tx = $this->stripeTx(15.0);

        $this->refused(StripeController::adapter()->refund($tx, 5.005));
        $this->refused(StripeController::adapter()->refund($tx->fresh(), 1.234));
        $this->refused(StripeController::adapter()->refund($tx->fresh(), 0.0));
        $this->assertSame([], $this->stripeAmounts());
    }

    public function test_three_decimal_currency_on_stripe_refunds_whole_ten_fils_only(): void
    {
        $tx = $this->stripeTx(5.0, 'KWD');

        $this->refused(StripeController::adapter()->refund($tx, 1.234));
        $this->assertTrue(StripeController::adapter()->refund($tx->fresh(), 1.23)['ok']);

        $this->assertSame([1230], $this->stripeAmounts());
        $this->assertSame(1230, RefundGuard::refundedMinor($tx->fresh()));
    }

    public function test_a_moved_platform_currency_still_refunds_in_the_transactions_currency(): void
    {
        $tx = $this->stripeTx(5.0, 'KWD');
        config(['settings.currency_code' => 'USD']);

        $this->assertTrue(StripeController::adapter()->refund($tx, 5.0)['ok']);
        $this->refused(StripeController::adapter()->refund($tx->fresh(), 0.01));
        $this->assertSame([5000], $this->stripeAmounts());
    }

    public function test_a_number_in_another_currency_is_refused(): void
    {
        $tx = $this->stripeTx(15.0);

        $result = StripeController::adapter()->refund($tx, 5.0, 'EUR');

        $this->refused($result);
        $this->assertStringContainsString('EUR', $result['message']);
        $this->assertSame([], $this->stripeAmounts());
        $this->assertTrue(StripeController::adapter()->refund($tx->fresh(), 5.0, 'usd')['ok']);
    }

    public function test_an_order_refund_in_another_currency_than_the_payment_is_refused(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 30.0, ['secret_key' => 'sk_test_fake_store'], 'KWD');
        $order->forceFill(['currency_code' => 'USD'])->save();
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => 'stripe', 'payment_id' => 'pi_fake_o'])->save();

        $this->refused((new AdapterRefunder(app(StripeAdapter::class)))->refund($order->fresh(), 12.5));
        $this->assertSame([], $this->stripeAmounts());

        $order->forceFill(['currency_code' => 'KWD'])->save();
        $this->assertTrue((new AdapterRefunder(app(StripeAdapter::class)))->refund($order->fresh(), 12.5)['ok']);
        $this->assertSame([12500], $this->stripeAmounts());
    }

    public function test_a_provider_refusal_releases_the_reservation(): void
    {
        $tx = $this->stripeTx(15.0);
        $this->stripeQueue = [
            Http::response(['error' => ['message' => 'Card issuer declined']], 402),
            Http::response(['id' => 're_after', 'status' => 'succeeded']),
        ];

        $failed = StripeController::adapter()->refund($tx, 15.0);
        $this->assertFalse($failed['ok']);
        $this->assertArrayNotHasKey('refused', $failed);
        $this->assertSame(0, RefundGuard::refundedMinor($tx->fresh()));
        $this->assertSame(['failed'], array_column($this->ledger($tx), 'status'));

        $this->assertSame(['ok' => true, 'reference' => 're_after', 'message' => null], StripeController::adapter()->refund($tx->fresh(), 15.0));
        $this->assertSame(1500, RefundGuard::refundedMinor($tx->fresh()));
    }

    public function test_an_unknown_provider_outcome_keeps_the_reservation(): void
    {
        $tx = $this->stripeTx(15.0);
        $this->stripeQueue = [new ConnectionException('cURL error 28: Operation timed out')];

        $unknown = StripeController::adapter()->refund($tx, 10.0);

        $this->assertFalse($unknown['ok']);
        $this->assertArrayNotHasKey('refused', $unknown);
        $this->assertSame((string) ___('The payment provider did not confirm this refund. Check your payment provider dashboard before trying again.'), $unknown['message']);
        $ledger = $this->ledger($tx);
        $this->assertSame(['pending'], array_column($ledger, 'status'));
        $this->assertSame(1000, RefundGuard::refundedMinor($tx->fresh()), 'still counted against the cap');

        // Refunding what would remain without the pending entry (15.00) is refused; only 5.00 is left.
        $this->refused(StripeController::adapter()->refund($tx->fresh(), 15.0));
        $this->refused(StripeController::adapter()->refund($tx->fresh(), 5.01));
        $this->assertTrue(StripeController::adapter()->refund($tx->fresh(), 5.0)['ok']);
        $this->assertSame(['pending', 'ok'], array_column($this->ledger($tx), 'status'));
    }

    public function test_the_guard_releases_only_on_an_explicit_refusal(): void
    {
        $tx = $this->stripeTx(15.0);

        $refusal = RefundGuard::run($tx, 15.0, null, 'stripe', 2, fn () => ['ok' => false, 'reference' => null, 'message' => 'declined']);
        $this->assertSame(['ok' => false, 'reference' => null, 'message' => 'declined'], $refusal);
        $this->assertSame(0, RefundGuard::refundedMinor($tx->fresh()));

        $thrown = RefundGuard::run($tx->fresh(), 15.0, null, 'stripe', 2, fn () => throw new \RuntimeException('socket closed'));
        $this->assertFalse($thrown['ok']);
        $this->assertSame(1500, RefundGuard::refundedMinor($tx->fresh()));
        $this->assertSame(['failed', 'pending'], array_column($this->ledger($tx), 'status'));

        $retry = RefundGuard::run($tx->fresh(), 15.0, null, 'stripe', 2, fn () => ['ok' => true, 'reference' => 're_x', 'message' => null]);
        $this->assertTrue($retry['refused'] ?? false, 'a retry while the outcome is unknown cannot refund twice');
    }

    public function test_a_legacy_auto_refund_counts_against_the_cap(): void
    {
        $tx = $this->stripeTx(15.0);
        $tx->forceFill(['details' => ['auto_refund' => ['reference' => 're_old', 'amount' => 10.0, 'at' => now()->toIso8601String()]]])->save();

        $this->refused(StripeController::adapter()->refund($tx->fresh(), 6.0));
        $this->assertTrue(StripeController::adapter()->refund($tx->fresh(), 5.0)['ok']);
        $this->assertSame([500], $this->stripeAmounts());
    }

    public function test_a_platform_auto_refund_is_ledgered_once(): void
    {
        config(['settings.auto_refunds_enabled' => '1']);
        $tx = $this->stripeTx(30.0);

        $this->assertTrue(GatewayRefundService::refundTransaction($tx, 12.0)['automatic']);
        $fresh = $tx->fresh();
        $this->assertTrue($fresh->details->auto_refund->ledgered);
        $this->assertSame(1200, RefundGuard::refundedMinor($fresh));

        $over = GatewayRefundService::refundTransaction($this->stripeTx(10.0), 11.0);
        $this->assertFalse($over['automatic']);
        $this->assertTrue($over['refused']);
    }

    public function test_paytabs_refunds_are_capped_and_sent_in_the_transactions_currency(): void
    {
        [, $tx] = $this->makePlanTransaction(10.0, 'AED', 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'TST7', 'transaction_gatway' => 'paytabs']);

        $this->refused(PaytabsController::adapter()->refund($tx->fresh(), 25.0));
        $this->assertTrue(PaytabsController::adapter()->refund($tx->fresh(), 4.5)['ok']);
        $this->assertTrue(PaytabsController::adapter()->refund($tx->fresh(), 5.5)['ok']);
        $this->refused(PaytabsController::adapter()->refund($tx->fresh(), 0.01));

        $sent = Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), 'paytabs'))->map(fn (array $p) => [$p[0]['cart_amount'], $p[0]['cart_currency']])->values()->all();
        $this->assertSame([['4.50', 'AED'], ['5.50', 'AED']], $sent);
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function refundingAdapters(): array
    {
        return [
            'stripe' => [StripeController::class, 'USD'],
            'paystack' => [PaystackController::class, 'NGN'],
            'razorpay' => [RazorpayController::class, 'INR'],
            'payu' => [PayumoneyController::class, 'PLN'],
            'paytabs' => [PaytabsController::class, 'AED'],
            'mollie' => [MollieController::class, 'EUR'],
            'paypal' => [PaypalController::class, 'USD'],
            'iyzico' => [IyzicoController::class, 'TRY'],
            'paytm' => [PaytmController::class, 'INR'],
            'midtrans' => [MidtransController::class, 'IDR'],
        ];
    }

    /** @param  class-string  $controller */
    #[DataProvider('refundingAdapters')]
    public function test_every_refunding_adapter_is_guarded(string $controller, string $currency): void
    {
        Http::fake();
        /** @var GatewayAdapter $adapter */
        $adapter = $controller::adapter();
        [, $tx] = $this->makePlanTransaction(100.0, $currency, 'wallet_topup');
        $tx->update(['status' => Transaction::STATUS_SUCCESS, 'payment_id' => 'PAY-'.$tx->id, 'transaction_gatway' => $adapter->key()]);

        $this->refused($adapter->refund($tx->fresh(), 100.01));
        $this->refused($adapter->refund($tx->fresh(), 10.0, $currency === 'USD' ? 'EUR' : 'USD'));
        $this->refused($adapter->refund($tx->fresh(), 10.001));
        $pending = $this->makePlanTransaction(100.0, $currency, 'wallet_topup')[1];
        $this->refused($adapter->refund($pending->fresh(), 10.0));

        Http::assertNothingSent();
    }

    public function test_the_owner_refund_endpoint_refuses_without_recording(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 30.0, ['secret_key' => 'sk_test_fake_store'], 'USD');
        $store = Store::find($order->store_id);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        $order->forceFill(['is_paid' => 1, 'payment_gateway' => 'stripe', 'grand_total' => 30, 'subtotal' => 30, 'status' => 'completed', 'currency_code' => 'USD'])->save();
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => 'stripe', 'payment_id' => 'pi_fake_e'])->save();
        $order = PricedOrderLine::add($order, 30.0);
        $owner = $store->user;

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 40])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => '10.005'])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertNull($order->fresh()->refund_method);
        $this->assertSame([], $this->stripeAmounts());

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => '12.50'])
            ->assertOk()->assertJson(['success' => true, 'automatic' => true]);
        $this->assertSame([1250], $this->stripeAmounts());
        $this->assertSame('original_payment', $order->fresh()->refund_method);
    }

    public function test_the_owner_refund_endpoint_uses_the_orders_currency_after_the_store_moved(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 5.0, ['secret_key' => 'sk_test_fake_store'], 'KWD');
        $store = Store::find($order->store_id);
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        $order->forceFill(['is_paid' => 1, 'payment_gateway' => 'stripe', 'status' => 'completed', 'currency_code' => 'KWD'])->save();
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => 'stripe', 'payment_id' => 'pi_fake_k'])->save();
        $order = PricedOrderLine::add($order, 5.0);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        // KWD has 3 decimals: 1.25 is kept exactly (not re-rounded to the store's new USD decimals) and sent as fils.
        $this->actingAs($store->user)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => '1.25'])
            ->assertOk()->assertJson(['automatic' => true]);
        $this->assertSame([1250], $this->stripeAmounts());
    }
}
