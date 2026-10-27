<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GocardlessController;
use Juwri\Modules\Payments\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * G9-P1 — GoCardless bank-debit driver. PLAN BILLING ONLY, platform-level.
 * Guardrails: Billing Request Flow redirect, RAW-body HMAC-SHA256 signature
 * verify, async pending→confirmed activation timing, replay idempotency, and
 * hard exclusion from restaurant order checkout + BYOK.
 */
class GocardlessGatewayTest extends TestCase
{
    use RefreshDatabase;
    use GatewayTestHelpers;

    private string $secret = 'whsec_test_123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        config([
            'settings.gocardless_access_token'  => 'sandbox_token_abc',
            'settings.gocardless_webhook_secret' => $this->secret,
            'settings.gocardless_sandbox'        => true,
        ]);
    }

    private function fakeFlow(): array
    {
        return [
            'api-sandbox.gocardless.com/billing_requests' => Http::response([
                'billing_requests' => ['id' => 'BRQ123'],
            ], 201),
            'api-sandbox.gocardless.com/billing_request_flows' => Http::response([
                'billing_request_flows' => ['authorisation_url' => 'https://pay.gocardless.com/flow/BRF999'],
            ], 201),
        ];
    }

    /** Build the raw event body + its valid signature for a payments event. */
    private function signedEvent(string $txId, string $action = 'confirmed'): array
    {
        $body = json_encode([
            'events' => [[
                'id'            => 'EV'.$action,
                'resource_type' => 'payments',
                'action'        => $action,
                'links'         => ['payment' => 'PM123'],
                'metadata'      => ['transaction_id' => $txId],
            ]],
        ]);
        $sig = hash_hmac('sha256', $body, $this->secret);

        return [$body, $sig];
    }

    /** Fake the server-side GET /payments/{id} the driver does before settling. */
    private function fakePayment(string $txId, int $amountMinor = 4900, string $currency = 'GBP'): void
    {
        Http::fake([
            'api-sandbox.gocardless.com/payments/*' => Http::response([
                'payments' => [
                    'id'       => 'PM123',
                    'status'   => 'confirmed',
                    'amount'   => $amountMinor,
                    'currency' => $currency,
                    'metadata' => ['transaction_id' => $txId],
                ],
            ], 200),
        ]);
    }

    private function postWebhook(string $body, string $sig)
    {
        return $this->call('POST', '/webhook/gocardless', [], [], [], [
            'HTTP_WEBHOOK_SIGNATURE' => $sig,
            'CONTENT_TYPE'           => 'application/json',
        ], $body);
    }

    public function test_pay_creates_billing_request_flow_and_redirects(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP');
        Http::fake($this->fakeFlow());

        // The driver returns a RedirectResponse to the authorisation_url.
        $redirect = GocardlessController::pay($tx);
        $this->assertSame('https://pay.gocardless.com/flow/BRF999', $redirect->getTargetUrl());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/billing_requests')
            && $r['billing_requests']['mandate_request']['currency'] === 'GBP'
            // Must also request a PAYMENT (amount in minor units) — not a bare mandate.
            && (int) $r['billing_requests']['payment_request']['amount'] === 4900
            && $r['billing_requests']['payment_request']['metadata']['transaction_id'] === (string) $tx->id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/billing_request_flows')
            && $r['billing_request_flows']['links']['billing_request'] === 'BRQ123');
    }

    public function test_webhook_valid_signature_confirmed_activates_plan(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP');
        $this->fakePayment((string) $tx->id);
        [$body, $sig] = $this->signedEvent((string) $tx->id, 'confirmed');

        $this->postWebhook($body, $sig)->assertOk();

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('gocardless', $settled->transaction_gatway);
    }

    public function test_webhook_confirmed_with_amount_mismatch_does_not_activate(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP');
        // Confirmed payment reports a DIFFERENT amount → never settle.
        $this->fakePayment((string) $tx->id, 100);
        [$body, $sig] = $this->signedEvent((string) $tx->id, 'confirmed');

        $this->postWebhook($body, $sig)->assertOk();

        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_webhook_bad_signature_returns_401_and_does_not_activate(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP');
        [$body] = $this->signedEvent((string) $tx->id, 'confirmed');

        $this->postWebhook($body, 'deadbeef_not_the_real_sig')->assertStatus(401);

        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP');
        $this->fakePayment((string) $tx->id);
        [$body, $sig] = $this->signedEvent((string) $tx->id, 'confirmed');

        $this->postWebhook($body, $sig)->assertOk();
        $this->postWebhook($body, $sig)->assertOk(); // replay: no re-activation

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
    }

    public function test_ipn_return_does_not_settle_pending_mandate(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP');

        GocardlessController::ipn(request());

        // Bacs is async: return is mandate-pending, never settled here.
        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_hidden_from_order_checkout_and_byok(): void
    {
        $checkout = file_get_contents(app_path('Modules/Ordering/Http/Controllers/User/PaymentController.php'));
        $this->assertStringNotContainsString('gocardless', $checkout,
            'GoCardless must never appear in the restaurant order-checkout gateway loop');

        $linen = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringNotContainsString('gocardless', $linen,
            'GoCardless must never appear in the restaurant BYOK gateways array');
    }

    public function test_admin_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/gocardless.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'gocardless' =>", $ctrl);
        $this->assertStringContainsString('gocardless_access_token', $ctrl);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'gocardless']);
    }
}
