<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\NoonAdapter;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * E2 — noon Payments webhook signature verification.
 *
 * Scheme (https://docs.noonpayments.com/webhook, "Event data" table and the
 * "Sample signature verification in JavaScript" snippet):
 *
 *     signature = base64( HMAC-SHA512( joined, secret ) )
 *     joined    = orderId,orderStatus,eventId,eventType,timeStamp
 *                 [,originalOrderId,merchantOrderReference,attemptNumber]
 *
 * The secret is generated per webhook endpoint in Merchant Portal > Payment
 * Settings > Webhook (it is NOT the API key).
 *
 * These tests pin: noon's own published test vector, accept-on-valid,
 * reject-on-missing, reject-on-tamper (body and signature), reject-on-
 * unconfigured-secret, and that a rejected event causes NO outbound call.
 */
class NoonWebhookSignatureTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private const SECRET = '9dc5ba3a-8576-4359-a62d-b5c7d13f51f2';

    /** BYOK creds incl. the webhook secret, as stored in store_options. */
    private function creds(): array
    {
        return [
            'business_id' => 'BUS',
            'application_id' => 'APP',
            'api_key' => 'KEY',
            'webhook_secret' => self::SECRET,
        ];
    }

    /** Compute a valid noon signature for a payload (mirrors the published spec). */
    private function sign(array $payload, string $secret = self::SECRET): string
    {
        $fields = ['orderId', 'orderStatus', 'eventId', 'eventType', 'timeStamp'];
        $parts = [];
        foreach ($fields as $f) {
            $parts[] = (string) ($payload[$f] ?? '');
        }
        foreach (['originalOrderId', 'merchantOrderReference', 'attemptNumber'] as $f) {
            if (array_key_exists($f, $payload) && $payload[$f] !== null && $payload[$f] !== '') {
                $parts[] = (string) $payload[$f];
            }
        }

        return base64_encode(hash_hmac('sha512', implode(',', $parts), $secret, true));
    }

    /** A CAPTURED event body for the given reference, signed unless told otherwise. */
    private function event(string $reference, array $overrides = []): array
    {
        return array_merge([
            'eventType' => 'Sale',
            'eventId' => 'C2FE4329-60DB-429D-AB4F-AB02283130B8',
            'timeStamp' => '2021-11-10T08:13:39.2645589Z',
            'orderId' => '605208341474',
            'orderStatus' => 'CAPTURED',
            'reference' => $reference,
        ], $overrides);
    }

    private function fakeGetOrder(string $reference, string $status = 'CAPTURED', float $amount = 100.0, string $currency = 'SAR'): array
    {
        return ['api-test.noonpayments.com/payment/v1/order/*' => Http::response([
            'resultCode' => 0,
            'result' => [
                'order' => [
                    'id' => '605208341474',
                    'status' => $status,
                    'reference' => $reference,
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            ],
        ], 200)];
    }

    /**
     * noon's OWN published test vector, verified through our implementation.
     * If this fails, our signing string or algorithm has drifted from the spec.
     */
    public function test_implementation_matches_noon_published_test_vector(): void
    {
        $payload = [
            'orderId' => '605208341474',
            'orderStatus' => 'FAILED',
            'eventId' => 'C2FE4329-60DB-429D-AB4F-AB02283130B8',
            'eventType' => 'Fail',
            'timeStamp' => '2021-11-10T08:13:39.2645589Z',
            'signature' => 'xkGGD0TCOXDdTshgcPEyW+rIWm9PeNro0Tu1Le/Xpd8KiuManjywF2I7dZlhwkY91MG36VD//ZuTPMVhK7qjoQ==',
        ];

        $verify = fn (array $payload, string $secret): bool => NoonAdapter::signatureIsValid($payload, $secret); // P1-23 part 5: NoonController → NoonAdapter

        $this->assertTrue(
            $verify($payload, self::SECRET),
            "noon's documented sample signature must validate"
        );

        // ...and must not validate under a different secret.
        $this->assertFalse($verify($payload, 'wrong-secret'));
    }

    public function test_valid_signature_is_accepted_and_settles(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id);
        $body['signature'] = $this->sign($body);

        $this->postJson('/webhook/noon', $body)->assertOk();

        $this->assertSettledOnce($order, $tx);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/payment/v1/order/605208341474'));
    }

    public function test_missing_signature_is_rejected(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $this->postJson('/webhook/noon', $this->event((string) $tx->id))
            ->assertStatus(401);

        $this->assertNotSettled($order, $tx);
        // Rejected before any outbound call — no credential use, no amplification.
        Http::assertNothingSent();
    }

    public function test_tampered_body_is_rejected(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        // Sign a FAILED event, then flip the status to CAPTURED in transit.
        $body = $this->event((string) $tx->id, ['orderStatus' => 'FAILED']);
        $body['signature'] = $this->sign($body);
        $body['orderStatus'] = 'CAPTURED';

        $this->postJson('/webhook/noon', $body)->assertStatus(401);

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_tampered_signature_is_rejected(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id);
        $body['signature'] = strrev($this->sign($body));

        $this->postJson('/webhook/noon', $body)->assertStatus(401);

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_signature_from_wrong_secret_is_rejected(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id);
        $body['signature'] = $this->sign($body, 'attacker-secret');

        $this->postJson('/webhook/noon', $body)->assertStatus(401);

        $this->assertNotSettled($order, $tx);
    }

    public function test_unconfigured_secret_fails_closed(): void
    {
        // Same creds MINUS the webhook secret.
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, [
            'business_id' => 'BUS', 'application_id' => 'APP', 'api_key' => 'KEY',
        ]);
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id);
        $body['signature'] = $this->sign($body);

        $this->postJson('/webhook/noon', $body)->assertStatus(401);

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_blank_secret_fails_closed(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, [
            'business_id' => 'BUS', 'application_id' => 'APP', 'api_key' => 'KEY',
            'webhook_secret' => '   ',
        ]);
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id);
        $body['signature'] = $this->sign($body, '   ');

        $this->postJson('/webhook/noon', $body)->assertStatus(401);

        $this->assertNotSettled($order, $tx);
    }

    /**
     * An unresolvable reference is ACK'd, not rejected.
     *
     * Settling deletes the transaction row, so the most common event with no resolvable
     * reference is noon retrying a payment that already completed. Answering 401 there
     * kept noon retrying a finished payment through its ~34h backoff. Nothing can follow
     * a failed lookup — no secret, no outbound call, no state change — so the safe answer
     * is "received, nothing to do". Verification still applies to every event that DOES
     * resolve to a tenant, which is every event that could change anything.
     */
    public function test_unknown_reference_is_acked_without_any_side_effect(): void
    {
        Http::fake($this->fakeGetOrder('999999'));

        $body = $this->event('999999');
        $body['signature'] = $this->sign($body);

        $this->postJson('/webhook/noon', $body)->assertOk();

        Http::assertNothingSent();
    }

    /** An unsigned event for an unresolvable reference is equally inert. */
    public function test_unknown_reference_without_a_signature_is_also_inert(): void
    {
        Http::fake($this->fakeGetOrder('999999'));

        $this->postJson('/webhook/noon', $this->event('999999'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_retry_fields_are_covered_by_the_signature(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id, [
            'originalOrderId' => '605208341400',
            'merchantOrderReference' => (string) $tx->id,
            'attemptNumber' => '2',
        ]);
        $body['signature'] = $this->sign($body);

        $this->postJson('/webhook/noon', $body)->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_retry_field_tamper_is_rejected(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id, [
            'originalOrderId' => '605208341400',
            'merchantOrderReference' => (string) $tx->id,
            'attemptNumber' => '2',
        ]);
        $body['signature'] = $this->sign($body);
        $body['attemptNumber'] = '3'; // covered field, altered in transit

        $this->postJson('/webhook/noon', $body)->assertStatus(401);
        $this->assertNotSettled($order, $tx);
    }

    public function test_plan_context_uses_platform_secret(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        config([
            'settings.noon_business_id' => 'PBUS',
            'settings.noon_application_id' => 'PAPP',
            'settings.noon_api_key' => 'PKEY',
            'settings.noon_sandbox' => true,
            'settings.noon_webhook_secret' => self::SECRET,
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        Http::fake($this->fakeGetOrder((string) $tx->id, 'CAPTURED', 49.0, 'SAR'));

        $body = $this->event((string) $tx->id);
        $body['signature'] = $this->sign($body);

        $this->postJson('/webhook/noon', $body)->assertOk();

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('noon', $settled->transaction_gatway);
    }

    public function test_plan_context_rejects_when_platform_secret_unset(): void
    {
        config([
            'settings.noon_business_id' => 'PBUS',
            'settings.noon_application_id' => 'PAPP',
            'settings.noon_api_key' => 'PKEY',
            'settings.noon_sandbox' => true,
            'settings.noon_webhook_secret' => null,
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        Http::fake($this->fakeGetOrder((string) $tx->id, 'CAPTURED', 49.0, 'SAR'));

        $body = $this->event((string) $tx->id);
        $body['signature'] = $this->sign($body);

        $this->postJson('/webhook/noon', $body)->assertStatus(401);

        $this->assertNull(Transaction::find($tx->id)->status);
    }

    /**
     * The order id is interpolated into the GET-order URL; a traversal payload
     * must never reach the HTTP client even on a correctly signed event.
     */
    public function test_traversal_order_id_is_not_used_for_lookup(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds());
        Http::fake($this->fakeGetOrder((string) $tx->id));

        $body = $this->event((string) $tx->id, ['orderId' => '../../refund/1']);
        $body['signature'] = $this->sign($body);

        $this->postJson('/webhook/noon', $body)->assertOk(); // signature is valid...

        $this->assertNotSettled($order, $tx); // ...but the id is rejected
        Http::assertNothingSent();
    }
}
