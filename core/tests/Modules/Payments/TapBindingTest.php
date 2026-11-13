<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\TapController;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-15-sec (review B-R1, P0): the Tap return settles only the transaction the
 * verified charge belongs to. The charge is read with the key of the
 * transaction named on the return (store key for orders, platform key for
 * everything else; no key = refused), never a key chosen by the request, and
 * it must be the charge opened for that transaction, CAPTURED, for its amount
 * and currency, in the key's live/test mode.
 *
 * Tap API used (developers.tap.company): GET /v2/charges/{tap_id} with the
 * secret key as bearer; fields id, status, amount, currency, live_mode,
 * reference.transaction.
 */
class TapBindingTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const STORE_KEY = 'sk_test_store';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['settings.tap_secret_key' => null, 'settings.currency_code' => 'SAR']);
    }

    /** @return array{0: Store, 1: Order, 2: Transaction} */
    private function tapOrder(float $amount = 25.0, string $key = self::STORE_KEY): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('tap', $amount, ['secret_key' => $key]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');

        return [$store, $order, $tx];
    }

    /** @param  array<string, mixed>  $overrides */
    private function charge(Transaction $tx, string $id = 'chg_1', array $overrides = []): array
    {
        return array_replace([
            'id' => $id, 'status' => 'CAPTURED', 'amount' => (float) $tx->amount, 'currency' => 'SAR',
            'live_mode' => false, 'reference' => ['transaction' => (string) $tx->id],
        ], $overrides);
    }

    private string $tapOwnerKey = '';

    /** @var array<string, mixed> */
    private array $tapCharge = [];

    private bool $tapFaked = false;

    /**
     * Tap answers a charge only to the key of the account that owns it. Later
     * calls swap the charge (Http::fake stubs stack, so it is registered once).
     *
     * @param  array<string, mixed>  $charge
     */
    private function fakeTap(string $ownerKey, array $charge): void
    {
        $this->tapOwnerKey = $ownerKey;
        $this->tapCharge = $charge;
        if ($this->tapFaked) {
            return;
        }
        $this->tapFaked = true;
        Http::fake(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer '.$this->tapOwnerKey)
            ? Http::response($this->tapCharge, 200)
            : Http::response(['errors' => [['code' => '1140', 'description' => 'Charge not found']]], 404));
    }

    public function test_a_charge_in_a_store_owners_account_cannot_settle_a_platform_transaction(): void
    {
        // The platform has no Tap key; a store owner has one and opens a charge
        // in their own Tap account that references a platform wallet top-up.
        [$attackerStore] = $this->tapOrder(49.0, 'sk_test_attacker');
        [, $victim] = $this->makePlanTransaction(49.0, 'SAR', 'wallet_topup');
        $this->fakeTap('sk_test_attacker', $this->charge($victim, 'chg_evil'));

        $this->get('/ipn/tap?tap_id=chg_evil&store_id='.$attackerStore->id.'&txn='.$victim->id);
        $this->get('/ipn/tap?tap_id=chg_evil&store_id='.$attackerStore->id);

        $this->assertNull($victim->fresh()->status);
        $this->assertNull($victim->fresh()->payment_id);
    }

    public function test_a_charge_in_one_stores_account_cannot_settle_another_stores_order(): void
    {
        config(['settings.tap_secret_key' => 'sk_test_platform']);
        $this->tapOrder(25.0, 'sk_test_attacker');
        [, $victimOrder, $victimTx] = $this->tapOrder(25.0, 'sk_test_victim');
        $this->fakeTap('sk_test_attacker', $this->charge($victimTx, 'chg_evil'));

        $this->get('/ipn/tap?tap_id=chg_evil&txn='.$victimTx->id);

        $this->assertNotSettled($victimOrder, $victimTx);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer sk_test_victim'));
    }

    /** The request may still carry the old `store_id` hint; it picks nothing. */
    public function test_the_charge_must_reference_the_transaction_named_on_the_return(): void
    {
        [, $order, $tx] = $this->tapOrder();
        [, $otherOrder, $otherTx] = $this->tapOrder();
        $this->fakeTap(self::STORE_KEY, $this->charge($otherTx));

        $this->get('/ipn/tap?tap_id=chg_1&store_id='.$tx->store_id.'&txn='.$tx->id);

        $this->assertNotSettled($order, $tx);
        $this->assertNotSettled($otherOrder, $otherTx);
    }

    public function test_currency_or_live_mode_mismatch_does_not_settle(): void
    {
        [, $order, $tx] = $this->tapOrder();

        $this->fakeTap(self::STORE_KEY, $this->charge($tx, 'chg_1', ['currency' => 'KWD']));
        $this->get('/ipn/tap?tap_id=chg_1&store_id='.$tx->store_id.'&txn='.$tx->id);
        $this->assertNotSettled($order, $tx);

        $this->fakeTap(self::STORE_KEY, $this->charge($tx, 'chg_1', ['live_mode' => true]));
        $this->get('/ipn/tap?tap_id=chg_1&store_id='.$tx->store_id.'&txn='.$tx->id);
        $this->assertNotSettled($order, $tx);

        $this->fakeTap(self::STORE_KEY, $this->charge($tx, 'chg_1', ['currency' => null]));
        $this->get('/ipn/tap?tap_id=chg_1&store_id='.$tx->store_id.'&txn='.$tx->id);
        $this->assertNotSettled($order, $tx);

        $this->fakeTap(self::STORE_KEY, $this->charge($tx, 'chg_1', ['status' => 'INITIATED']));
        $this->get('/ipn/tap?tap_id=chg_1&store_id='.$tx->store_id.'&txn='.$tx->id);
        $this->assertNotSettled($order, $tx);
    }

    public function test_a_live_key_needs_a_live_charge(): void
    {
        [, $order, $tx] = $this->tapOrder(25.0, 'sk_live_store');

        $this->fakeTap('sk_live_store', $this->charge($tx, 'chg_1', ['live_mode' => false]));
        $this->get('/ipn/tap?tap_id=chg_1&txn='.$tx->id);
        $this->assertNotSettled($order, $tx);

        $this->fakeTap('sk_live_store', $this->charge($tx, 'chg_1', ['live_mode' => true]));
        $this->get('/ipn/tap?tap_id=chg_1&txn='.$tx->id);
        $this->assertSettledOnce($order, $tx);
    }

    public function test_only_the_charge_opened_for_the_transaction_settles_it(): void
    {
        [, $order, $tx] = $this->tapOrder();
        $tx->forceFill(['details' => ['tap_charge_id' => 'chg_opened']])->save();
        $this->fakeTap(self::STORE_KEY, $this->charge($tx, 'chg_other'));

        $this->get('/ipn/tap?tap_id=chg_other&txn='.$tx->id);

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_the_verified_charge_settles_its_order_once_with_the_store_key(): void
    {
        [, $order, $tx] = $this->tapOrder();
        $tx->forceFill(['details' => ['tap_charge_id' => 'chg_1']])->save();
        $this->fakeTap(self::STORE_KEY, $this->charge($tx));

        $this->get('/ipn/tap?tap_id=chg_1&txn='.$tx->id);
        $this->get('/ipn/tap?tap_id=chg_1&txn='.$tx->id);

        $this->assertSettledOnce($order, $tx);
        $this->assertSame('chg_1', Transaction::find($tx->id)->payment_id);
        Http::assertSentCount(1);
    }

    public function test_a_platform_transaction_is_refused_while_the_platform_key_is_empty(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR', 'wallet_topup');
        $this->fakeTap('', $this->charge($tx));

        $this->get('/ipn/tap?tap_id=chg_1&txn='.$tx->id);

        $this->assertNull($tx->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_pay_names_the_transaction_on_the_return_and_records_the_charge(): void
    {
        [, , $tx] = $this->tapOrder();
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_new', 'transaction' => ['url' => 'https://tap.test/pay']], 200)]);

        $response = TapController::pay($tx);

        $this->assertSame('https://tap.test/pay', $response->getTargetUrl());
        $this->assertSame('chg_new', Transaction::find($tx->id)->details->tap_charge_id);
        Http::assertSent(fn (HttpRequest $r) => $r['redirect']['url'] === route('ipn', ['gateway' => 'tap', 'txn' => $tx->id])
            && $r['reference']['transaction'] === (string) $tx->id
            && $r->hasHeader('Authorization', 'Bearer '.self::STORE_KEY));
    }
}
