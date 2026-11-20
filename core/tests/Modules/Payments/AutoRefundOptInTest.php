<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\PricedOrderLine;
use Tests\TestCase;

/**
 * WI-06a-refunds — owner decision: automatic refunds are OFF unless the store
 * (its orders) or the platform (platform payments) switches them on; while off
 * no provider refund call is made. Stripe stands in for the four registered
 * adapters (Stripe, PayPal, Razorpay, Paystack).
 */
class AutoRefundOptInTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake_platform']);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_fake_r', 'object' => 'checkout.session', 'payment_status' => 'paid',
                'status' => 'complete', 'amount_total' => 3000, 'currency' => 'usd', 'payment_intent' => 'pi_fake_r']),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_fake_r', 'status' => 'succeeded']),
        ]);
    }

    /** A paid Stripe order of 30 USD whose store owner is returned. */
    private function paidStripeOrder(): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 30.0, ['secret_key' => 'sk_test_fake_store'], 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $order->forceFill(['is_paid' => 1, 'payment_gateway' => 'stripe', 'grand_total' => 30, 'subtotal' => 30, 'status' => 'completed'])->save();
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => 'stripe', 'payment_id' => 'cs_fake_r'.$tx->id])->save();
        $order = PricedOrderLine::add($order, 30.0);

        return [Store::find($store->id)->user, $store, $order];
    }

    private function refundCalls(): int
    {
        return Http::recorded(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/refunds'))->count();
    }

    public function test_store_off_by_default_records_a_manual_refund_without_calling_the_provider(): void
    {
        [$owner, , $order] = $this->paidStripeOrder();

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 30])
            ->assertOk()->assertJson(['success' => true, 'automatic' => false]);

        Http::assertNothingSent();
        $this->assertSame('original_payment', $order->fresh()->refund_method);
    }

    public function test_store_on_refunds_once_and_a_replay_makes_no_second_call(): void
    {
        [$owner, $store, $order] = $this->paidStripeOrder();
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 30])
            ->assertOk()->assertJson(['success' => true, 'automatic' => true]);
        $this->assertSame(1, $this->refundCalls());
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/refunds') && $r['payment_intent'] === 'pi_fake_r'
            && (int) $r['amount'] === 3000 && $r->hasHeader('Authorization', 'Bearer sk_test_fake_store'));

        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 30])->assertStatus(422);
        $this->assertSame(1, $this->refundCalls());
    }

    public function test_one_store_switching_on_does_not_switch_on_another(): void
    {
        [, $postA] = $this->paidStripeOrder();
        StoreOption::updateStoreOption($postA->id, 'auto_refunds_enabled', '1');
        [$ownerB, , $orderB] = $this->paidStripeOrder();

        $this->actingAs($ownerB)->postJson(route('stores.refundOrderToOriginal', $orderB->id), ['amount' => 30])
            ->assertOk()->assertJson(['automatic' => false]);
        Http::assertNothingSent();
    }

    public function test_platform_payment_off_on_and_replay(): void
    {
        [, $tx] = $this->makePlanTransaction(30.0, 'USD', 'wallet_topup');
        $tx->forceFill(['status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'stripe', 'payment_id' => 'cs_fake_r'])->save();

        $off = GatewayRefundService::refundTransaction($tx->fresh(), 30.0);
        $this->assertFalse($off['automatic']);
        Http::assertNothingSent();

        config(['settings.auto_refunds_enabled' => '1']);
        $on = GatewayRefundService::refundTransaction($tx->fresh(), 30.0);
        $this->assertSame(['automatic' => true, 'gateway' => 'stripe', 'reference' => 're_fake_r', 'message' => null], $on);
        $this->assertSame('re_fake_r', $tx->fresh()->details->auto_refund->reference);

        $replay = GatewayRefundService::refundTransaction($tx->fresh(), 30.0);
        $this->assertFalse($replay['automatic']);
        $this->assertSame(1, $this->refundCalls());
    }

    public function test_only_the_four_decided_gateways_are_registered(): void
    {
        $this->assertSame(['stripe', 'paypal', 'razorpay', 'paystack'], array_keys(GatewayRefundService::adapters()));
        $this->assertArrayNotHasKey('mollie', GatewayRefundService::drivers());
    }
}
