<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\RazorpayController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\LedgerEntry;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Juwri\Modules\Payments\Services\LedgerService;
use Smalot\PdfParser\Parser;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Owner decision 2026-10-01 ("don't refuse on Stripe"): Stripe and Razorpay charge a 3-decimal amount between their
 * 10-fils steps ROUNDED DOWN (KWD 12.345 → 12.340); the 5 fils are booked as a payment rounding adjustment
 * (transaction details + a balanced ledger post), the customer is told before paying and sees what was charged after.
 * Before (earlier today) such amounts were refused; before that, rounded half-up (12.350).
 */
class GatewayRoundingTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals();
        app()->setLocale('en');
        PaymentGateway::firstOrCreate(['payment_folder' => 'stripe'], ['payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake', 'settings.stripe_webhook_secret' => 'whsec_fake', 'settings.stripe_payment_mode' => 'one_time']);
    }

    /** @return array{0: Store, 1: Order, 2: Transaction} */
    private function stripeOrder(float $amount = 12.345, string $currency = 'KWD'): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', $amount, ['secret_key' => 'sk_test_fake_store'], $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$store, $order, Transaction::find($tx->id)];
    }

    private function stripeSession(int $total, string $currency = 'kwd'): array
    {
        return ['id' => 'cs_r1', 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'status' => 'complete',
            'amount_total' => $total, 'currency' => $currency, 'payment_intent' => 'pi_r1', 'url' => 'https://checkout.stripe.test/cs_r1'];
    }

    private function account(string $type, int $id, string $kind): ?LedgerAccount
    {
        return LedgerAccount::where(['owner_type' => $type, 'owner_id' => $id, 'kind' => $kind, 'currency' => 'KWD'])->first();
    }

    // ------------------------------------------------------------------ quote

    public function test_quote_rounds_down_only_between_steps(): void
    {
        $this->assertSame(['gateway' => 'stripe', 'currency' => 'KWD', 'total_minor' => 12345, 'charged_minor' => 12340, 'adjustment_minor' => 5, 'step_minor' => 10],
            GatewayRounding::quote('stripe', Money::ofMinor(12345, 'KWD')));
        $this->assertSame(9, GatewayRounding::quote('razorpay', Money::ofMinor(12349, 'OMR'))['adjustment_minor']);
        $this->assertNull(GatewayRounding::quote('stripe', Money::ofMinor(12340, 'KWD'))); // already a step
        $this->assertNull(GatewayRounding::quote('stripe', Money::ofMinor(1234, 'USD')));
        $this->assertNull(GatewayRounding::quote('paypal', Money::ofMinor(12345, 'KWD')));
        $this->assertNull(GatewayRounding::quote('stripe', Money::ofMinor(12345, 'IQD'))); // not a Stripe 3-decimal currency
        $this->assertNull(GatewayRounding::quote('stripe', Money::ofMinor(12345, 'KWD'), 'USD')); // never across currencies
        $this->assertSame(12340, GatewayRounding::chargeMinor('stripe', Money::ofMinor(12345, 'KWD')));
        $this->assertSame(1234, GatewayRounding::chargeMinor('stripe', Money::ofMinor(1234, 'USD')));
    }

    // ------------------------------------------------------------------ order: charge, settle, books

    public function test_order_is_charged_rounded_down_settles_on_that_amount_and_books_the_difference(): void
    {
        [$store, $order, $tx] = $this->stripeOrder();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response($this->stripeSession(12340) + ['payment_status' => 'unpaid', 'status' => 'open'])]);

        StripeController::pay($tx);

        Http::assertSent(fn (ClientRequest $r) => (int) $r['line_items'][0]['price_data']['unit_amount'] === 12340); // before: refused
        $recorded = GatewayRounding::recorded($tx->fresh());
        $this->assertSame([12345, 12340, 5], [$recorded['total_minor'], $recorded['charged_minor'], $recorded['adjustment_minor']]);
        $this->assertNull($recorded['ledger_tx'], 'nothing booked before the payment settles');

        $answers = Http::sequence()->push($this->stripeSession(12345))->push($this->stripeSession(12350))->push($this->stripeSession(12340));
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_r1' => $answers]);
        $this->get(route('ipn', 'stripe').'?payment_id=cs_r1&pay_mode=one_time');
        $this->assertNotSettled($order, $tx); // the full 12.345 is not what we asked for
        $this->get(route('ipn', 'stripe').'?payment_id=cs_r1&pay_mode=one_time');
        $this->assertNotSettled($order, $tx); // nor is more
        $this->get(route('ipn', 'stripe').'?payment_id=cs_r1&pay_mode=one_time');
        $this->assertSettledOnce($order, $tx);

        // Books: the order total is unchanged (charged 12.340 + adjustment 0.005), the adjustment booked once, balanced.
        $tx = $tx->fresh();
        $this->assertSame('12.345', $tx->money('amount')->major());
        $rounding = GatewayRounding::recorded($tx);
        $this->assertNotNull($rounding['ledger_tx']);
        $writeOff = $this->account('restaurant', (int) $store->id, 'payment_rounding');
        $settled = $this->account('restaurant', (int) $store->id, 'payment_rounding_settled');
        $this->assertSame(-0.005, LedgerService::balanceFor($writeOff));
        $this->assertSame(0.005, LedgerService::balanceFor($settled));
        $this->assertSame(0, (int) LedgerEntry::where('transaction_id', $rounding['ledger_tx'])->sum('amount_minor'));

        GatewayRounding::book($tx, 'stripe'); // replay: idempotent
        $this->assertSame(2, LedgerEntry::where('transaction_id', $rounding['ledger_tx'])->count());
        $this->assertSame(-0.005, LedgerService::balanceFor($writeOff));
    }

    public function test_the_signed_webhook_settles_the_rounded_down_charge(): void
    {
        [$store, $order, $tx] = $this->stripeOrder();
        $tx->update(['payment_id' => 'cs_r1']);
        $deliver = function (int $total) {
            $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession($total)]]);
            $t = time();

            return $this->call('POST', route('webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_fake')], $payload);
        };

        $deliver(12350)->assertOk();
        $this->assertNotSettled($order, $tx);
        $deliver(12340)->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertSame(-0.005, LedgerService::balanceFor($this->account('restaurant', (int) $store->id, 'payment_rounding')));
    }

    // ------------------------------------------------------------------ refunds

    private function settledStripeOrder(): array
    {
        [$store, $order, $tx] = $this->stripeOrder();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response($this->stripeSession(12340))]);
        StripeController::pay($tx);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_r1' => Http::response($this->stripeSession(12340))]);
        $this->get(route('ipn', 'stripe').'?payment_id=cs_r1&pay_mode=one_time');
        $this->assertSettledOnce($order, $tx);

        return [$store, $order, Transaction::find($tx->id)];
    }

    private function fakeRefunds(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/cs_r1' => Http::response($this->stripeSession(12340)),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded']),
        ]);
    }

    public function test_a_full_refund_refunds_what_was_charged_and_reverses_the_adjustment(): void
    {
        [$store, , $tx] = $this->settledStripeOrder();
        $this->fakeRefunds();

        $result = StripeController::adapter()->refund($tx, 12.345); // "refund the order" — the total
        $this->assertTrue($result['ok'], (string) $result['message']);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/refunds') && (int) $r['amount'] === 12340);

        $writeOff = $this->account('restaurant', (int) $store->id, 'payment_rounding');
        $this->assertSame(0.0, LedgerService::balanceFor($writeOff), 'the adjustment is reversed');
        $this->assertSame(0.0, LedgerService::balanceFor($this->account('restaurant', (int) $store->id, 'payment_rounding_settled')));
        $this->assertNotNull(GatewayRounding::recorded($tx->fresh())['reversed_tx']);

        $again = StripeController::adapter()->refund($tx->fresh(), 0.01);
        $this->assertFalse($again['ok']); // nothing left: never more than was charged
    }

    public function test_partial_refunds_are_capped_at_the_charged_amount(): void
    {
        [$store, , $tx] = $this->settledStripeOrder();
        $this->fakeRefunds();

        $this->assertTrue(StripeController::adapter()->refund($tx, 5.0)['ok']);
        $this->assertSame(-0.005, LedgerService::balanceFor($this->account('restaurant', (int) $store->id, 'payment_rounding')), 'a partial refund keeps the adjustment');

        $over = StripeController::adapter()->refund($tx->fresh(), 7.35);
        $this->assertFalse($over['ok']);
        $this->assertSame('The refund is more than what is left to refund on this payment (7.340 KWD).', $over['message']);

        $this->assertTrue(StripeController::adapter()->refund($tx->fresh(), 7.34)['ok']);
        $this->assertSame(0.0, LedgerService::balanceFor($this->account('restaurant', (int) $store->id, 'payment_rounding')), 'all charged refunded: reversed');
    }

    // ------------------------------------------------------------------ plan, top-up, Razorpay

    public function test_a_recurring_plan_is_priced_rounded_down_and_each_paid_invoice_books_the_difference(): void
    {
        config(['settings.stripe_payment_mode' => 'recurring', 'settings.currency_code' => 'KWD']);
        [$user, $tx] = $this->makePlanTransaction(9.995, 'KWD', 'membership');
        $tx->update(['product_id' => 7, 'frequency' => 'MONTHLY', 'product_name' => 'Pro']);
        $this->actingAs($user);
        Http::fake(fn (ClientRequest $r) => $r->method() === 'GET'
            ? Http::response(['error' => ['message' => 'No such object']], 404)
            : Http::response(['id' => 'x', 'url' => 'https://checkout.stripe.test/x']));

        StripeController::pay(Transaction::find($tx->id));

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/plans') && (int) $r['amount'] === 9990 && $r['id'] === '7_MONTHLY_9990_KWD');
    }

    public function test_a_wallet_top_up_settles_on_the_rounded_down_charge_and_books_on_the_platform(): void
    {
        config(['settings.currency_code' => 'KWD']);
        [$user, $tx] = $this->makePlanTransaction(12.345, 'KWD', 'wallet_topup');
        $this->actingAs($user);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response($this->stripeSession(12340))]);
        StripeController::pay(Transaction::find($tx->id));
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_r1' => Http::response($this->stripeSession(12340))]);

        $this->get(route('ipn', 'stripe').'?payment_id=cs_r1&pay_mode=one_time');

        $this->assertNotNull(Transaction::find($tx->id)->status);
        $this->assertSame(-0.005, LedgerService::balanceFor($this->account('platform', 0, 'payment_rounding')));
    }

    public function test_razorpay_charges_rounded_down_and_its_webhook_settles_that_amount(): void
    {
        PaymentGateway::firstOrCreate(['payment_folder' => 'razorpay'], ['payment_title' => 'Razorpay', 'payment_install' => '1']);
        config(['settings.razorpay_api_key' => 'rzp_test_fake', 'settings.razorpay_secret_key' => 'fake_secret',
            'settings.razorpay_webhook_secret' => 'fake_webhook_secret', 'settings.currency_code' => 'KWD']);
        [$user, $tx] = $this->makePlanTransaction(1.235, 'KWD', 'wallet_topup');
        $this->actingAs($user);
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_R1'])]);

        $details = TestResponse::fromBaseResponse(RazorpayController::pay(Transaction::find($tx->id)))->assertOk()->viewData('details');
        $this->assertSame(1230, $details['amount']);
        $tx = Transaction::find($tx->id);
        $tx->update(['payment_id' => 'order_R1']);
        $deliver = function (int $amount) {
            $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
                'id' => 'pay_R1', 'order_id' => 'order_R1', 'status' => 'captured', 'amount' => $amount, 'currency' => 'KWD']]]]);

            return $this->call('POST', route('webhook', 'razorpay'), [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $payload, 'fake_webhook_secret')], $payload);
        };

        $deliver(1240);
        $this->assertNull(Transaction::find($tx->id)->status);
        $deliver(1230);
        $this->assertNotNull(Transaction::find($tx->id)->status);
        $this->assertSame(-0.005, LedgerService::balanceFor($this->account('platform', 0, 'payment_rounding')));
    }

    // ------------------------------------------------------------------ what the customer sees

    public function test_the_pay_page_warns_before_paying_only_when_rounding_applies(): void
    {
        [$store, , $tx] = $this->stripeOrder();
        StoreOption::updateStoreOption($store->id, 'restaurant_paystack_install', '1');
        $notice = 'Card payments in KWD are charged in steps of 0.010. You will be charged 12.340 KWD (0.005 KWD less than the total).';

        $html = $this->get("/payment/{$tx->id}")->assertOk()->getContent();
        $this->assertStringContainsString(e($notice), $html);
        $this->assertSame(1, substr_count($html, 'data-rounding-notice='), 'Stripe only, not Paystack');

        [, , $whole] = $this->stripeOrder(12.34);
        $this->assertStringNotContainsString('data-rounding-notice=', $this->get("/payment/{$whole->id}")->assertOk()->getContent());
        [, , $usd] = $this->stripeOrder(12.5, 'USD');
        $this->assertStringNotContainsString('data-rounding-notice=', $this->get("/payment/{$usd->id}")->assertOk()->getContent());

        app()->setLocale('fr');
        $this->assertSame('Les paiements par carte en KWD sont débités par paliers de 0.010. Vous serez débité de 12.340 KWD (0.005 KWD de moins que le total).',
            GatewayRounding::notice('stripe', Money::ofMinor(12345, 'KWD')));
        $this->assertNull(GatewayRounding::stepNotice('stripe', 'USD'));
        $this->assertNotNull(GatewayRounding::stepNotice('razorpay', 'OMR'));
    }

    public function test_receipts_show_the_amount_charged_and_the_rounding(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [$store, $order, $tx] = $this->settledStripeOrder();
        $lines = ['Amount charged' => '12.340 KWD', 'Rounded down for card payment' => '−0.005 KWD'];
        $this->assertSame($lines, GatewayRounding::receiptLines($tx));

        // Customer order page (shared partial on every storefront track page).
        $page = view('store_templates.shared.payment-pending', ['order' => Order::find($order->id)])->render();
        $this->assertStringContainsString('data-rounding-lines', $page);
        $this->assertStringContainsString('12.340 KWD', $page);
        $this->assertStringContainsString('−0.005 KWD', $page);
        $this->assertSame([], GatewayRounding::receiptLines(GatewayRounding::orderTransaction(Order::find($this->stripeOrder(12.34)[1]->id))));

        // Plan / top-up invoice (web + PDF).
        [$user, $plan] = $this->makePlanTransaction(12.345, 'KWD', 'wallet_topup');
        GatewayRounding::record($plan, 'stripe');
        $plan->update(['status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'stripe', 'payment_id' => 'cs_inv']);
        $invoice = $this->actingAs($user)->get(route('invoice', ['transaction' => $plan->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-rounding-line', $invoice);
        $this->assertStringContainsString('12.340 KWD', $invoice);
        $pdf = $this->actingAs($user)->get(route('invoice', ['transaction' => $plan->id, 'download' => 'pdf']))->assertOk()->getContent();
        // The amount actually charged sits under the total, in the PDF's text layer (not raw bytes).
        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
        $this->assertStringContainsString('12.340 KWD', $text);
    }
}
