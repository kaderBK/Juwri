<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\StripeController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\GatewaySandbox;

/**
 * Phase 4 item 18 — Stripe test mode (docs.stripe.com/testing, /webhooks#verify-manually): Checkout Session →
 * hosted page (3-D Secure there) → `checkout.session.completed` webhook → paid; refund; declined card; duplicate and
 * replayed webhooks; the sandbox flag. Test vectors are built from Stripe's documented scheme
 * (`Stripe-Signature: t=…,v1=HMAC-SHA256(secret, "t.body")`) with an invented secret.
 */
class StripeSandboxTest extends SandboxTestCase
{
    private const WHSEC = 'whsec_test_vector_not_a_real_secret';

    protected function gateway(): string
    {
        return 'stripe';
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.stripe_secret_key' => 'sk_test_platform_fake', 'settings.stripe_webhook_secret' => self::WHSEC, 'settings.stripe_payment_mode' => 'one_time']);
    }

    /** @return array<string, mixed> */
    private function checkoutSession(string $id, string $paymentStatus = 'paid'): array
    {
        return ['id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'url' => 'https://checkout.stripe.com/c/pay/'.$id,
            'payment_status' => $paymentStatus, 'status' => $paymentStatus === 'paid' ? 'complete' : 'open', 'amount_total' => 10000,
            'currency' => 'sar', 'payment_intent' => 'pi_test_1', 'livemode' => false];
    }

    private function event(string $id, array $session, bool $livemode = false): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => $livemode, 'data' => ['object' => $session]]);
    }

    private function signature(string $body, ?int $t = null, string $secret = self::WHSEC): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    public function test_the_signature_matches_the_documented_test_vector(): void
    {
        // Stripe's scheme on a fixed vector: t=1700000000, body {"id":"evt_test"} → HMAC-SHA256 hex.
        $this->assertSame(
            hash_hmac('sha256', '1700000000.{"id":"evt_test"}', self::WHSEC),
            substr($this->signature('{"id":"evt_test"}', 1700000000), strlen('t=1700000000,v1=')),
        );
    }

    public function test_create_redirect_webhook_paid_once_and_a_duplicate_is_a_no_op(): void
    {
        [, $order, $tx] = $this->order(['secret_key' => 'sk_test_store_fake']);
        $this->fake(['POST /v1/checkout/sessions' => fn (ClientRequest $r) => $this->checkoutSession('cs_test_1', 'unpaid') + ['client_reference_id' => $r['client_reference_id']]]);

        $start = StripeController::adapter()->initiate($tx->fresh());
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $start->headers->get('Location'), 'to Stripe\'s hosted page (3-D Secure happens there)');
        $this->assertSame('cs_test_1', $tx->fresh()->payment_id);
        Http::assertSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_store_fake'));

        $body = $this->event('evt_test_paid', $this->checkoutSession('cs_test_1'));
        $this->webhook($body, ['HTTP_STRIPE_SIGNATURE' => $this->signature($body)])->assertOk();
        $this->assertSettledOnce($order, $tx);
        $this->assertCreditedOnce($tx);

        // the same event again (Stripe retries): processed once
        $this->webhook($body, ['HTTP_STRIPE_SIGNATURE' => $this->signature($body)])->assertOk()->assertJson(['message' => 'duplicate']);
        $this->assertCreditedOnce($tx);
        $this->assertSame(1, $this->receipts());
    }

    public function test_the_buyer_return_settles_and_a_declined_card_does_not(): void
    {
        [, $order, $tx] = $this->order(['secret_key' => 'sk_test_store_fake']);
        $tx->update(['payment_id' => 'cs_test_declined']);
        $this->fake(['GET /v1/checkout/sessions/cs_test_declined' => $this->checkoutSession('cs_test_declined', 'unpaid')]);
        $this->get(route('ipn', 'stripe').'?payment_id=cs_test_declined&pay_mode=one_time')->assertRedirect();
        $this->assertNotSettled($order, $tx); // card 4000 0000 0000 0002 (declined): the session stays unpaid

        $this->fake(['GET /v1/checkout/sessions/cs_test_declined' => $this->checkoutSession('cs_test_declined')]);
        $this->get(route('ipn', 'stripe').'?payment_id=cs_test_declined&pay_mode=one_time')->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_unsigned_wrongly_signed_and_replayed_webhooks_are_rejected(): void
    {
        [, $order, $tx] = $this->order(['secret_key' => 'sk_test_store_fake']);
        $tx->update(['payment_id' => 'cs_test_1']);
        $body = $this->event('evt_test_paid', $this->checkoutSession('cs_test_1'));

        $this->webhook($body)->assertStatus(400);
        $this->webhook($body, ['HTTP_STRIPE_SIGNATURE' => $this->signature($body, null, 'whsec_other')])->assertStatus(400);
        // a captured, correctly signed request replayed after the 5-minute tolerance
        $this->webhook($body, ['HTTP_STRIPE_SIGNATURE' => $this->signature($body, time() - 301)])->assertStatus(400);
        // a body changed after signing
        $this->webhook(str_replace('10000', '1', $body), ['HTTP_STRIPE_SIGNATURE' => $this->signature($body)])->assertStatus(400);

        $this->assertNotSettled($order, $tx);
        $this->assertSame(0, $this->receipts());
    }

    public function test_refund_goes_to_the_payment_intent(): void
    {
        [, $tx] = $this->makePlanTransaction(100.0, 'SAR', 'wallet_topup');
        $tx->update(['payment_id' => 'cs_test_9', 'status' => Transaction::STATUS_SUCCESS, 'transaction_gatway' => 'stripe']);
        $this->fake([
            'GET /v1/checkout/sessions/cs_test_9' => array_merge($this->checkoutSession('cs_test_9'), ['payment_intent' => 'pi_test_9']),
            'POST /v1/refunds' => ['id' => 're_test_9', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 2500],
        ]);

        $this->assertSame(['ok' => true, 'reference' => 're_test_9', 'message' => null], StripeController::adapter()->refund($tx->fresh(), 25.0));
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/v1/refunds') && $r['payment_intent'] === 'pi_test_9' && (int) $r['amount'] === 2500);
    }

    public function test_with_sandboxes_off_a_test_key_pays_nothing_and_a_test_mode_event_is_refused(): void
    {
        config(['payments.sandbox.enabled' => false]);
        [, $order, $tx] = $this->order(['secret_key' => 'sk_test_store_fake']);
        $this->fake([]);

        StripeController::adapter()->initiate($tx->fresh());
        Http::assertNothingSent();
        $this->assertNull($tx->fresh()->payment_id, 'no session opened on a test key');

        $tx->update(['payment_id' => 'cs_test_1']);
        $body = $this->event('evt_test_paid', $this->checkoutSession('cs_test_1'));
        $this->webhook($body, ['HTTP_STRIPE_SIGNATURE' => $this->signature($body)])->assertStatus(400);
        $this->assertNotSettled($order, $tx);

        // a live key is never affected
        $this->assertTrue(GatewaySandbox::allowsKey('stripe', 'sk_live_x'));
    }
}
