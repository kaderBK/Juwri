<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\BnplEligibility;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * G4-P1 — Tamara BNPL driver. Money guardrails: create checkout session +
 * redirect, HS256 JWT webhook verify (reject tamper), authorise → capture
 * sequence, amount re-confirm, replay idempotency, threshold gating, both
 * contexts.
 */
class TamaraGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['api_token' => 'tok', 'notification_token' => 'nt'];

    /** Hand-build an HS256 JWT signed with $secret. */
    private function jwt(array $payload, string $secret): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($payload));
        $signing = $header.'.'.$body;
        $sig = $b64(hash_hmac('sha256', $signing, $secret, true));

        return $signing.'.'.$sig;
    }

    /** What pay() records: the Tamara order created for this transaction (WI-06e). */
    private function bind(Transaction $tx, string $tamaraOrderId): void
    {
        $tx->forceFill(['details' => ['tamara_order_id' => $tamaraOrderId]])->save();
    }

    private function fakeTamaraApis(?float $capturedAmount = null, string $currency = 'SAR'): array
    {
        $captureBody = $capturedAmount === null
            ? ['capture_id' => 'cap_1']
            : ['capture_id' => 'cap_1', 'total_amount' => ['amount' => $capturedAmount, 'currency' => $currency]];

        return [
            'api-sandbox.tamara.co/checkout' => Http::response(['order_id' => 'ord_1', 'checkout_id' => 'chk_1', 'checkout_url' => 'https://tamara.co/go/1'], 200),
            'api-sandbox.tamara.co/orders/*/authorise' => Http::response(['status' => 'authorised'], 200),
            'api-sandbox.tamara.co/orders/*/capture' => Http::response($captureBody, 200),
        ];
    }

    public function test_pay_creates_checkout_session_and_redirects(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('tamara', 250.0, $this->creds);
        // WI-09p: the storefront only hands off to a gateway it offers (BNPL on, basket above the minimum).
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        Http::fake($this->fakeTamaraApis());

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'tamara', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C',
        ]);

        $res->assertRedirect('https://tamara.co/go/1');
        $this->assertSame('ord_1', Transaction::find($tx->id)->details->tamara_order_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/checkout')
            && $r['order_reference_id'] === (string) $tx->id
            && (float) $r['total_amount']['amount'] === 250.0
            && $r['payment_type'] === 'PAY_BY_INSTALMENTS');
    }

    public function test_webhook_valid_jwt_authorises_captures_and_settles(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tamara', 250.0, $this->creds);
        $this->bind($tx, 'ord_1');
        Http::fake($this->fakeTamaraApis(250.0));

        $token = $this->jwt([
            'order_id' => 'ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved',
        ], 'nt');

        $this->withHeaders(['tamaraToken' => $token])
            ->post('/webhook/tamara', ['order_reference_id' => (string) $tx->id, 'order_id' => 'ord_1', 'event_type' => 'order_approved'])
            ->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/orders/ord_1/authorise'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/orders/ord_1/capture'));
        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_invalid_jwt_signature_rejected(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tamara', 250.0, $this->creds);
        $this->bind($tx, 'ord_1');
        Http::fake($this->fakeTamaraApis(250.0));

        // Signed with the WRONG secret → signature verification must fail.
        $token = $this->jwt([
            'order_id' => 'ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved',
        ], 'WRONG_SECRET');

        $this->withHeaders(['tamaraToken' => $token])
            ->post('/webhook/tamara', ['order_reference_id' => (string) $tx->id, 'order_id' => 'ord_1', 'event_type' => 'order_approved'])
            ->assertStatus(401);

        $this->assertNotSettled($order, $tx);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/authorise'));
    }

    public function test_webhook_amount_mismatch_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tamara', 250.0, $this->creds);
        $this->bind($tx, 'ord_1');
        // Capture confirms a DIFFERENT amount than our record.
        Http::fake($this->fakeTamaraApis(5.0));

        $token = $this->jwt([
            'order_id' => 'ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved',
        ], 'nt');

        $this->withHeaders(['tamaraToken' => $token])
            ->post('/webhook/tamara', ['order_reference_id' => (string) $tx->id, 'order_id' => 'ord_1', 'event_type' => 'order_approved'])
            ->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_declined_event_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tamara', 250.0, $this->creds);
        $this->bind($tx, 'ord_1');
        Http::fake($this->fakeTamaraApis(250.0));

        $token = $this->jwt([
            'order_id' => 'ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_declined',
        ], 'nt');

        $this->withHeaders(['tamaraToken' => $token])
            ->post('/webhook/tamara', ['order_reference_id' => (string) $tx->id, 'order_id' => 'ord_1', 'event_type' => 'order_declined'])
            ->assertOk();

        $this->assertNotSettled($order, $tx);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/capture'));
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tamara', 250.0, $this->creds);
        $this->bind($tx, 'ord_1');
        Http::fake($this->fakeTamaraApis(250.0));

        $token = $this->jwt([
            'order_id' => 'ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved',
        ], 'nt');
        $body = ['order_reference_id' => (string) $tx->id, 'order_id' => 'ord_1', 'event_type' => 'order_approved'];

        $this->withHeaders(['tamaraToken' => $token])->post('/webhook/tamara', $body)->assertOk();
        $this->withHeaders(['tamaraToken' => $token])->post('/webhook/tamara', $body)->assertOk();

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: settled order payment is consumed, not deleted
    }

    public function test_bnpl_threshold_gates_checkout_visibility(): void
    {
        [$store] = $this->makeOrderTransaction('tamara', 250.0, $this->creds);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_min_total', '200');

        // Below the owner minimum → hidden.
        $this->assertFalse(BnplEligibility::availableForOrder($store, 150.0, 'tamara'));
        // At/above the minimum → visible.
        $this->assertTrue(BnplEligibility::availableForOrder($store, 250.0, 'tamara'));
    }

    public function test_plan_context_settles_with_platform_creds(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        config([
            'settings.tamara_api_token' => 'PLAT_TOK',
            'settings.tamara_notification_token' => 'PLAT_NT',
            'settings.tamara_sandbox' => true,
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        $this->bind($tx, 'ord_9');
        Http::fake($this->fakeTamaraApis(49.0));

        $token = $this->jwt([
            'order_id' => 'ord_9', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved',
        ], 'PLAT_NT');

        $this->withHeaders(['tamaraToken' => $token])
            ->post('/webhook/tamara', ['order_reference_id' => (string) $tx->id, 'order_id' => 'ord_9', 'event_type' => 'order_approved'])
            ->assertOk();

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('tamara', $settled->transaction_gatway);
    }

    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/tamara.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'tamara' =>", $ctrl);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_tamara_api_token', $store);
        $this->assertStringContainsString('restaurant_tamara_notification_token', $store);
    }
}
