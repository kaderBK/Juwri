<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GatewayAdapterController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\GatewayAdapter;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\Refunds\GatewayRefundService;
use Juwri\Modules\Payments\Services\StoreCustomerGateways;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P1-23 part 5 — the MENA gateways (Tap, Paymob, Tabby, Tamara, Geidea, Paylink, noon) moved from hand-written
 * controllers to the GatewayAdapter family so a store's OWN customers' gift cards, store-credit top-ups and deposits are
 * charged, verified and settled on the STORE's account ("customer money goes straight to the store's gateway; juwri
 * never holds it", Terms 4.3) — before, those controllers used the store's keys only for orders, the platform's for
 * everything else. Every gateway runs the same checks (HTTP faked):
 *
 *  - a store payment (gift card) is charged with the store's keys, never the platform's, settles with them, and the
 *    buyer is sent back to the storefront; a platform purchase still uses the platform's keys;
 *  - an amount or currency that differs from the transaction is refused;
 *  - a repeated callback credits once;
 *  - a payment started by the old controller (its stored state, same callback URL) still settles;
 *  - refunds: no provider refund API is wired (the old controllers had none) — nothing is sent to any account and the
 *    refund is recorded as a tracked manual refund in the store's own provider dashboard.
 */
abstract class MenaAdapterTestCase extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @var list<callable(ClientRequest): mixed> latest first */
    private array $answers = [];

    abstract protected function gateway(): string;

    /** @return class-string<GatewayAdapterController> */
    abstract protected function controller(): string;

    /** @return array<string, string> store option suffix (restaurant_<gw>_<suffix>) => value */
    abstract protected function storeCreds(): array;

    /** @return array<string, string> platform config key => value */
    abstract protected function platformCreds(): array;

    /** Whether a provider request was made with the store's (true) or the platform's (false) account. */
    abstract protected function sentWith(ClientRequest $request, bool $store): bool;

    /** Provider answers for initiate (redirect URL + the provider's id for the transaction). */
    abstract protected function answerInitiate(ClientRequest $request): mixed;

    /** The state the OLD controller's pay() left on the transaction (stored provider ids). */
    abstract protected function startedByOldController(Transaction $transaction): void;

    /** The provider confirming $amount $currency for $transaction (fakes + the callback / return request). */
    abstract protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config($this->platformCreds());
        Http::fake(function (ClientRequest $request) {
            foreach ($this->answers as $answer) {
                $response = $answer($request);
                if ($response !== null) {
                    return $response;
                }
            }

            return Http::response(['message' => 'unexpected call'], 404);
        });
        $this->answer(fn (ClientRequest $r) => $this->answerInitiate($r));
    }

    /** @param  callable(ClientRequest): mixed  $answer  null = not mine */
    protected function answer(callable $answer): void
    {
        array_unshift($this->answers, $answer);
    }

    protected function adapter(): GatewayAdapter
    {
        return ($this->controller())::adapter();
    }

    /** The store's (and the payments') currency — the gateway's own when it charges one currency only. */
    protected function currency(): string
    {
        return 'SAR';
    }

    /** Another currency the provider could report (the currency-mismatch check). */
    protected function otherCurrency(): string
    {
        return 'AED';
    }

    /** A store with the gateway switched on with its own keys, in {@see currency()}, gift cards on. */
    protected function storeWithGateway(): Store
    {
        [$store] = $this->makeOrderTransaction($this->gateway(), 1.0, $this->storeCreds(), $this->currency());
        StoreOption::updateStoreOption($store->id, 'currency_code', $this->currency());
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1'); // Tabby/Tamara: the owner's BNPL switch

        return $store;
    }

    protected function giftCard(Store $store, float $amount = 25.0): Transaction
    {
        ['transaction' => $tx] = GiftCardService::initiate((int) $store->id, $amount, $this->gateway(), ['purchaser_email' => 'buyer@example.com']);

        return $tx;
    }

    protected function pay(Transaction $transaction): Response
    {
        request()->merge(['email' => 'diner@example.com', 'phone' => '+966500000000', 'name' => 'Diner']);

        return ($this->controller())::pay($transaction->fresh());
    }

    /** Settled once: the gift card funded exactly once, the transaction paid on this gateway. */
    protected function assertGiftCardSettledOnce(Store $store, Transaction $tx, float $amount = 25.0): void
    {
        $fresh = Transaction::find($tx->id);
        $this->assertSame(Transaction::STATUS_SUCCESS, $fresh->status, 'the gift card payment settles');
        $this->assertSame($this->gateway(), $fresh->transaction_gatway);
        $this->assertEqualsWithDelta($amount, GiftCardService::liabilityFor((int) $store->id), 0.0001, 'funded exactly once');
        $this->assertSame(1, DB::table('outbox_messages')->where('dedupe_key', Settlement::CREDIT.':'.$tx->id)->count());
    }

    protected function assertGiftCardNotSettled(Store $store, Transaction $tx): void
    {
        $this->assertNull(Transaction::find($tx->id)->status, 'still pending');
        $this->assertEqualsWithDelta(0.0, GiftCardService::liabilityFor((int) $store->id), 0.0001, 'not funded');
    }

    // ------------------------------------------------------------------------------------------------ the checks

    public function test_the_gateway_is_an_adapter_and_offered_to_store_customers(): void
    {
        $this->assertTrue(is_subclass_of($this->controller(), GatewayAdapterController::class));
        $this->assertSame($this->controller(), PaymentController::controllerFor($this->gateway()));
        $this->assertArrayHasKey($this->gateway(), StoreCustomerGateways::ADAPTER_FOLDERS);

        $store = $this->storeWithGateway();
        $this->assertSame($this->gateway(), app(CustomerGateways::class)->resolve((int) $store->id, $this->gateway()));
    }

    public function test_a_gift_card_is_charged_and_settled_on_the_store_account_not_the_platform(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $response = $this->pay($tx);

        $this->assertTrue($response->isRedirect(), 'sent to the provider');
        $this->assertStringNotContainsString(url('/'), (string) $response->headers->get('Location'), 'away to the provider');
        Http::assertSent(fn (ClientRequest $r) => $this->sentWith($r, true));
        Http::assertNotSent(fn (ClientRequest $r) => $this->sentWith($r, false));

        $this->confirm($tx->fresh(), '25.00', $this->currency(), true);

        $this->assertGiftCardSettledOnce($store, $tx);
        Http::assertNotSent(fn (ClientRequest $r) => $this->sentWith($r, false));
    }

    public function test_a_store_payment_confirmed_on_the_platform_account_never_settles(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);

        $this->confirm($tx->fresh(), '25.00', $this->currency(), false);

        $this->assertGiftCardNotSettled($store, $tx);
    }

    public function test_a_platform_purchase_still_uses_the_platform_account(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, $this->currency(), 'wallet_topup');

        $this->pay($tx);

        Http::assertSent(fn (ClientRequest $r) => $this->sentWith($r, false));
        Http::assertNotSent(fn (ClientRequest $r) => $this->sentWith($r, true));
    }

    public function test_an_amount_or_currency_mismatch_is_refused(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);

        $this->confirm($tx->fresh(), '24.99', $this->currency(), true);
        $this->assertGiftCardNotSettled($store, $tx);

        $this->confirm($tx->fresh(), '25.00', $this->otherCurrency(), true);
        $this->assertGiftCardNotSettled($store, $tx);
    }

    public function test_a_repeated_callback_credits_once(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);

        $this->confirm($tx->fresh(), '25.00', $this->currency(), true);
        $this->confirm($tx->fresh(), '25.00', $this->currency(), true);

        $this->assertGiftCardSettledOnce($store, $tx);
    }

    public function test_an_order_payment_started_on_the_old_controller_still_settles(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction($this->gateway(), 100.0, $this->storeCreds(), $this->currency());
        $this->startedByOldController($tx); // what the hand-written controller stored at pay time

        $this->confirm($tx->fresh(), '100.00', $this->currency(), true);

        $this->assertSettledOnce($order, $tx);
        Http::assertNotSent(fn (ClientRequest $r) => $this->sentWith($r, false));
    }

    public function test_a_refund_is_never_sent_to_another_account(): void
    {
        [$store, $order, $tx] = $this->makeOrderTransaction($this->gateway(), 100.0, $this->storeCreds(), $this->currency());
        $tx->forceFill(['status' => Transaction::STATUS_CONSUMED, 'transaction_gatway' => $this->gateway()])->save();
        Order::whereKey($order->id)->update(['payment_gateway' => $this->gateway(), 'is_paid' => 1]);
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');

        $this->assertFalse($this->adapter()->supportsRefund());
        $this->assertFalse($this->adapter()->refund($tx->fresh(), 10.0)['ok']);
        $result = GatewayRefundService::refund($order->fresh(), 10.0);

        $this->assertFalse($result['automatic'], 'tracked manual refund in the store\'s own dashboard');
        $this->assertSame($this->gateway(), $result['gateway']);
        Http::assertNothingSent();
    }
}
