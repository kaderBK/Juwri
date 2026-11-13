<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * G8-P1 — Noon Payments driver. Money guardrails: INITIATE redirect to postUrl,
 * GET-order server re-verify as the sole settlement gate, amount/currency match,
 * idempotency, both contexts.
 */
class NoonGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['business_id' => 'BUS', 'application_id' => 'APP', 'api_key' => 'KEY', 'webhook_secret' => self::WEBHOOK_SECRET];

    /** WIRING-RUN E2: the webhook now rejects unsigned callbacks, so these fixtures sign. */
    private const WEBHOOK_SECRET = 'noon-test-webhook-secret';

    /**
     * WIRING-RUN E2 — noon signs a comma-joined subset of the body with HMAC-SHA512,
     * base64-encoded, and sends it back as the `signature` field. Mirrors
     * NoonWebhookSignatureTest::sign().
     */
    private function signed(array $payload, ?string $secret = null): array
    {
        $payload += ['orderStatus' => 'CAPTURED', 'eventId' => 'EV1', 'eventType' => 'ORDER', 'timeStamp' => '2026-07-18 10:00:00'];
        $parts = [];
        foreach (['orderId', 'orderStatus', 'eventId', 'eventType', 'timeStamp'] as $f) {
            $parts[] = (string) ($payload[$f] ?? '');
        }
        $payload['signature'] = base64_encode(hash_hmac('sha512', implode(',', $parts), $secret ?: self::WEBHOOK_SECRET, true));

        return $payload;
    }

    private function fakeInitiate(): array
    {
        return ['api-test.noonpayments.com/payment/v1/order' => Http::response([
            'resultCode' => 0,
            'result' => [
                'order' => ['id' => 'NOON123', 'status' => 'INITIATED'],
                'checkoutData' => ['postUrl' => 'https://checkout.noonpayments.com/go/NOON123'],
            ],
        ], 200)];
    }

    private function fakeGetOrder(string $reference, string $status = 'CAPTURED', float $amount = 100.0, string $currency = 'SAR'): array
    {
        return ['api-test.noonpayments.com/payment/v1/order/*' => Http::response([
            'resultCode' => 0,
            'result' => [
                'order' => [
                    'id' => 'NOON123',
                    'status' => $status,
                    'reference' => $reference,
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            ],
        ], 200)];
    }

    public function test_pay_initiates_and_redirects_to_post_url(): void
    {
        [, , $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds);
        Http::fake($this->fakeInitiate());

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'noon', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C',
        ]);

        $res->assertRedirect('https://checkout.noonpayments.com/go/NOON123');

        $expectedAuth = 'Key '.base64_encode('BUS.APP:KEY');
        Http::assertSent(function ($r) use ($tx, $expectedAuth) {
            return str_contains($r->url(), '/payment/v1/order')
                && $r['apiOperation'] === 'INITIATE'
                && $r['order']['reference'] === (string) $tx->id
                && (float) $r['order']['amount'] === 100.0
                && $r->hasHeader('Authorization', $expectedAuth);
        });
    }

    public function test_webhook_settles_on_reverified_captured_matching_amount(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds);
        Http::fake($this->fakeGetOrder((string) $tx->id, 'CAPTURED', 100.0, 'SAR'));

        $this->post('/webhook/noon', $this->signed(['orderId' => 'NOON123', 'reference' => (string) $tx->id]))->assertOk();

        $this->assertSettledOnce($order, $tx);
        // Settlement is driven by GET-order, not the body.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/payment/v1/order/NOON123'));
    }

    public function test_webhook_non_captured_status_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds);
        Http::fake($this->fakeGetOrder((string) $tx->id, 'FAILED', 100.0, 'SAR'));

        $this->post('/webhook/noon', $this->signed(['orderId' => 'NOON123', 'reference' => (string) $tx->id]))->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_amount_tamper(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds);
        Http::fake($this->fakeGetOrder((string) $tx->id, 'CAPTURED', 5.0, 'SAR'));

        $this->post('/webhook/noon', $this->signed(['orderId' => 'NOON123', 'reference' => (string) $tx->id]))->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_currency_mismatch(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds, 'SAR');
        Http::fake($this->fakeGetOrder((string) $tx->id, 'CAPTURED', 100.0, 'USD'));

        $this->post('/webhook/noon', $this->signed(['orderId' => 'NOON123', 'reference' => (string) $tx->id]))->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('noon', 100.0, $this->creds);
        Http::fake($this->fakeGetOrder((string) $tx->id, 'CAPTURED', 100.0, 'SAR'));

        $payload = $this->signed(['orderId' => 'NOON123', 'reference' => (string) $tx->id]);
        $this->post('/webhook/noon', $payload)->assertOk();
        $this->post('/webhook/noon', $payload)->assertOk(); // replay: no double credit

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: settled order payment is consumed, not deleted
    }

    public function test_plan_context_settles_with_platform_creds(): void
    {
        config([
            'settings.noon_business_id' => 'PBUS',
            'settings.noon_application_id' => 'PAPP',
            'settings.noon_api_key' => 'PKEY',
            'settings.noon_sandbox' => true,
            // E2: platform-context webhooks verify against the platform secret.
            'settings.noon_webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        Http::fake($this->fakeGetOrder((string) $tx->id, 'CAPTURED', 49.0, 'SAR'));

        $this->post('/webhook/noon', $this->signed(['orderId' => 'NOON123', 'reference' => (string) $tx->id]));

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('noon', $settled->transaction_gatway);
    }

    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/noon.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'noon' =>", $ctrl);
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("'noon'", $edit);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_noon_business_id', $store);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'noon']);
    }
}
