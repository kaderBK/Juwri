<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\EcashController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * B1 — eCash (Syria) driver SKELETON. Proves the money guardrails hold even
 * though the real provider wiring is not live yet:
 *   - creds absent → pay() settles NOTHING and sends the buyer back;
 *   - the verify path (server-side status fetch) REFUSES to settle on amount
 *     mismatch and on currency mismatch, with zero side effects;
 *   - settle-once / replay idempotency.
 *
 * The API stubs (apiCreateCheckout/apiFetchStatus) are driven via Http::fake on
 * ASSUMED endpoints (see EcashController TODOs); real paths/creds land later.
 */
class EcashGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /** merchant_id/terminal_id plain; api_key vaulted (restaurant_ecash_api_key ∈ SECRET_KEYS). */
    private array $creds = ['merchant_id' => 'M1', 'terminal_id' => 'T1', 'api_key' => 'K1'];

    private function fakeStatus(string $status = 'SUCCESS', float $amount = 100.0, string $currency = 'SYP'): array
    {
        return ['api.ecash-pay.com/*' => Http::response([
            'status' => $status, 'amount' => $amount, 'currency' => $currency,
        ], 200)];
    }

    public function test_methods_exist(): void
    {
        foreach (['pay', 'ipn', 'webhook'] as $m) {
            $this->assertTrue(method_exists(EcashController::class, $m), "EcashController::$m missing");
        }
    }

    public function test_pay_redirects_to_hosted_checkout_when_configured(): void
    {
        [, , $tx] = $this->makeOrderTransaction('ecash', 100.0, $this->creds, 'SYP');
        Http::fake(['api.ecash-pay.com/*' => Http::response(['checkout_url' => 'https://pay.ecash-pay.com/go/9'], 200)]);

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'ecash']);

        $res->assertRedirect('https://pay.ecash-pay.com/go/9');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/checkout')
            && $r['order_id'] === (string) $tx->id
            && $r['currency'] === 'SYP');
    }

    /** Creds absent → not-configured: no charge, buyer bounced back to the payment page. */
    public function test_pay_without_creds_settles_nothing(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('ecash', 100.0, [], 'SYP');
        Http::fake(); // any stray call would be an empty 200; we assert none is needed

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'ecash']);

        $res->assertRedirect(route('payment.index', $tx));
        Http::assertNothingSent();
        $this->assertNull($tx->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }

    public function test_webhook_settles_on_verified_success_matching_amount(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('ecash', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeStatus('SUCCESS', 100.0, 'SYP'));

        $this->post('/webhook/ecash', ['custom' => (string) $tx->id])->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_without_success_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('ecash', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeStatus('FAILED', 100.0, 'SYP'));

        $this->post('/webhook/ecash', ['custom' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_amount_tamper(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('ecash', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeStatus('SUCCESS', 5.0, 'SYP')); // tampered amount

        $this->post('/webhook/ecash', ['custom' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_currency_mismatch(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('ecash', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeStatus('SUCCESS', 100.0, 'USD')); // wrong currency

        $this->post('/webhook/ecash', ['custom' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    /** Creds absent on the verify path → no status fetch, settle nothing. */
    public function test_webhook_without_creds_settles_nothing(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('ecash', 100.0, [], 'SYP');
        Http::fake();

        $this->post('/webhook/ecash', ['custom' => (string) $tx->id])->assertOk();

        Http::assertNothingSent();
        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('ecash', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeStatus('SUCCESS', 100.0, 'SYP'));

        $payload = ['custom' => (string) $tx->id];
        $this->post('/webhook/ecash', $payload)->assertOk();
        $this->post('/webhook/ecash', $payload)->assertOk(); // replay: no double credit

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status);
    }

    public function test_seam_wiring_present(): void
    {
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'ecash']);

        $pc = file_get_contents(app_path('Modules/Ordering/Http/Controllers/User/PaymentController.php'));
        $this->assertStringContainsString("'ecash'", $pc);

        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_ecash_api_key', $store);

        $vault = file_get_contents(app_path('Modules/Payments/Services/GatewaySecretVault.php'));
        $this->assertStringContainsString('restaurant_ecash_api_key', $vault);
    }
}
