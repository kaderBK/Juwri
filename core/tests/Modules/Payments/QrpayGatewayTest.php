<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\QrpayController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * B2 — QRPay (Syria) driver SKELETON. Same guardrail proof as eCash, over the
 * real SPEC §3 shapes: token auth → create → status verify (match custom +
 * amount + currency before settle). API stubs (apiToken/apiCreate/apiStatus) are
 * driven via Http::fake on the SPEC endpoints; real base/sandbox creds land later.
 */
class QrpayGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /** client_id plain; secret_id vaulted (restaurant_qrpay_secret_id ∈ SECRET_KEYS). */
    private array $creds = ['client_id' => 'C1', 'secret_id' => 'S1'];

    private function fakeToken(): array
    {
        return ['api.syrpayment.com/authentication/*' => Http::response([
            'access_token' => 'AT-123', 'expire_time' => 600,
        ], 200)];
    }

    private function fakeStatus(bool $success, ?string $custom, float $amount = 100.0, string $currency = 'SYP'): array
    {
        return ['api.syrpayment.com/payment/status' => Http::response([
            'data' => ['token' => 'pt', 'trx_id' => 'TRX9', 'payer' => ['username' => 'u'], 'custom' => $custom,
                'amount' => $amount, 'currency' => $currency],
            'message' => ['code' => 200, 'success' => $success],
        ], 200)];
    }

    public function test_methods_exist(): void
    {
        foreach (['pay', 'ipn', 'webhook'] as $m) {
            $this->assertTrue(method_exists(QrpayController::class, $m), "QrpayController::$m missing");
        }
    }

    public function test_pay_authenticates_creates_and_redirects(): void
    {
        [, , $tx] = $this->makeOrderTransaction('qrpay', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeToken() + [
            'api.syrpayment.com/payment/create' => Http::response(['token' => 'pt', 'payment_url' => 'https://pay.syrpayment.com/qr/9'], 200),
        ]);

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'qrpay']);

        $res->assertRedirect('https://pay.syrpayment.com/qr/9');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/payment/create')
            && $r['custom'] === (string) $tx->id
            && $r['amount'] === '100.00'      // SPEC §3: 2-dp decimal
            && $r['currency'] === 'SYP');
    }

    public function test_pay_without_creds_settles_nothing(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, [], 'SYP');
        Http::fake();

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'qrpay']);

        $res->assertRedirect(route('payment.index', $tx));
        Http::assertNothingSent();
        $this->assertNull($tx->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }

    public function test_webhook_settles_on_verified_success_matching_amount(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeToken() + $this->fakeStatus(true, null, 100.0, 'SYP'));

        $this->post('/webhook/qrpay', ['custom' => (string) $tx->id])->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_without_success_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeToken() + $this->fakeStatus(false, (string) $tx->id, 100.0, 'SYP'));

        $this->post('/webhook/qrpay', ['custom' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_custom_mismatch(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, $this->creds, 'SYP');
        // status echoes a DIFFERENT custom — must never settle our transaction.
        Http::fake($this->fakeToken() + $this->fakeStatus(true, '99999999', 100.0, 'SYP'));

        $this->post('/webhook/qrpay', ['custom' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_amount_tamper(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeToken() + $this->fakeStatus(true, (string) $tx->id, 5.0, 'SYP'));

        $this->post('/webhook/qrpay', ['custom' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_currency_mismatch(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeToken() + $this->fakeStatus(true, (string) $tx->id, 100.0, 'USD'));

        $this->post('/webhook/qrpay', ['custom' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_without_creds_settles_nothing(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, [], 'SYP');
        Http::fake();

        $this->post('/webhook/qrpay', ['custom' => (string) $tx->id])->assertOk();

        Http::assertNothingSent();
        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('qrpay', 100.0, $this->creds, 'SYP');
        Http::fake($this->fakeToken() + $this->fakeStatus(true, (string) $tx->id, 100.0, 'SYP'));

        $payload = ['custom' => (string) $tx->id];
        $this->post('/webhook/qrpay', $payload)->assertOk();
        $this->post('/webhook/qrpay', $payload)->assertOk(); // replay: no double credit

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status);
    }

    public function test_seam_wiring_present(): void
    {
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'qrpay']);

        $pc = file_get_contents(app_path('Modules/Ordering/Http/Controllers/User/PaymentController.php'));
        $this->assertStringContainsString("'qrpay'", $pc);

        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_qrpay_secret_id', $store);

        $vault = file_get_contents(app_path('Modules/Payments/Services/GatewaySecretVault.php'));
        $this->assertStringContainsString('restaurant_qrpay_secret_id', $vault);
    }
}
