<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Models\StoreOption;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TapController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\TapAdapter;

/**
 * Phase 4 item 18 — Tap test mode (developers.tap.company: charges, refunds, webhook `hashstring`): charge → hosted
 * page / 3-D Secure (`transaction.url`) → the signed charge POST → paid; refund; declined card; duplicate, unsigned,
 * wrongly signed posts; the sandbox flag. The hashstring vector follows Tap's documented string
 * (x_id…x_amount…x_currency…x_gateway_reference…x_payment_reference…x_status…x_created…) with an invented test key.
 */
class TapSandboxTest extends SandboxTestCase
{
    private const KEY = 'sk_test_store_vector_not_real';

    protected function gateway(): string
    {
        return 'tap';
    }

    /** @return array<string, mixed> */
    private function charge(Transaction $tx, string $status = 'CAPTURED', float $amount = 100.0, bool $live = false): array
    {
        return ['id' => 'chg_TS'.$tx->id, 'object' => 'charge', 'live_mode' => $live, 'status' => $status, 'amount' => $amount, 'currency' => 'SAR',
            'reference' => ['transaction' => (string) $tx->id, 'gateway' => 'GW123', 'payment' => 'PAY456'],
            'transaction' => ['created' => '1700000000000', 'url' => 'https://checkout.tap.company/?mode=page&token=TS'.$tx->id]];
    }

    private function signed(array $charge, string $key = self::KEY): array
    {
        return ['HTTP_HASHSTRING' => TapAdapter::hashString($charge, $key)];
    }

    public function test_the_hashstring_follows_the_documented_string(): void
    {
        $charge = ['id' => 'chg_TS1', 'amount' => 1, 'currency' => 'KWD', 'status' => 'CAPTURED',
            'reference' => ['gateway' => 'GW', 'payment' => 'P'], 'transaction' => ['created' => '1700000000000']];
        $this->assertSame(
            hash_hmac('sha256', 'x_idchg_TS1x_amount1.000x_currencyKWDx_gateway_referenceGWx_payment_referencePx_statusCAPTUREDx_created1700000000000', self::KEY),
            TapAdapter::hashString($charge, self::KEY),
            'KWD amounts at 3 decimals',
        );
    }

    public function test_charge_redirect_signed_post_paid_once_and_a_duplicate_is_a_no_op(): void
    {
        [, $order, $tx] = $this->order(['secret_key' => self::KEY]);
        $this->fake(['POST /v2/charges' => fn (ClientRequest $r) => $this->charge($tx, 'INITIATED')]);

        $start = TapController::adapter()->initiate($tx->fresh());
        $this->assertStringStartsWith('https://checkout.tap.company/', (string) $start->headers->get('Location'));
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://api.tap.company/v2/charges' && $r['post']['url'] === route('webhook', 'tap')
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY));

        $paid = $this->charge($tx);
        $this->fake(['GET /v2/charges/chg_TS'.$tx->id => $paid]);
        $this->webhook(json_encode($paid), $this->signed($paid))->assertOk();
        $this->assertSettledOnce($order, $tx);

        $this->webhook(json_encode($paid), $this->signed($paid))->assertOk()->assertJson(['message' => 'duplicate']);
        $this->assertCreditedOnce($tx);
        $this->assertSame(1, $this->receipts());
    }

    public function test_unsigned_wrongly_signed_and_tampered_posts_are_rejected(): void
    {
        [, $order, $tx] = $this->order(['secret_key' => self::KEY]);
        $paid = $this->charge($tx);
        $this->fake(['GET /v2/charges/' => $paid]);

        $this->webhook(json_encode($paid))->assertStatus(400);
        $this->webhook(json_encode($paid), $this->signed($paid, 'sk_test_someone_else'))->assertStatus(400);
        $tampered = $paid;
        $tampered['amount'] = 1.0;
        $this->webhook(json_encode($tampered), $this->signed($paid))->assertStatus(400);

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_a_declined_card_does_not_settle(): void
    {
        [, $order, $tx] = $this->order(['secret_key' => self::KEY]);
        $declined = $this->charge($tx, 'DECLINED');
        $this->fake(['GET /v2/charges/' => $declined]);

        $this->webhook(json_encode($declined), $this->signed($declined))->assertOk()->assertJson(['message' => 'not paid']);
        $this->get('/ipn/tap?tap_id=chg_TS'.$tx->id.'&txn='.$tx->id)->assertRedirect();
        $this->assertNotSettled($order, $tx);
    }

    public function test_refund_posts_to_tap_with_the_store_key(): void
    {
        [, $order, $tx] = $this->order(['secret_key' => self::KEY]);
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => 'tap', 'payment_id' => 'chg_TS'.$tx->id])->save();
        $this->fake(['POST /v2/refunds' => ['id' => 're_TS1', 'object' => 'refund', 'status' => 'PENDING', 'amount' => 40, 'currency' => 'SAR']]);

        $this->assertTrue(TapController::adapter()->supportsRefund());
        $this->assertSame(['ok' => true, 'reference' => 're_TS1', 'message' => null], TapController::adapter()->refund($tx->fresh(), 40.0));
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://api.tap.company/v2/refunds' && $r['charge_id'] === 'chg_TS'.$tx->id
            && (float) $r['amount'] === 40.0 && $r['currency'] === 'SAR' && $r->hasHeader('Authorization', 'Bearer '.self::KEY));

        $this->fake(['POST /v2/refunds' => ['__status' => 400, 'body' => ['errors' => [['code' => '1126', 'description' => 'Refund amount exceeds']]]]]);
        $this->assertFalse(TapController::adapter()->refund($tx->fresh(), 10.0)['ok']);
    }

    public function test_with_sandboxes_off_a_test_key_pays_nothing_and_a_test_charge_post_is_refused(): void
    {
        config(['payments.sandbox.enabled' => false]);
        [, $order, $tx] = $this->order(['secret_key' => self::KEY]);
        $this->fake([]);

        TapController::adapter()->initiate($tx->fresh());
        $paid = $this->charge($tx);
        $this->webhook(json_encode($paid), $this->signed($paid))->assertStatus(400); // the test key counts as no key

        // with a live key, a post that says it is a test charge is refused
        StoreOption::updateStoreOption($tx->store_id, 'restaurant_tap_secret_key', 'sk_live_store_fake');
        $this->webhook(json_encode($paid), $this->signed($paid, 'sk_live_store_fake'))->assertStatus(400);

        Http::assertNothingSent();
        $this->assertNotSettled($order, $tx);
    }
}
