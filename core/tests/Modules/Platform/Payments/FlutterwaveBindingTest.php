<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-15-sec (review B-R3, P2): Flutterwave settles only a verified transaction
 * bound to the pending one (`data.tx_ref` = our id), in the currency pay()
 * charged, read with that transaction's own key; the webhook fails closed
 * without a secret hash.
 *
 * Flutterwave API used (developer.flutterwave.com): GET
 * /v3/transactions/{id}/verify with the secret key; `data.status`
 * (`successful`), `data.tx_ref`, `data.amount`, `data.currency`; webhooks carry
 * the dashboard secret hash in the `verif-hash` header.
 */
class FlutterwaveBindingTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'FLWSECK_TEST-store';

    private const HASH = 'flw-hash';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['settings.flutterwave_secret_hash' => self::HASH, 'settings.flutterwave_secret_key' => null]);
    }

    /** @return array{0: Store, 1: Order, 2: Transaction} */
    private function flwOrder(float $amount = 25.0, ?string $key = self::STORE_KEY): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('flutterwave', $amount, $key === null ? [] : ['secret_key' => $key], 'NGN');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'NGN');

        return [$store, $order, $tx];
    }

    /** @param  array<string, mixed>  $data */
    private function fakeVerify(array $data): void
    {
        Http::fake(['api.flutterwave.com/v3/transactions/*/verify' => Http::response(['status' => 'success', 'data' => $data], 200)]);
    }

    private function webhook(string $flwId, string $txRef, ?string $hash = self::HASH): void
    {
        $this->call('POST', '/webhook/flutterwave', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_VERIF_HASH' => $hash,
        ]), (string) json_encode(['event' => 'charge.completed', 'data' => ['id' => $flwId, 'tx_ref' => $txRef]]));
    }

    public function test_the_webhook_refuses_everything_without_a_secret_hash(): void
    {
        config(['settings.flutterwave_secret_hash' => '']);
        [, $order, $tx] = $this->flwOrder();
        $this->fakeVerify(['status' => 'successful', 'amount' => 25.0, 'currency' => 'NGN', 'tx_ref' => (string) $tx->id]);

        $this->webhook('flw_1', (string) $tx->id, null);
        $this->webhook('flw_1', (string) $tx->id, '');

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_a_payment_made_for_another_transaction_does_not_settle_this_one(): void
    {
        [$store, $order, $tx] = $this->flwOrder();
        // A real, successful payment of the same amount at the same store, for another order.
        $this->fakeVerify(['status' => 'successful', 'amount' => 25.0, 'currency' => 'NGN', 'tx_ref' => (string) ($tx->id + 1000)]);

        $this->webhook('flw_paid_elsewhere', (string) $tx->id);
        $this->get('/ipn/flutterwave?status=successful&transaction_id=flw_paid_elsewhere&tx_ref='.$tx->id);

        $this->assertNotSettled($order, $tx);
    }

    public function test_the_currency_must_be_the_one_charged_even_when_the_transaction_has_none(): void
    {
        [, $order, $tx] = $this->flwOrder();
        $tx->forceFill(['currency_code' => null])->save();
        $this->fakeVerify(['status' => 'successful', 'amount' => 25.0, 'currency' => 'GHS', 'tx_ref' => (string) $tx->id]);

        $this->webhook('flw_1', (string) $tx->id);

        $this->assertNotSettled($order, $tx);
    }

    public function test_a_store_without_its_own_key_is_refused(): void
    {
        config(['settings.flutterwave_secret_key' => 'FLWSECK_TEST-platform']);
        [, $order, $tx] = $this->flwOrder(25.0, null);
        $this->fakeVerify(['status' => 'successful', 'amount' => 25.0, 'currency' => 'NGN', 'tx_ref' => (string) $tx->id]);

        $this->webhook('flw_1', (string) $tx->id);

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_the_bound_payment_settles_once_with_the_store_key(): void
    {
        [, $order, $tx] = $this->flwOrder();
        $this->fakeVerify(['status' => 'successful', 'amount' => 25.0, 'currency' => 'NGN', 'tx_ref' => (string) $tx->id]);

        $this->webhook('flw_1', (string) $tx->id);
        $this->webhook('flw_1', (string) $tx->id);

        $this->assertSettledOnce($order, $tx);
        $this->assertSame('flw_1', Transaction::find($tx->id)->payment_id);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer '.self::STORE_KEY));
    }
}
