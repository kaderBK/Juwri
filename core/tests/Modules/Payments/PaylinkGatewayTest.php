<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * G1-P1 — Paylink / STC Pay driver. Money guardrails: auth+addInvoice redirect,
 * GetInvoice server re-verify, amount/currency match, idempotency, both contexts.
 */
class PaylinkGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['api_id' => 'APP_ID_1123453311', 'secret_key' => 'sekret'];

    private function fakeAuth(): array
    {
        return ['restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'TKN'], 200)];
    }

    private function fakeInvoice(string $orderNumber, string $status = 'Paid', float $amount = 100.0, string $currency = 'SAR'): array
    {
        return ['restpilot.paylink.sa/api/getInvoice/*' => Http::response([
            'orderStatus' => $status,
            'orderNumber' => $orderNumber,
            'amount' => $amount,
            'currency' => $currency,
        ], 200)];
    }

    public function test_pay_authenticates_and_redirects_to_hosted_page(): void
    {
        [, , $tx] = $this->makeOrderTransaction('paylink', 100.0, $this->creds);
        Http::fake(array_merge($this->fakeAuth(), [
            'restpilot.paylink.sa/api/addInvoice' => Http::response(['transactionNo' => 'TX9', 'url' => 'https://pay.sa/go/9'], 200),
        ]));

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'paylink', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C',
        ]);

        $res->assertRedirect('https://pay.sa/go/9');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/addInvoice')
            && $r['orderNumber'] === (string) $tx->id && (float) $r['amount'] === 100.0);
    }

    public function test_webhook_settles_on_verified_paid_matching_amount(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paylink', 100.0, $this->creds);
        Http::fake(array_merge($this->fakeAuth(), $this->fakeInvoice((string) $tx->id, 'Paid', 100.0, 'SAR')));

        $this->post('/webhook/paylink', ['transactionNo' => 'TX9', 'orderNumber' => (string) $tx->id])->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_rejects_amount_tamper(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paylink', 100.0, $this->creds);
        Http::fake(array_merge($this->fakeAuth(), $this->fakeInvoice((string) $tx->id, 'Paid', 5.0, 'SAR')));

        $this->post('/webhook/paylink', ['transactionNo' => 'TX9', 'orderNumber' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_rejects_currency_mismatch(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paylink', 100.0, $this->creds, 'SAR');
        Http::fake(array_merge($this->fakeAuth(), $this->fakeInvoice((string) $tx->id, 'Paid', 100.0, 'USD')));

        $this->post('/webhook/paylink', ['transactionNo' => 'TX9', 'orderNumber' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_not_paid_status_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paylink', 100.0, $this->creds);
        Http::fake(array_merge($this->fakeAuth(), $this->fakeInvoice((string) $tx->id, 'Pending', 100.0, 'SAR')));

        $this->post('/webhook/paylink', ['transactionNo' => 'TX9', 'orderNumber' => (string) $tx->id])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paylink', 100.0, $this->creds);
        Http::fake(array_merge($this->fakeAuth(), $this->fakeInvoice((string) $tx->id, 'Paid', 100.0, 'SAR')));

        $payload = ['transactionNo' => 'TX9', 'orderNumber' => (string) $tx->id];
        $this->post('/webhook/paylink', $payload)->assertOk();
        $this->post('/webhook/paylink', $payload)->assertOk(); // replay: no double credit

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: settled order payment is consumed, not deleted
    }

    public function test_plan_context_settles_with_platform_creds(): void
    {
        config(['settings.paylink_api_id' => 'PLAT', 'settings.paylink_secret_key' => 's', 'settings.paylink_sandbox' => true]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        Http::fake(array_merge($this->fakeAuth(), $this->fakeInvoice((string) $tx->id, 'Paid', 49.0, 'SAR')));

        $this->post('/webhook/paylink', ['transactionNo' => 'TX9', 'orderNumber' => (string) $tx->id]);

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('paylink', $settled->transaction_gatway);
    }

    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/paylink.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'paylink' =>", $ctrl);
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("'paylink'", $edit);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_paylink_api_id', $store);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'paylink']);
    }
}
