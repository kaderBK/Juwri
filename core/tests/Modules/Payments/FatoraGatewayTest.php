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
 * G7-P1 — Fatora (Qatar) driver. Money guardrails: checkout redirect, server-side
 * Verify Payment before settle (redirect/webhook params alone NEVER settle),
 * amount tamper, currency, replay idempotency, both contexts.
 *
 * ASSUMPTION (mirrors FatoraController): verify endpoint POST /payments/verify,
 * success read from result.status == 'SUCCESS', amount from result.amount,
 * checkout URL from result.checkout_url.
 */
class FatoraGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['api_key' => '00000000-0000-4000-8000-00000000fa70'];

    private function fakeCheckout(): array
    {
        return ['api.fatora.io/v1/payments/checkout' => Http::response([
            'result' => ['checkout_url' => 'https://checkout.fatora.io/go/9'],
        ], 200)];
    }

    private function fakeVerify(string $status = 'SUCCESS', float $amount = 100.0, string $currency = 'QAR'): array
    {
        return ['api.fatora.io/v1/payments/verify' => Http::response([
            'result' => ['status' => $status, 'amount' => $amount, 'currency' => $currency],
        ], 200)];
    }

    public function test_pay_posts_checkout_and_redirects(): void
    {
        [, , $tx] = $this->makeOrderTransaction('fatora', 100.0, $this->creds, 'QAR');
        Http::fake($this->fakeCheckout());

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'fatora', 'email' => 'c@e.com', 'phone' => '+974500', 'name' => 'C',
        ]);

        $res->assertRedirect('https://checkout.fatora.io/go/9');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/payments/checkout')
            && $r['order_id'] === (string) $tx->id
            && (float) $r['amount'] === 100.0
            && $r->hasHeader('api_key', $this->creds['api_key']));
    }

    public function test_webhook_settles_on_verified_success_matching_amount(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fatora', 100.0, $this->creds, 'QAR');
        Http::fake($this->fakeVerify('SUCCESS', 100.0, 'QAR'));

        $this->post('/webhook/fatora', ['order_id' => (string) $tx->id])->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    /** CRITICAL: webhook received but verify says unpaid → NEVER settles. */
    public function test_webhook_without_verified_success_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fatora', 100.0, $this->creds, 'QAR');
        Http::fake($this->fakeVerify('FAILED', 100.0, 'QAR'));

        $this->post('/webhook/fatora', ['order_id' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_amount_tamper(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fatora', 100.0, $this->creds, 'QAR');
        Http::fake($this->fakeVerify('SUCCESS', 5.0, 'QAR'));

        $this->post('/webhook/fatora', ['order_id' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_currency_mismatch(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fatora', 100.0, $this->creds, 'QAR');
        Http::fake($this->fakeVerify('SUCCESS', 100.0, 'USD'));

        $this->post('/webhook/fatora', ['order_id' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fatora', 100.0, $this->creds, 'QAR');
        Http::fake($this->fakeVerify('SUCCESS', 100.0, 'QAR'));

        $payload = ['order_id' => (string) $tx->id];
        $this->post('/webhook/fatora', $payload)->assertOk();
        $this->post('/webhook/fatora', $payload)->assertOk(); // replay: no double credit

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: settled order payment is consumed, not deleted
    }

    public function test_plan_context_settles_with_platform_creds(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        config(['settings.fatora_api_key' => 'PLATKEY', 'settings.fatora_sandbox' => true]);
        [, $tx] = $this->makePlanTransaction(49.0, 'QAR');
        Http::fake($this->fakeVerify('SUCCESS', 49.0, 'QAR'));

        $this->post('/webhook/fatora', ['order_id' => (string) $tx->id])->assertOk();

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('fatora', $settled->transaction_gatway);
    }

    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/fatora.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'fatora' =>", $ctrl);
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("'fatora'", $edit);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_fatora_api_key', $store);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'fatora']);
    }
}
