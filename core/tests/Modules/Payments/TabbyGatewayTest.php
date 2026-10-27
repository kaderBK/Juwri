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
 * G5-P1 — Tabby BNPL driver. Money guardrails: v2 checkout redirect, webhook =
 * custom auth header (X-Tabby-Auth) + RE-FETCH payment by id, capture on
 * AUTHORIZED, status-case normalization, forged-webhook inert, amount match,
 * idempotency, BNPL threshold gating, both contexts.
 *
 * AUTH HEADER: X-Tabby-Auth must equal the tenant secret_key.
 */
class TabbyGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['secret_key' => 'sk_test_1', 'public_key' => 'pk_test_1', 'merchant_code' => 'mc_1'];

    private const RETRIEVE = 'api.tabby.ai/api/v2/payments/PAY123';

    private const CAPTURE = 'api.tabby.ai/api/v2/payments/PAY123/captures';

    private function fakeCheckout(): array
    {
        return ['api.tabby.ai/api/v2/checkout' => Http::response([
            'payment' => ['id' => 'PAY123'],
            'web_url' => 'https://checkout.tabby.ai/go/PAY123',
        ], 200)];
    }

    private function fakeRetrieve(string $status, float $amount = 100.0, string $currency = 'SAR', string $reference = 'X'): array
    {
        return [self::RETRIEVE => Http::response([
            'id' => 'PAY123',
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'order' => ['reference_id' => $reference],
        ], 200)];
    }

    /** What pay() records: the Tabby payment created for this transaction (WI-06e). */
    private function bind(Transaction $tx): string
    {
        $tx->forceFill(['payment_id' => 'PAY123'])->save();

        return (string) $tx->id;
    }

    private function fakeCapture(): array
    {
        return [self::CAPTURE => Http::response(['status' => 'CLOSED'], 200)];
    }

    /** Enable BNPL so the tabby gateway is eligible for order-context. */
    private function enableBnpl($storeId, float $min = 50.0): void
    {
        StoreOption::updateStoreOption($storeId, 'restaurant_bnpl_enabled', '1');
        StoreOption::updateStoreOption($storeId, 'restaurant_bnpl_min_total', (string) $min);
    }

    private function webhookBody(string $ref): array
    {
        return ['id' => 'PAY123', 'order' => ['reference_id' => $ref]];
    }

    // (a) pay() creates checkout + redirects.
    public function test_pay_creates_checkout_and_redirects(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('tabby', 100.0, $this->creds);
        $this->enableBnpl($store->id); // WI-09p: the storefront only hands off to a gateway it offers
        Http::fake($this->fakeCheckout());

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'tabby', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C',
        ]);

        $res->assertRedirect('https://checkout.tabby.ai/go/PAY123');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v2/checkout')
            && $r['payment']['order']['reference_id'] === (string) $tx->id
            && $r['payment']['amount'] === '100.00'
            && $r['merchant_code'] === 'mc_1');
    }

    // (b) webhook w/ correct header + AUTHORIZED → capture → settle.
    public function test_webhook_authorized_captures_and_settles(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tabby', 100.0, $this->creds);
        $ref = $this->bind($tx);
        Http::fake(array_merge($this->fakeRetrieve('AUTHORIZED', 100.0, 'SAR', $ref), $this->fakeCapture()));

        $this->withHeaders(['X-Tabby-Auth' => 'sk_test_1'])
            ->post('/webhook/tabby', $this->webhookBody((string) $tx->id))
            ->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/captures'));
        $this->assertSettledOnce($order, $tx);
    }

    // (c) forged webhook (no/wrong header) → 401, not settled, NO re-fetch.
    public function test_forged_webhook_is_rejected_without_refetch(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tabby', 100.0, $this->creds);
        $ref = $this->bind($tx);
        Http::fake(array_merge($this->fakeRetrieve('CLOSED', 100.0, 'SAR', $ref), $this->fakeCapture()));

        // No header at all.
        $this->post('/webhook/tabby', $this->webhookBody((string) $tx->id))->assertStatus(401);
        // Wrong header.
        $this->withHeaders(['X-Tabby-Auth' => 'wrong'])
            ->post('/webhook/tabby', $this->webhookBody((string) $tx->id))
            ->assertStatus(401);

        $this->assertNotSettled($order, $tx);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v2/payments/'));
    }

    // (d) webhook alone never settles: re-fetch REJECTED → not settled.
    public function test_webhook_rejected_status_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tabby', 100.0, $this->creds);
        $ref = $this->bind($tx);
        Http::fake(array_merge($this->fakeRetrieve('REJECTED', 100.0, 'SAR', $ref), $this->fakeCapture()));

        $this->withHeaders(['X-Tabby-Auth' => 'sk_test_1'])
            ->post('/webhook/tabby', $this->webhookBody((string) $tx->id))
            ->assertOk();

        $this->assertNotSettled($order, $tx);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/captures'));
    }

    // (e) status case normalization: lowercase 'authorized' still settles.
    public function test_status_case_is_normalized(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tabby', 100.0, $this->creds);
        $ref = $this->bind($tx);
        Http::fake(array_merge($this->fakeRetrieve('authorized', 100.0, 'SAR', $ref), $this->fakeCapture()));

        $this->withHeaders(['X-Tabby-Auth' => 'sk_test_1'])
            ->post('/webhook/tabby', $this->webhookBody((string) $tx->id))
            ->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    // (f) amount mismatch → not settled.
    public function test_webhook_amount_mismatch_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tabby', 100.0, $this->creds);
        $ref = $this->bind($tx);
        Http::fake(array_merge($this->fakeRetrieve('CLOSED', 5.0, 'SAR', $ref), $this->fakeCapture()));

        $this->withHeaders(['X-Tabby-Auth' => 'sk_test_1'])
            ->post('/webhook/tabby', $this->webhookBody((string) $tx->id))
            ->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    // (g) replay idempotent.
    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tabby', 100.0, $this->creds);
        $ref = $this->bind($tx);
        Http::fake(array_merge($this->fakeRetrieve('CLOSED', 100.0, 'SAR', $ref), $this->fakeCapture()));

        $body = $this->webhookBody((string) $tx->id);
        $this->withHeaders(['X-Tabby-Auth' => 'sk_test_1'])->post('/webhook/tabby', $body)->assertOk();
        // Replay after the tx is consumed: no double-credit. The auth gate can no
        // longer resolve tenant creds, so the replay is rejected — either way the
        // order is never paid twice and the tx stays gone.
        $this->withHeaders(['X-Tabby-Auth' => 'sk_test_1'])->post('/webhook/tabby', $body);

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: consumed, not deleted
    }

    // (h) BNPL threshold gating via BnplEligibility.
    public function test_bnpl_threshold_gates_checkout_visibility(): void
    {
        [$store, , $txLow] = $this->makeOrderTransaction('tabby', 30.0, $this->creds);
        $this->enableBnpl($store->id, 50.0); // min 50; order 30 → below

        // Below threshold: tabby not offered.
        $below = BnplEligibility::availableForOrder($store, 30.0, 'tabby');
        $this->assertFalse($below);

        // At/above threshold: tabby offered.
        $above = BnplEligibility::availableForOrder($store, 100.0, 'tabby');
        $this->assertTrue($above);
    }

    // (i) plan context settles with platform creds.
    public function test_plan_context_settles_with_platform_creds(): void
    {
        config([
            'settings.tabby_secret_key' => 'sk_plat',
            'settings.tabby_public_key' => 'pk_plat',
            'settings.tabby_merchant_code' => 'mc_plat',
            'settings.tabby_sandbox' => '1',
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        $ref = $this->bind($tx);
        Http::fake(array_merge($this->fakeRetrieve('CLOSED', 49.0, 'SAR', $ref), $this->fakeCapture()));

        $this->withHeaders(['X-Tabby-Auth' => 'sk_plat'])
            ->post('/webhook/tabby', $this->webhookBody((string) $tx->id));

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('tabby', $settled->transaction_gatway);
    }

    // (j) admin + BYOK wiring present.
    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/tabby.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'tabby' =>", $ctrl);
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("'tabby'", $edit);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_tabby_secret_key', $store);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'tabby']);
    }
}
