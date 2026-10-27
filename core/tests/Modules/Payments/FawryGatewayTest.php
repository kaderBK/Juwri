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
 * G6-P1 — Fawry (Egypt) driver. Money guardrails: signed hosted-checkout charge,
 * Server-to-Server Notification V2 signature verify (hand-computed vectors),
 * PAID + amount match, cash-at-kiosk pending → later settle, replay idempotency,
 * both contexts. Every SHA-256 signature vector is hand-built here.
 */
class FawryGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private array $creds = ['merchant_code' => 'mc', 'security_key' => 'sk'];

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** SHA-256(merchantCode + merchantRefNum + customerProfileId + returnUrl + itemId + quantity + price(2dp) + secureKey) */
    private function checkoutSig(string $mc, string $ref, string $profile, string $returnUrl, string $itemId, string $qty, $price, string $sk): string
    {
        return hash('sha256', $mc.$ref.$profile.$returnUrl.$itemId.$qty.$this->money($price).$sk);
    }

    /** SHA-256(merchantCode + merchantRefNumber + secureKey) */
    private function statusSig(string $mc, string $ref, string $sk): string
    {
        return hash('sha256', $mc.$ref.$sk);
    }

    /** SHA-256(merchantCode + referenceNumber + refundAmount(2dp) + secureKey) */
    private function refundSig(string $mc, string $refNo, $amount, string $sk): string
    {
        return hash('sha256', $mc.$refNo.$this->money($amount).$sk);
    }

    /** SHA-256(fawryRefNumber + merchantRefNum + paymentAmount(2dp) + orderAmount(2dp) + orderStatus + paymentMethod + paymentRefNumber + secureKey) */
    private function webhookSig(string $fawryRef, string $ref, $payAmt, $ordAmt, string $status, string $method, string $payRefNo, string $sk): string
    {
        return hash('sha256', $fawryRef.$ref.$this->money($payAmt).$this->money($ordAmt).$status.$method.$payRefNo.$sk);
    }

    private function notification(Transaction $tx, string $status, float $amount, string $sk = 'sk', string $fawryRef = 'FAW123', string $method = 'PAYATFAWRY'): array
    {
        $ref = (string) $tx->id;
        $payRefNo = $status === 'PAID' ? 'PRN999' : '';

        return [
            'fawryRefNumber' => $fawryRef,
            'merchantRefNum' => $ref,
            'paymentAmount' => $amount,
            'orderAmount' => $amount,
            'orderStatus' => $status,
            'paymentMethod' => $method,
            'paymentRefNumber' => $payRefNo,
            'messageSignature' => $this->webhookSig($fawryRef, $ref, $amount, $amount, $status, $method, $payRefNo, $sk),
        ];
    }

    public function test_signature_helper_vectors(): void
    {
        // Guard the exact concatenation order/format against silent drift.
        $this->assertSame(hash('sha256', 'mc42sk'), $this->statusSig('mc', '42', 'sk'));
        $this->assertSame(hash('sha256', 'mc4210.00sk'), $this->refundSig('mc', '42', 10, 'sk'));
        $this->assertSame(
            hash('sha256', 'mc7https://ret1150.00sk'),
            $this->checkoutSig('mc', '7', '', 'https://ret', '1', '1', 50, 'sk')
        );
        $this->assertSame(
            hash('sha256', 'FAW7100.00100.00PAIDPAYATFAWRYPRNsk'),
            $this->webhookSig('FAW', '7', 100, 100, 'PAID', 'PAYATFAWRY', 'PRN', 'sk')
        );
    }

    public function test_pay_posts_signed_charge_and_redirects(): void
    {
        [, , $tx] = $this->makeOrderTransaction('fawry', 100.0, $this->creds, 'EGP');
        Http::fake([
            'atfawry.fawrystaging.com/ECommerceWeb/api/payments/charge' => Http::response([
                'nextAction' => ['redirectUrl' => 'https://atfawry.fawrystaging.com/go/abc'],
            ], 200),
        ]);

        $returnUrl = route('ipn', 'fawry');
        $expectedSig = $this->checkoutSig('mc', (string) $tx->id, '', $returnUrl, '1', '1', 100.0, 'sk');

        $res = $this->post("/payment/{$tx->id}/pay", [
            'payment_method' => 'fawry', 'email' => 'c@e.com', 'phone' => '+2010', 'name' => 'C',
        ]);

        $res->assertRedirect('https://atfawry.fawrystaging.com/go/abc');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/payments/charge')
            && $r['merchantRefNum'] === (string) $tx->id
            && $r['signature'] === $expectedSig
            && $r['currencyCode'] === 'EGP');
    }

    public function test_webhook_settles_on_valid_signature_paid_matching_amount(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, $this->creds, 'EGP');

        $this->post('/webhook/fawry', $this->notification($tx, 'PAID', 100.0))->assertOk();

        $this->assertSettledOnce($order, $tx);
    }

    public function test_webhook_bad_signature_returns_401_and_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, $this->creds, 'EGP');

        $payload = $this->notification($tx, 'PAID', 100.0);
        $payload['messageSignature'] = 'deadbeef';

        $this->post('/webhook/fawry', $payload)->assertStatus(401);

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_amount_tamper_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, $this->creds, 'EGP');

        // Signature valid for a 5.00 payment, but our tx is 100.00.
        $this->post('/webhook/fawry', $this->notification($tx, 'PAID', 5.0))->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, $this->creds, 'EGP');

        $payload = $this->notification($tx, 'PAID', 100.0);
        $this->post('/webhook/fawry', $payload)->assertOk();
        $this->post('/webhook/fawry', $payload)->assertOk(); // replay: no double credit

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status); // MC-02: settled order payment is consumed, not deleted
    }

    public function test_cash_at_kiosk_pending_then_later_paid_settles(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, $this->creds, 'EGP');

        // Customer got a Fawry code but hasn't paid yet — NEW, no settle.
        $this->post('/webhook/fawry', $this->notification($tx, 'NEW', 100.0))->assertOk();
        $this->assertNotSettled($order, $tx);

        // Customer pays at the kiosk → PAID notification arrives later → settles.
        $this->post('/webhook/fawry', $this->notification($tx, 'PAID', 100.0))->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_plan_context_settles_with_platform_creds(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        config([
            'settings.fawry_merchant_code' => 'PLAT_MC',
            'settings.fawry_security_key' => 'plat_sk',
            'settings.fawry_sandbox' => true,
        ]);
        [, $tx] = $this->makePlanTransaction(49.0, 'EGP');

        $this->post('/webhook/fawry', $this->notification($tx, 'PAID', 49.0, 'plat_sk'))->assertOk();

        $settled = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $settled->status);
        $this->assertSame('fawry', $settled->transaction_gatway);
    }

    public function test_admin_and_byok_wiring_present(): void
    {
        $this->assertFileExists(resource_path('views/admin/billing/gateways/forms/fawry.blade.php'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString("'fawry' =>", $ctrl);
        $edit = file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString("'fawry'", $edit);
        $store = file_get_contents(app_path('Http/Controllers/User/StoreController.php')).file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString('restaurant_fawry_merchant_code', $store);
        $this->assertDatabaseHas('payment_gateways', ['payment_folder' => 'fawry']);
    }
}
