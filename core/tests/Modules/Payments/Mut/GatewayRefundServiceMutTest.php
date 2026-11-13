<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Mut;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P6-28: mutation killers for GatewayRefundService — store-payment refunds (booking deposits ...), platform refunds,
 * live order drivers and the gateway lookup. Exact amounts/currencies, refusal vs manual, and that a refusal never
 * reaches the provider.
 */
class GatewayRefundServiceMutTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const MANUAL_MY_PAY = 'Recorded. Complete this refund in your My pay dashboard to move the money.';

    private const MANUAL_NONE = 'Recorded. Refund the diner through the original payment method to move the money.';

    private const SEND_FAILED = 'Refund could not be sent to the provider — complete it in your payment provider dashboard.';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        FakeRefundAdapter::reset();
        FakeOrderRefunder::$result = ['ok' => true, 'reference' => 'drv-1'];
        config(['refunds.adapters' => ['my_pay' => FakeRefundAdapter::class], 'refunds.store_opt_in' => [], 'refunds.drivers' => []]);
    }

    /** A settled 30.000 KWD deposit-style store payment (not an order) through my_pay. */
    private function storePayment(string $status = Transaction::STATUS_SUCCESS, ?string $gateway = 'my_pay', bool $optIn = true): Transaction
    {
        [$store, , $tx] = $this->makeOrderTransaction('my_pay', 30.0, [], 'KWD');
        $tx->forceFill(['transaction_method' => 'booking_deposit', 'product_id' => null, 'status' => $status, 'transaction_gatway' => $gateway])->save();
        if ($optIn) {
            StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        }

        return $tx->fresh();
    }

    private function manual(?string $gateway, string $message): array
    {
        return ['automatic' => false, 'gateway' => $gateway, 'reference' => null, 'message' => $message];
    }

    public function test_store_payment_refunds_the_exact_amount_and_currency_through_the_adapter(): void
    {
        $tx = $this->storePayment();
        FakeRefundAdapter::$result = ['ok' => true, 'reference' => 'ref-77'];

        $res = GatewayRefundService::refundStorePayment($tx, 12.345, 'KWD');

        $this->assertSame(['automatic' => true, 'gateway' => 'my_pay', 'reference' => 'ref-77', 'message' => null], $res);
        $this->assertSame([[(int) $tx->id, 12.345, 'KWD']], FakeRefundAdapter::$calls);
    }

    public function test_store_payment_ok_without_reference_gives_null_reference(): void
    {
        FakeRefundAdapter::$result = ['ok' => true];
        $res = GatewayRefundService::refundStorePayment($this->storePayment(), 1.0);
        $this->assertSame(['automatic' => true, 'gateway' => 'my_pay', 'reference' => null, 'message' => null], $res);
    }

    public function test_store_order_and_storeless_payments_are_refused_before_the_provider(): void
    {
        $tx = $this->storePayment();
        $order = $tx->replicate();
        $order->forceFill(['transaction_method' => 'order'])->save();
        $this->assertSame($this->manual('my_pay', 'Store orders are refunded from the order.') + ['refused' => true],
            GatewayRefundService::refundStorePayment($order, 5.0));

        $platform = $tx->replicate();
        $platform->forceFill(['store_id' => null])->save();
        $this->assertSame($this->manual('my_pay', 'Store orders are refunded from the order.') + ['refused' => true],
            GatewayRefundService::refundStorePayment($platform, 5.0));
        $this->assertSame([], FakeRefundAdapter::$calls);
    }

    public function test_zero_negative_and_non_finite_amounts_are_refused_and_the_smallest_positive_is_sent(): void
    {
        $tx = $this->storePayment();
        foreach ([0.0, -0.001, INF, NAN] as $amount) {
            $this->assertSame($this->manual('my_pay', 'Nothing to refund.') + ['refused' => true],
                GatewayRefundService::refundStorePayment($tx, $amount), (string) $amount);
        }
        $this->assertSame([], FakeRefundAdapter::$calls);

        $this->assertTrue(GatewayRefundService::refundStorePayment($tx, 0.001)['automatic']);
        $this->assertCount(1, FakeRefundAdapter::$calls);
    }

    public function test_no_gateway_or_store_opt_out_records_a_manual_refund_without_a_call(): void
    {
        $this->assertSame($this->manual(null, self::MANUAL_NONE), GatewayRefundService::refundStorePayment($this->storePayment(gateway: null), 5.0));
        $this->assertSame($this->manual('my_pay', self::MANUAL_MY_PAY), GatewayRefundService::refundStorePayment($this->storePayment(optIn: false), 5.0));
        $this->assertSame([], FakeRefundAdapter::$calls);
    }

    public function test_an_unsettled_payment_is_refused_and_a_consumed_one_is_refunded(): void
    {
        $this->assertSame($this->manual('my_pay', 'This payment is not settled, so there is nothing to refund.') + ['refused' => true],
            GatewayRefundService::refundStorePayment($this->storePayment('pending'), 5.0));
        $this->assertSame([], FakeRefundAdapter::$calls);

        $this->assertTrue(GatewayRefundService::refundStorePayment($this->storePayment(Transaction::STATUS_CONSUMED), 5.0)['automatic']);
    }

    public function test_an_unregistered_adapter_or_one_without_refunds_is_manual(): void
    {
        FakeRefundAdapter::$refunds = false;
        $this->assertSame($this->manual('my_pay', self::MANUAL_MY_PAY), GatewayRefundService::refundStorePayment($this->storePayment(), 5.0));
        $this->assertSame([], FakeRefundAdapter::$calls);
    }

    public function test_a_provider_exception_is_reported_and_recorded_as_manual(): void
    {
        Exceptions::fake();
        FakeRefundAdapter::$result = new \RuntimeException('provider down');

        $this->assertSame($this->manual('my_pay', self::SEND_FAILED), GatewayRefundService::refundStorePayment($this->storePayment(), 5.0));
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'provider down');
    }

    public function test_a_provider_refusal_is_refused_and_a_failure_is_manual_with_its_message(): void
    {
        $tx = $this->storePayment();
        FakeRefundAdapter::$result = ['ok' => false, 'refused' => true, 'message' => 'Over the cap.'];
        $this->assertSame($this->manual('my_pay', 'Over the cap.') + ['refused' => true], GatewayRefundService::refundStorePayment($tx, 5.0));

        FakeRefundAdapter::$result = ['ok' => false, 'refused' => true];
        $this->assertSame($this->manual('my_pay', 'Nothing to refund.') + ['refused' => true], GatewayRefundService::refundStorePayment($tx, 5.0));

        FakeRefundAdapter::$result = ['ok' => false, 'message' => 'Declined by bank.'];
        $this->assertSame($this->manual('my_pay', 'Declined by bank.'), GatewayRefundService::refundStorePayment($tx, 5.0));

        FakeRefundAdapter::$result = ['ok' => false];
        $this->assertSame($this->manual('my_pay', 'Could not refund automatically — complete this refund in your payment provider.'),
            GatewayRefundService::refundStorePayment($tx, 5.0));
    }

    public function test_the_armed_stub_refunds_through_the_guard_capped_at_what_was_paid(): void
    {
        config(['app.e2e_stubs' => true]);
        $tx = $this->storePayment(gateway: 'stub');

        $ok = GatewayRefundService::refundStorePayment($tx, 10.5, 'KWD');
        $this->assertTrue($ok['automatic']);
        $this->assertSame('stub', $ok['gateway']);
        $this->assertMatchesRegularExpression('/^stub-refund-'.$tx->id.'-[a-z0-9]{8}$/', (string) $ok['reference']);

        $over = GatewayRefundService::refundStorePayment($tx->fresh(), 19.501, 'KWD');
        $this->assertFalse($over['automatic']);
        $this->assertTrue($over['refused'] ?? false);

        $this->assertTrue(GatewayRefundService::refundStorePayment($tx->fresh(), 19.5, 'KWD')['automatic']);
        $this->assertSame([], FakeRefundAdapter::$calls);
    }

    public function test_the_unarmed_stub_is_manual(): void
    {
        config(['app.e2e_stubs' => false]);
        $this->assertSame($this->manual('stub', 'Recorded. Complete this refund in your Stub dashboard to move the money.'),
            GatewayRefundService::refundStorePayment($this->storePayment(gateway: 'stub'), 5.0));
    }

    public function test_platform_refund_guards_and_exact_detail_ledger(): void
    {
        config(['settings.auto_refunds_enabled' => '1']);
        [, $tx] = $this->makePlanTransaction(30.0, 'USD');
        $tx->forceFill(['status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'my_pay'])->save();

        $order = $tx->replicate();
        $order->forceFill(['transaction_method' => 'order'])->save();
        $this->assertSame($this->manual('my_pay', 'Store orders are refunded from the order.'), GatewayRefundService::refundTransaction($order, 5.0));
        $this->assertSame($this->manual('my_pay', 'Nothing to refund.'), GatewayRefundService::refundTransaction($tx->fresh(), 0.0));
        $pending = $tx->replicate();
        $pending->forceFill(['status' => 'pending'])->save();
        $this->assertSame($this->manual('my_pay', 'Nothing to refund.'), GatewayRefundService::refundTransaction($pending, 5.0));
        $this->assertSame([], FakeRefundAdapter::$calls);

        FakeRefundAdapter::$result = ['ok' => false, 'refused' => true, 'message' => 'No.'];
        $this->assertSame($this->manual('my_pay', 'No.') + ['refused' => true], GatewayRefundService::refundTransaction($tx->fresh(), 5.0));
        FakeRefundAdapter::$result = ['ok' => false];
        $this->assertSame($this->manual('my_pay', 'Could not refund automatically — complete this refund in your payment provider.'),
            GatewayRefundService::refundTransaction($tx->fresh(), 5.0));

        Exceptions::fake();
        FakeRefundAdapter::$result = new \RuntimeException('timeout');
        $this->assertSame($this->manual('my_pay', self::SEND_FAILED), GatewayRefundService::refundTransaction($tx->fresh(), 5.0));
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'timeout');

        FakeRefundAdapter::$result = ['ok' => true, 'reference' => 'ref-9'];
        $this->assertSame(['automatic' => true, 'gateway' => 'my_pay', 'reference' => 'ref-9', 'message' => null],
            GatewayRefundService::refundTransaction($tx->fresh(), 7.25));
        $auto = (array) $tx->fresh()->details->auto_refund;
        $this->assertSame('ref-9', $auto['reference']);
        $this->assertSame(7.25, $auto['amount']);
        $this->assertTrue($auto['ledgered']);
    }

    public function test_platform_without_gateway_is_manual(): void
    {
        config(['settings.auto_refunds_enabled' => '1']);
        [, $tx] = $this->makePlanTransaction(30.0, 'USD');
        $tx->forceFill(['status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => null])->save();
        $this->assertSame($this->manual(null, self::MANUAL_NONE), GatewayRefundService::refundTransaction($tx->fresh(), 5.0));
    }

    public function test_drivers_take_configured_refunders_first_and_ignore_other_classes(): void
    {
        config(['refunds.drivers' => [NotARefunder::class, 'No\\Such\\Class', 42]]);
        $drivers = GatewayRefundService::drivers();
        $this->assertNotInstanceOf(FakeOrderRefunder::class, $drivers['my_pay']);

        config(['refunds.drivers' => [FakeOrderRefunder::class]]);
        $this->assertInstanceOf(FakeOrderRefunder::class, GatewayRefundService::drivers()['my_pay']);
    }

    public function test_order_refund_through_a_live_driver_ok_refused_failed_and_thrown(): void
    {
        config(['refunds.drivers' => [FakeOrderRefunder::class]]);
        [$store, $order] = $this->makeOrderTransaction('my_pay', 30.0, [], 'USD');
        $order->forceFill(['payment_gateway' => 'my_pay'])->save();
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');

        FakeOrderRefunder::$result = ['ok' => true, 'reference' => 'drv-5'];
        $this->assertSame(['automatic' => true, 'gateway' => 'my_pay', 'reference' => 'drv-5', 'message' => null],
            GatewayRefundService::refund($order->fresh(), 3.0));

        FakeOrderRefunder::$result = ['ok' => false, 'refused' => true, 'message' => 'Too much.'];
        $this->assertSame($this->manual('my_pay', 'Too much.') + ['refused' => true], GatewayRefundService::refund($order->fresh(), 3.0));

        FakeOrderRefunder::$result = ['ok' => false];
        $this->assertSame($this->manual('my_pay', 'Could not refund automatically — complete this refund in your payment provider.'),
            GatewayRefundService::refund($order->fresh(), 3.0));

        Exceptions::fake();
        FakeOrderRefunder::$result = new \RuntimeException('drv down');
        $this->assertSame($this->manual('my_pay', self::SEND_FAILED), GatewayRefundService::refund($order->fresh(), 3.0));
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'drv down');
    }

    public function test_the_gateway_falls_back_to_the_latest_paying_transaction(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('my_pay', 30.0, [], 'USD');
        $order->forceFill(['payment_gateway' => null])->save();
        $tx->forceFill(['transaction_method' => 'my_pay'])->save();
        $this->assertSame('my_pay', GatewayRefundService::resolveGateway($order->fresh()));

        $tx->forceFill(['transaction_method' => 'cust_wallet_topup'])->save();
        $this->assertNull(GatewayRefundService::resolveGateway($order->fresh()));
    }
}
