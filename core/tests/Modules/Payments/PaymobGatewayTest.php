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
 * G2-P1 — Paymob driver. Money guardrails: Intention API redirect (cents),
 * HMAC-SHA512 callback verify (hand-computed vector), amount match, replay
 * idempotency, both contexts, BYOK/admin wiring.
 */
class PaymobGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = [
        'secret_key' => 'sk_test_secret',
        'public_key' => 'pk_test_public',
        'integration_id' => '9988',
        'hmac_secret' => 'HMAC_TENANT_SECRET',
    ];

    /** Ordered exactly like PaymobController::HMAC_FIELDS. */
    private function hmacValue($v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_null($v)) {
            return '';
        }

        return (string) $v;
    }

    /** Build a callback `obj` for our transaction id, echoed as merchant_order_id. */
    private function callbackObj(string $merchantOrderId, int $amountCents = 10000, string $currency = 'SAR', bool $success = true): array
    {
        return [
            'amount_cents' => $amountCents,
            'created_at' => '2026-07-12T10:00:00.000000',
            'currency' => $currency,
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => 555001,
            'integration_id' => 9988,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => 777002, 'merchant_order_id' => $merchantOrderId],
            'owner' => 12345,
            'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => $success,
        ];
    }

    /** Compute the HMAC-SHA512 the same way the controller does. */
    private function computeHmac(array $obj, string $secret): string
    {
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
            'source_data.sub_type', 'source_data.type', 'success',
        ];
        $concat = '';
        foreach ($fields as $f) {
            $concat .= $this->hmacValue(data_get($obj, $f));
        }

        return hash_hmac('sha512', $concat, $secret);
    }

    public function test_pay_creates_intention_and_redirects_to_unified_checkout(): void
    {
        [, , $tx] = $this->makeOrderTransaction('paymob', 100.0, $this->creds);
        Http::fake([
            'ksa.paymob.com/v1/intention/' => Http::response(['client_secret' => 'CS_ABC123', 'intention_order_id' => 777002], 200),
        ]);

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'paymob', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C',
        ]);

        $res->assertRedirect('https://ksa.paymob.com/unifiedcheckout/?publicKey=pk_test_public&clientSecret=CS_ABC123');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/intention/')
            && (int) $r['amount'] === 10000
            && $r['special_reference'] === (string) $tx->id
            && str_contains($r->header('Authorization')[0], 'Token sk_test_secret'));
    }

    public function test_webhook_settles_on_valid_hmac_and_matching_amount(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paymob', 100.0, $this->creds);
        $tx->forceFill(['details' => ['paymob_order_id' => '777002']])->save(); // WI-06d: recorded at pay()
        $obj = $this->callbackObj((string) $tx->id, 10000, 'SAR', true);
        $hmac = $this->computeHmac($obj, $this->creds['hmac_secret']);

        $this->post("/webhook/paymob?hmac={$hmac}", ['obj' => $obj])->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_bad_hmac_returns_401_and_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paymob', 100.0, $this->creds);
        $tx->forceFill(['details' => ['paymob_order_id' => '777002']])->save(); // WI-06d: recorded at pay()
        $obj = $this->callbackObj((string) $tx->id, 10000, 'SAR', true);

        $this->post('/webhook/paymob?hmac=deadbeef', ['obj' => $obj])->assertStatus(401);

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_amount_mismatch_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paymob', 100.0, $this->creds);
        $tx->forceFill(['details' => ['paymob_order_id' => '777002']])->save(); // WI-06d: recorded at pay()
        // Valid HMAC over a payload that claims a different (smaller) amount.
        $obj = $this->callbackObj((string) $tx->id, 500, 'SAR', true);
        $hmac = $this->computeHmac($obj, $this->creds['hmac_secret']);

        $this->post("/webhook/paymob?hmac={$hmac}", ['obj' => $obj])->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('paymob', 100.0, $this->creds);
        $tx->forceFill(['details' => ['paymob_order_id' => '777002']])->save(); // WI-06d: recorded at pay()
        $obj = $this->callbackObj((string) $tx->id, 10000, 'SAR', true);
        $hmac = $this->computeHmac($obj, $this->creds['hmac_secret']);

        $this->post("/webhook/paymob?hmac={$hmac}", ['obj' => $obj])->assertOk();
        $this->post("/webhook/paymob?hmac={$hmac}", ['obj' => $obj])->assertOk(); // replay: noop

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: settled order payment is consumed, not deleted
    }

    public function test_plan_context_settles_with_platform_creds(): void
    {
        config([
            'settings.paymob_secret_key' => 'sk_platform',
            'settings.paymob_public_key' => 'pk_platform',
            'settings.paymob_integration_id' => '4321',
            'settings.paymob_hmac_secret' => 'PLATFORM_HMAC',
            'settings.paymob_sandbox' => true,
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR');
        $tx->forceFill(['details' => ['paymob_order_id' => '777002']])->save(); // WI-06d: recorded at pay()
        $obj = $this->callbackObj((string) $tx->id, 4900, 'SAR', true);
        $hmac = $this->computeHmac($obj, 'PLATFORM_HMAC');

        $this->post("/webhook/paymob?hmac={$hmac}", ['obj' => $obj]);

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('paymob', $settled->transaction_gatway);
    }

    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/paymob.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'paymob' =>", $ctrl);
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("'paymob'", $edit);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_paymob_secret_key', $store);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'paymob']);
    }
}
