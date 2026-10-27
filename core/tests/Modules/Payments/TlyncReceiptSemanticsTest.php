<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Tlync\TlyncClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-17b — T-Lync receipt semantics (TDSP Merchant Portal API docs).
 *
 * POST receipt/transaction: completed → 200 {"result":"success","data":{amount,
 * currency, gateway_name, gateway_ref, date_time, reference, ...}}; found but not
 * complete → 200 {"result":"incomplete"}; unknown → 404; bad token → 401. The
 * backend_url callback is UNSIGNED and only triggers the receipt lookup.
 *
 * A payment settles only on a completed receipt in LYD for exactly our amount
 * (integer dirhams), once. Fake keys only; T-Lync HTTP is Http::fake'd.
 */
class TlyncReceiptSemanticsTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const CREDS = ['store_id' => 'store_fake', 'token' => 'tok_fake'];

    private const RECEIPT = 'uat-api.tlync.ly/api/v3/receipt/transaction';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /** @return array{0: Order, 1: Transaction} */
    private function pending(float $amount = 10.0, string $currency = 'LYD'): array
    {
        [, $order, $tx] = $this->makeOrderTransaction('tdsp', $amount, self::CREDS, $currency);

        return [$order, $tx];
    }

    /** @return array<string, mixed> the documented completed receipt */
    private function completed(string $amount = '10.000', string $currency = 'LYD', array $extra = []): array
    {
        return ['result' => 'success', 'data' => array_merge([
            'amount' => $amount, 'currency' => $currency, 'gateway_name' => 'sadad',
            'gateway_ref' => 'GW-1', 'date_time' => '2026-09-29 10:00:00', 'reference' => 'RCPT-1',
        ], $extra)];
    }

    /** The unsigned backend_url callback, as T-Lync (or a forger) would send it. */
    private function tlCallback(Transaction $tx, string $amount = '10.000')
    {
        return $this->post('/webhook/tdsp', [
            'result' => 'success', 'amount' => $amount, 'store_id' => 'store_fake', 'our_ref' => 'OUR-1',
            'payment_method' => 'sadad', 'customer_phone' => '+218900000', 'custom_ref' => (string) $tx->id,
        ])->assertOk();
    }

    private function receiptCalls(): int
    {
        return Http::recorded(fn ($req) => str_contains($req->url(), 'receipt/transaction'))->count();
    }

    public function test_completed_lyd_receipt_for_our_amount_settles_once(): void
    {
        [$order, $tx] = $this->pending(10.0);
        Http::fake([self::RECEIPT => Http::response($this->completed('10.000'), 200)]);

        $this->tlCallback($tx);

        $this->assertSettledOnce($order, $tx);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'receipt/transaction')
            && $req->hasHeader('Authorization', 'Bearer tok_fake')
            && $req['store_id'] === 'store_fake' && $req['custom_ref'] === (string) $tx->id);
    }

    public function test_replayed_callback_is_idempotent(): void
    {
        [$order, $tx] = $this->pending(10.0);
        Http::fake([self::RECEIPT => Http::response($this->completed('10.000'), 200)]);

        $this->tlCallback($tx);
        $this->tlCallback($tx);
        $this->tlCallback($tx);

        $this->assertSettledOnce($order, $tx);
        $this->assertSame(1, $this->receiptCalls(), 'a settled transaction is not looked up again');
    }

    public function test_a_concurrent_settlement_between_lookup_and_claim_does_not_settle_twice(): void
    {
        [$order, $tx] = $this->pending(10.0);
        // The receipt call "races": another request settles the row meanwhile.
        $this->app->instance(TlyncClient::class, new class((int) $tx->id) extends TlyncClient
        {
            public function __construct(private int $txId) {}

            public function receipt(string $base, string $token, string $storeId, string $customRef): Response
            {
                Transaction::whereKey($this->txId)->update(['status' => Transaction::STATUS_SUCCESS]);

                return parent::receipt($base, $token, $storeId, $customRef);
            }
        });
        Http::fake([self::RECEIPT => Http::response($this->completed('10.000'), 200)]);

        $this->tlCallback($tx);

        $this->assertSame(0, (int) $order->fresh()->is_paid, 'the losing request must not run paySuccess');
    }

    public function test_incomplete_receipt_does_not_settle(): void
    {
        [$order, $tx] = $this->pending();
        Http::fake([self::RECEIPT => Http::response(['result' => 'incomplete'], 200)]);

        $this->tlCallback($tx);

        $this->assertNotSettled($order, $tx);
    }

    public function test_unknown_transaction_404_does_not_settle(): void
    {
        [$order, $tx] = $this->pending();
        Http::fake([self::RECEIPT => Http::response(['message' => 'Transaction Id is Invalid'], 404)]);

        $this->tlCallback($tx);

        $this->assertNotSettled($order, $tx);
    }

    public function test_bad_token_401_and_server_error_do_not_settle(): void
    {
        [$order, $tx] = $this->pending();
        Http::fake([self::RECEIPT => Http::sequence()
            ->push(['message' => 'Unauthenticated.'], 401)
            ->push($this->completed('10.000'), 500)]);

        $this->tlCallback($tx);
        $this->tlCallback($tx);

        $this->assertNotSettled($order, $tx);
    }

    public function test_receipt_in_another_currency_does_not_settle(): void
    {
        [$order, $tx] = $this->pending();
        Http::fake([self::RECEIPT => Http::sequence()
            ->push($this->completed('10.000', 'USD'), 200)
            ->push($this->completed('10.000', 'LYDX'), 200)]);

        $this->tlCallback($tx);
        $this->tlCallback($tx);

        $this->assertNotSettled($order, $tx);
    }

    public function test_receipt_for_another_amount_does_not_settle(): void
    {
        [$order, $tx] = $this->pending(10.0);
        Http::fake([self::RECEIPT => Http::sequence()
            ->push($this->completed('10.001'), 200)   // one dirham more
            ->push($this->completed('9.999'), 200)    // one dirham less
            ->push($this->completed('10.0004'), 200)  // finer than a dirham — never rounded into a match
            ->push($this->completed('1.000'), 200)]);

        foreach (range(1, 4) as $_) {
            $this->tlCallback($tx, '10.000');
        }

        $this->assertNotSettled($order, $tx);
    }

    public function test_forged_callback_alone_does_not_settle(): void
    {
        [$order, $tx] = $this->pending();
        Http::fake(); // the receipt endpoint answers 200 with an empty body

        $this->tlCallback($tx, '10.000');

        $this->assertNotSettled($order, $tx);
        $this->assertSame(1, $this->receiptCalls(), 'the callback only triggers the receipt lookup');
    }

    public function test_success_without_the_documented_data_object_does_not_settle(): void
    {
        [$order, $tx] = $this->pending(10.0);
        Http::fake([self::RECEIPT => Http::sequence()
            ->push(['result' => 'success', 'reference' => 'R'], 200)                                            // bare reference
            ->push(['result' => 'success', 'amount' => '10.000', 'currency' => 'LYD', 'reference' => 'R'], 200) // top level, no data
            ->push(['payment_status' => 'paid', 'data' => ['amount' => '10.000', 'currency' => 'LYD']], 200)     // no result
            ->push(['result' => 'Success', 'data' => ['amount' => '10.000', 'currency' => 'LYD']], 200)          // not exactly "success"
            ->push(['result' => 'success', 'data' => [['amount' => '10.000', 'currency' => 'LYD']]], 200)        // data is a list
            ->push(['result' => 'success', 'data' => ['amount' => 'ten', 'currency' => 'LYD']], 200)             // non-numeric amount
            ->push(['result' => 'success', 'data' => ['amount' => '10.000', 'currency' => '']], 200)]);          // empty currency

        foreach (range(1, 7) as $_) {
            $this->tlCallback($tx);
        }

        $this->assertNotSettled($order, $tx);
    }

    public function test_receipt_naming_another_custom_ref_does_not_settle(): void
    {
        [$order, $tx] = $this->pending(10.0);
        Http::fake([self::RECEIPT => Http::response($this->completed('10.000', 'LYD', ['custom_ref' => 'someone-else']), 200)]);

        $this->tlCallback($tx);

        $this->assertNotSettled($order, $tx);
    }

    public function test_non_lyd_transaction_is_never_looked_up_or_settled(): void
    {
        [$order, $tx] = $this->pending(10.0, 'USD');
        Http::fake([self::RECEIPT => Http::response($this->completed('10.000'), 200)]);

        $this->tlCallback($tx);

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_receipt_parsing_is_exact_in_integer_dirhams(): void
    {
        $this->assertSame(10000, TlyncClient::toMinor('10.000'));
        $this->assertSame(10000, TlyncClient::toMinor('10'));
        $this->assertSame(10000, TlyncClient::toMinor(10));
        $this->assertSame(10500, TlyncClient::toMinor('10.5'));
        $this->assertSame(10500, TlyncClient::toMinor(10.5));
        $this->assertSame(10000, TlyncClient::toMinor('10.000000'));
        $this->assertNull(TlyncClient::toMinor('10.0005'));
        $this->assertNull(TlyncClient::toMinor('-1.000'));
        $this->assertNull(TlyncClient::toMinor('1e3'));
        $this->assertNull(TlyncClient::toMinor(''));
        $this->assertSame(12500, TlyncClient::transactionMinor(12.5));
        $this->assertSame(12345, TlyncClient::transactionMinor('12.345'));
        $this->assertSame(-1, TlyncClient::transactionMinor(-5));

        $receipt = $this->completed('12.345');
        $this->assertTrue(TlyncClient::receiptSettles($receipt, 12345, 'ref'));
        $this->assertFalse(TlyncClient::receiptSettles($receipt, 12346, 'ref'));
        $this->assertSame('RCPT-1', TlyncClient::completedReceipt($receipt)['reference'] ?? null);
    }
}
