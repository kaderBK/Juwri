<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Payments;

use App\Http\Middleware\Installed;
use Juwri\Modules\Payments\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06d — Fawry: charged in the transaction currency (EGP only); a notice for another currency never settles.
 * A forged or mismatched notice must not settle.
 */
class FawryCurrencyTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function details(Transaction $tx, array $values): void
    {
        $tx->forceFill(['details' => array_merge((array) ($tx->details ?? []), $values)])->save();
    }

    // ------------------------------------------------------------- Fawry

    private function fawryNotice(Transaction $tx, float $amount, array $extra = []): array
    {
        $ref = (string) $tx->id;
        $money = fn ($a) => number_format((float) $a, 2, '.', '');

        return $extra + [
            'fawryRefNumber' => 'FAW1', 'merchantRefNum' => $ref, 'paymentAmount' => $amount, 'orderAmount' => $amount,
            'orderStatus' => 'PAID', 'paymentMethod' => 'CARD', 'paymentRefNumber' => 'PRN1',
            'messageSignature' => hash('sha256', 'FAW1'.$ref.$money($amount).$money($amount).'PAIDCARDPRN1sk'),
        ];
    }

    public function test_fawry_refuses_to_charge_a_non_egp_transaction(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, ['merchant_code' => 'mc', 'security_key' => 'sk'], 'USD');
        Http::fake();

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'fawry'])->assertRedirect();

        Http::assertNothingSent();
        $this->assertNotSettled($order, $tx);
    }

    public function test_fawry_signed_paid_notice_for_a_non_egp_transaction_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, ['merchant_code' => 'mc', 'security_key' => 'sk'], 'USD');

        $this->post('/webhook/fawry', $this->fawryNotice($tx, 100.0))->assertOk();

        $this->assertNotSettled($order, $tx);
    }

    public function test_fawry_notice_reporting_another_currency_does_not_settle(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('fawry', 100.0, ['merchant_code' => 'mc', 'security_key' => 'sk'], 'EGP');

        $this->post('/webhook/fawry', $this->fawryNotice($tx, 100.0, ['currencyCode' => 'USD']))->assertOk();
        $this->assertNotSettled($order, $tx);

        $this->post('/webhook/fawry', $this->fawryNotice($tx, 100.0, ['currencyCode' => 'EGP']))->assertOk();
        $this->assertSettledOnce($order, $tx);
    }

    public function test_fawry_charges_in_the_transaction_currency(): void
    {
        [, , $tx] = $this->makeOrderTransaction('fawry', 100.0, ['merchant_code' => 'mc', 'security_key' => 'sk'], 'egp');
        Http::fake(['atfawry.fawrystaging.com/*' => Http::response(['nextAction' => ['redirectUrl' => 'https://fawry.test/pay']], 200)]);

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'fawry'])->assertRedirect('https://fawry.test/pay');

        Http::assertSent(fn ($r) => $r['currencyCode'] === 'EGP');
    }
}
