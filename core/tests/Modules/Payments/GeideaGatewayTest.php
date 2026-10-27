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
 * G3-P1 — Geidea (KSA acquirer, HPP). Money guardrails: signed session request,
 * HMAC-verified callback, amount/currency match, bad-signature rejection,
 * idempotency, both contexts, BYOK/admin wiring.
 */
class GeideaGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['merchant_public_key' => 'pk_test', 'api_password' => 'pass'];

    private const BASE = 'https://api.ksamerchant.geidea.net';

    private function requestSig(string $pk, string $amount, string $currency, string $ref, string $ts, string $pass): string
    {
        return base64_encode(hash_hmac('sha256', $pk.$amount.$currency.$ref.$ts, $pass, true));
    }

    private function callbackSig(string $pk, string $amount, string $currency, string $orderId, string $status, string $ref, string $ts, string $pass): string
    {
        return base64_encode(hash_hmac('sha256', $pk.$amount.$currency.$orderId.$status.$ref.$ts, $pass, true));
    }

    private function callbackPayload(string $ref, string $amount, string $status, string $currency, string $pk, string $pass, ?string $sig = null): array
    {
        $orderId = 'GEI-'.$ref;
        $timestamp = '2026-07-12T10:00:00+00:00';
        $signature = $sig ?? $this->callbackSig($pk, $amount, $currency, $orderId, $status, $ref, $timestamp, $pass);

        return [
            'merchantReferenceId' => $ref,
            'orderId' => $orderId,
            'orderAmount' => $amount,
            'orderCurrency' => $currency,
            'status' => $status,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];
    }

    public function test_pay_posts_signed_session_and_redirects(): void
    {
        [, , $tx] = $this->makeOrderTransaction('geidea', 100.0, $this->creds);
        Http::fake([
            self::BASE.'/payment-intent/api/v2/direct/session' => Http::response([
                'session' => ['id' => 'sess_1', 'redirectUrl' => 'https://checkout.geidea.net/sess_1'],
            ], 200),
        ]);

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'geidea', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C',
        ]);

        $res->assertRedirect('https://checkout.geidea.net/sess_1');

        Http::assertSent(function ($r) use ($tx) {
            if (! str_contains($r->url(), '/payment-intent/api/v2/direct/session')) {
                return false;
            }
            $expected = $this->requestSig('pk_test', $r['amount'], $r['currency'], (string) $tx->id, $r['timestamp'], 'pass');

            return $r['merchantReferenceId'] === (string) $tx->id
                && $r['amount'] === '100.00'
                && $r['signature'] === $expected;
        });
    }

    public function test_webhook_settles_on_verified_signed_success(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('geidea', 100.0, $this->creds);

        $payload = $this->callbackPayload((string) $tx->id, '100.00', 'Success', 'SAR', 'pk_test', 'pass');
        $this->post('/webhook/geidea', $payload)->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_bad_signature_is_rejected_and_not_settled(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('geidea', 100.0, $this->creds);

        $payload = $this->callbackPayload((string) $tx->id, '100.00', 'Success', 'SAR', 'pk_test', 'pass', 'not-a-valid-sig');
        $this->post('/webhook/geidea', $payload)->assertStatus(401);

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_amount_tamper_with_valid_sig_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('geidea', 100.0, $this->creds);

        // Signature is valid over the tampered amount (5.00), but it != our tx amount.
        $payload = $this->callbackPayload((string) $tx->id, '5.00', 'Success', 'SAR', 'pk_test', 'pass');
        $this->post('/webhook/geidea', $payload)->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('geidea', 100.0, $this->creds);

        $payload = $this->callbackPayload((string) $tx->id, '100.00', 'Success', 'SAR', 'pk_test', 'pass');
        $this->post('/webhook/geidea', $payload)->assertOk();
        $this->post('/webhook/geidea', $payload)->assertOk(); // replay: no double credit

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: settled order payment is consumed, not deleted
    }

    public function test_plan_context_settles_with_platform_creds(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        config([
            'settings.geidea_merchant_public_key' => 'pk_plat',
            'settings.geidea_api_password' => 'plat_pass',
            'settings.geidea_sandbox' => '1',
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');

        $payload = $this->callbackPayload((string) $tx->id, '49.00', 'Success', 'SAR', 'pk_plat', 'plat_pass');
        $this->post('/webhook/geidea', $payload)->assertOk();

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('geidea', $settled->transaction_gatway);
    }

    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/geidea.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'geidea' =>", $ctrl);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_geidea_merchant_public_key', $store);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'geidea']);
    }
}
