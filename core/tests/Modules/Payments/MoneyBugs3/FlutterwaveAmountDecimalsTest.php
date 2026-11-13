<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MoneyBugs3;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\FlutterwaveController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Bug batch 3 · E — Flutterwave v3 takes the amount in MAJOR units of the currency. pay() sends it at the currency's
 * own decimals (KWD 3.375, not 3.38; UGX whole shillings), and verification compares in those decimals.
 */
class FlutterwaveAmountDecimalsTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const D = 0.000001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['settings.flutterwave_secret_hash' => 'flw-hash', 'settings.flutterwave_secret_key' => null]);
    }

    private function tx(float $amount, string $currency): Transaction
    {
        [$store, , $tx] = $this->makeOrderTransaction('flutterwave', $amount, ['secret_key' => 'FLWSECK_TEST-store'], $currency);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $tx->fresh();
    }

    private function sentAmount(Transaction $tx): float
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/x']], 200)]);
        FlutterwaveController::pay($tx);
        $sent = null;
        Http::assertSent(function (HttpRequest $r) use (&$sent) {
            if (str_ends_with($r->url(), '/v3/payments')) {
                $sent = $r['amount'];

                return true;
            }

            return false;
        });

        return (float) $sent;
    }

    public function test_kwd_amount_is_sent_in_fils(): void
    {
        $this->assertEqualsWithDelta(3.375, $this->sentAmount($this->tx(3.375, 'KWD')), self::D); // before: 3.38
    }

    public function test_ngn_usd_and_ugx_amounts_are_unchanged(): void
    {
        $this->assertEqualsWithDelta(2500.5, $this->sentAmount($this->tx(2500.5, 'NGN')), self::D);
        $this->assertEqualsWithDelta(12.34, $this->sentAmount($this->tx(12.34, 'USD')), self::D);
        $this->assertEqualsWithDelta(5000.0, $this->sentAmount($this->tx(5000, 'UGX')), self::D);
    }

    public function test_a_kwd_payment_five_fils_short_does_not_settle(): void
    {
        $tx = $this->tx(3.375, 'KWD');
        Http::fake(['api.flutterwave.com/v3/transactions/*/verify' => Http::response(['status' => 'success', 'data' => [
            'status' => 'successful', 'amount' => 3.37, 'currency' => 'KWD', 'tx_ref' => (string) $tx->id,
        ]], 200)]);

        $this->call('POST', '/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => 'flw-hash'],
            (string) json_encode(['event' => 'charge.completed', 'data' => ['id' => 'flw_1', 'tx_ref' => (string) $tx->id]]));

        $this->assertNull(Transaction::find($tx->id)->status); // before: settled (3.37 accepted for 3.375, tolerance 0.01)
    }

    public function test_a_payment_started_before_the_fix_at_the_old_2dp_amount_still_settles(): void
    {
        $tx = $this->tx(3.375, 'KWD');
        // P1-23 part 6: only a payment created before the fix (2026-10-01) can carry the old 2-decimal amount.
        Transaction::whereKey($tx->id)->update(['created_at' => '2026-09-30 12:00:00']);
        Http::fake(['api.flutterwave.com/v3/transactions/*/verify' => Http::response(['status' => 'success', 'data' => [
            'status' => 'successful', 'amount' => 3.38, 'currency' => 'KWD', 'tx_ref' => (string) $tx->id,
        ]], 200)]);

        $this->call('POST', '/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => 'flw-hash'],
            (string) json_encode(['event' => 'charge.completed', 'data' => ['id' => 'flw_1', 'tx_ref' => (string) $tx->id]]));

        $this->assertNotNull(Transaction::find($tx->id)->status);
    }

    public function test_a_payment_created_after_the_fix_at_the_old_2dp_amount_does_not_settle(): void
    {
        $tx = $this->tx(3.375, 'KWD'); // P1-23 part 6: exact (Settlement::amountMatches) — before: 3.38 settled 3.375 at any age
        Http::fake(['api.flutterwave.com/v3/transactions/*/verify' => Http::response(['status' => 'success', 'data' => [
            'status' => 'successful', 'amount' => 3.38, 'currency' => 'KWD', 'tx_ref' => (string) $tx->id,
        ]], 200)]);

        $this->call('POST', '/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => 'flw-hash'],
            (string) json_encode(['event' => 'charge.completed', 'data' => ['id' => 'flw_1', 'tx_ref' => (string) $tx->id]]));

        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_an_exact_kwd_payment_settles(): void
    {
        $tx = $this->tx(3.375, 'KWD');
        Http::fake(['api.flutterwave.com/v3/transactions/*/verify' => Http::response(['status' => 'success', 'data' => [
            'status' => 'successful', 'amount' => 3.375, 'currency' => 'KWD', 'tx_ref' => (string) $tx->id,
        ]], 200)]);

        $this->call('POST', '/webhook/flutterwave', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => 'flw-hash'],
            (string) json_encode(['event' => 'charge.completed', 'data' => ['id' => 'flw_1', 'tx_ref' => (string) $tx->id]]));

        $this->assertNotNull(Transaction::find($tx->id)->status);
    }
}
