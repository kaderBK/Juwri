<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Lane D (coordinator review): GatewaySandbox::gate blanks a sandbox store's credentials, webhook secrets included.
 * An empty secret must REFUSE a webhook — never verify HMAC('', body), which anyone can compute. For every adapter
 * with a sandbox mode or test key: sandboxes off, a sandbox-mode store, a webhook naming the order and signed every
 * way we know with an empty key, every provider API faked as "paid" → the order stays unpaid.
 */
class ForgedEmptySecretWebhookTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: array<string, string>, 2?: array<string, mixed>}> gateway => store creds (sandbox mode Yes / test keys), body overrides that reach the settle */
    public static function gateways(): array
    {
        return [
            'noon' => ['noon', ['business_id' => 'B', 'application_id' => 'A', 'api_key' => 'K', 'webhook_secret' => '']],
            'fawry' => ['fawry', ['merchant_code' => 'M', 'security_key' => 'K']],
            'geidea' => ['geidea', ['merchant_public_key' => 'P', 'api_password' => 'K']],
            'iyzico' => ['iyzico', ['api_key' => 'A', 'secret_key' => 'S']],
            'midtrans' => ['midtrans', ['server_key' => 'S', 'client_key' => 'C']],
            'paylink' => ['paylink', ['api_id' => 'A', 'secret_key' => 'S']],
            'paymob' => ['paymob', ['secret_key' => 'S', 'public_key' => 'P', 'integration_id' => '1', 'hmac_secret' => 'H']],
            'paytm' => ['paytm', ['merchant_mid' => 'M', 'merchant_key' => 'K', 'merchant_website' => 'W']],
            'payumoney' => ['payumoney', ['merchant_pos_id' => 'P', 'signature_key' => 'S', 'oauth_client_id' => 'C', 'oauth_client_secret' => 'X']],
            'tamara' => ['tamara', ['api_token' => 'A', 'notification_token' => 'N']],
            'tdsp' => ['tdsp', ['store_id' => 'S', 'token' => 'T']],
            'telr' => ['telr', ['store_id' => 'S', 'authkey' => 'K']],
            '2checkout' => ['2checkout', ['account_number' => 'S', 'public_key' => 'P', 'private_key' => 'K']],
            'stripe' => ['stripe', ['secret_key' => 'sk_test_abc', 'webhook_secret' => '']],
            'tap' => ['tap', ['secret_key' => 'sk_test_abc']],
            'moyasar' => ['moyasar', ['secret_key' => 'sk_test_abc', 'webhook_secret' => '']],
            'tabby' => ['tabby', ['secret_key' => 'sk_test_abc', 'merchant_code' => 'M']],
            'paystack' => ['paystack', ['secret_key' => 'sk_test_abc'], ['event' => 'charge.success', 'data' => ['status' => 'success', 'amount' => 1000, 'currency' => 'SAR']]],
            'flutterwave' => ['flutterwave', ['secret_key' => 'FLWSECK_TEST-abc']],
            'razorpay' => ['razorpay', ['api_key' => 'rzp_test_abc', 'secret_key' => 'S', 'webhook_secret' => '']],
            'mollie' => ['mollie', ['api_key' => 'test_'.str_repeat('a', 30)]],
            'paypal' => ['paypal', ['api_client_id' => 'C', 'api_secret' => 'S', 'webhook_id' => '']],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /**
     * @param  array<string, string>  $creds
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('gateways')]
    public function test_a_webhook_signed_with_an_empty_secret_never_pays(string $gateway, array $creds, array $extra = []): void
    {
        foreach ([null, 'ref_1'] as $paymentId) {
            [, $order, $tx] = $this->makeOrderTransaction($gateway, 10.0, $creds, 'SAR');
            $tx->payment_id = $paymentId;
            $tx->save();
            config(['payments.sandbox.enabled' => false]);

            // Every provider API answers "paid, this amount" — only the credential gate can stop the settle.
            $paid = ['status' => 'paid', 'paid' => true, 'success' => true, 'amount' => 10, 'currency' => 'SAR', 'id' => 'ref_1',
                'result' => ['code' => 0], 'resultCode' => 0, 'order' => ['status' => 'CAPTURED', 'amount' => 10, 'currency' => 'SAR', 'reference' => (string) $tx->id],
                'data' => ['status' => 'successful', 'amount' => 10, 'currency' => 'SAR', 'tx_ref' => (string) $tx->id, 'id' => 1],
                'transaction_status' => 'settlement', 'gross_amount' => '10.00', 'status_code' => '200', 'order_status' => 'PAID', 'orderStatus' => 'Paid', 'orderNumber' => (string) $tx->id, 'id_token' => 'tok', 'access_token' => 'tok'];
            Http::fake(['*' => Http::response($paid)]);

            $id = (string) $tx->id;
            $body = [
                'merchantRefNum' => $id, 'merchantReferenceId' => $id, 'order_reference_id' => $id, 'custom_ref' => $id,
                'tran_cartid' => $id, 'cartid' => $id, 'ORDERID' => $id, 'order_id' => $id, 'orderId' => $id, 'reference' => $id,
                'paymentConversationId' => $id, 'orderNumber' => $id, 'transactionNo' => 'ref_1', 'id' => 'ref_1', 'orderStatus' => 'PAID', 'status' => 'paid', 'paymentAmount' => '10.00',
                'orderAmount' => '10.00', 'obj' => ['id' => 1, 'success' => true, 'amount_cents' => 1000, 'order' => ['id' => 1, 'merchant_order_id' => $id]],
                'data' => ['id' => 1, 'tx_ref' => $id, 'status' => 'successful', 'reference' => $id, 'object' => ['id' => 'cs_1', 'metadata' => ['transaction_id' => $id]]],
                'payload' => ['payment' => ['entity' => ['id' => 'ref_1', 'order_id' => 'ref_1', 'status' => 'captured', 'amount' => 1000, 'currency' => 'SAR']]],
                'events' => [['id' => 'EV1', 'resource_type' => 'payments', 'action' => 'confirmed', 'links' => ['payment' => 'ref_1']]],
                'event' => 'payment.captured', 'type' => 'checkout.session.completed', 'livemode' => true, 'live' => true,
            ];
            $body = array_replace_recursive($body, $extra);
            $raw = (string) json_encode($body);
            $h256 = hash_hmac('sha256', $raw, '');
            $h512 = hash_hmac('sha512', $raw, '');
            $headers = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_WEBHOOK_SIGNATURE' => $h256, 'HTTP_X_PAYSTACK_SIGNATURE' => $h512, 'HTTP_X_RAZORPAY_SIGNATURE' => $h256,
                'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$raw, ''), 'HTTP_SIGNATURE' => $h256,
                'HTTP_HASHSTRING' => $h256, 'HTTP_VERIF_HASH' => '', 'HTTP_X_SIGNATURE' => $h256,
                'HTTP_TAMARATOKEN' => $this->jwt(['order_reference_id' => $id, 'exp' => time() + 600]),
            ];
            $query = '?hmac='.$h512.'&signature='.$h256;

            $this->call('POST', '/webhook/'.$gateway.$query, [], [], [], $headers, $raw);
            $this->call('POST', '/ipn/'.$gateway.$query, $body, [], [], ['HTTP_HASHSTRING' => $h256]);

            $this->assertSame(0, (int) $order->fresh()->is_paid, "{$gateway}: an empty-secret webhook must not pay the order");
            $this->assertNotSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)?->status, "{$gateway}: transaction must not settle");
        }
    }

    /** @param array<string, mixed> $claims */
    private function jwt(array $claims): string
    {
        $b = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b((string) json_encode($claims));

        return $head.'.'.$body.'.'.$b(hash_hmac('sha256', $head.'.'.$body, '', true));
    }
}
