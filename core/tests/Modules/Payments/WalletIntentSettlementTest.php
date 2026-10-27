<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Juwri\Modules\Payments\Services\LedgerService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Apple/Google Pay (Stripe Payment Request Button, WalletPaymentController::intent). Before: the intent was created
 * and the card charged, but NOTHING settled it — no webhook handled payment_intent.succeeded, the client only
 * reloaded the page — so the order stayed unpaid. Now every intent is backed by a pending order transaction that the
 * signed `payment_intent.succeeded` webhook settles through the Stripe exact-amount path (the rounded-down step for a
 * 3-decimal amount between steps, GatewayRounding booked), once.
 */
class WalletIntentSettlementTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public static array $stripeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        PaymentGateway::firstOrCreate(['payment_folder' => 'stripe'], ['payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_webhook_secret' => 'whsec_fake']);
        self::$stripeCalls = [];
        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                WalletIntentSettlementTest::$stripeCalls[] = $params;
                $n = count(WalletIntentSettlementTest::$stripeCalls);

                return [json_encode(['id' => 'pi_w'.$n, 'object' => 'payment_intent', 'client_secret' => 'pi_w'.$n.'_secret',
                    'amount' => $params['amount'] ?? 0, 'currency' => $params['currency'] ?? 'usd']), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** @return array{0: Store, 1: Order} */
    private function order(string $currency, float $tip): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'x',
            'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_publishable_key', 'pk_test_x');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'tip_amount' => $tip, 'currency_code' => $currency]);

        return [$store, $order];
    }

    /** @param  array<string, mixed>  $intent */
    private function webhook(array $intent): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(8), 'type' => 'payment_intent.succeeded', 'data' => ['object' => $intent]]);
        $t = time();

        return $this->call('POST', route('webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_fake')], $payload);
    }

    /** @return array<string, mixed> */
    private function succeeded(string $id, int $amount, string $currency, ?int $txId): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount,
            'currency' => $currency, 'metadata' => array_filter(['transaction_id' => $txId !== null ? (string) $txId : null])];
    }

    public function test_the_intent_creates_a_pending_transaction_and_the_signed_webhook_settles_it_once(): void
    {
        [$store, $order] = $this->order('KWD', 3.375);

        $res = $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])->assertOk();
        $this->assertSame(3370, $res->json('amount_minor')); // rounded DOWN to Stripe's step (before: 3380)
        $tx = Transaction::where('payment_id', 'pi_w1')->sole();
        $this->assertNull($tx->status);
        $this->assertSame([(int) $order->id, (int) $store->id, 'order', 'KWD'], [(int) $tx->product_id, (int) $tx->store_id, $tx->transaction_method, $tx->currency_code]);
        $this->assertSame('3.375', $tx->money('amount')->major());
        $this->assertSame((string) $tx->id, self::$stripeCalls[0]['metadata']['transaction_id']);

        $this->webhook($this->succeeded('pi_w1', 3370, 'kwd', $tx->id))->assertOk();

        $this->assertSame(1, (int) $order->fresh()->is_paid); // before: 0 — nothing settled wallet payments
        $this->assertSame(Transaction::STATUS_CONSUMED, $tx->fresh()->status);
        $this->assertSame('pi_w1', $tx->fresh()->payment_id);
        $writeOff = LedgerAccount::where(['owner_type' => 'restaurant', 'owner_id' => $store->id, 'kind' => 'payment_rounding', 'currency' => 'KWD'])->sole();
        $this->assertSame(-0.005, LedgerService::balanceFor($writeOff));
        $this->assertNotNull(GatewayRounding::recorded($tx->fresh())['ledger_tx']);

        // Replay: nothing pending under that intent any more — no second credit, no second booking.
        $wallet = (float) store_options($store->id, 'wallet_amount');
        $this->webhook($this->succeeded('pi_w1', 3370, 'kwd', $tx->id))->assertOk();
        $this->assertSame($wallet, (float) store_options($store->id, 'wallet_amount'));
        $this->assertSame(-0.005, LedgerService::balanceFor($writeOff));
    }

    public function test_with_payment_sandboxes_off_a_test_key_opens_no_wallet_intent(): void
    {
        // Phase 4 item 18: the Apple/Google Pay intent follows the same flag as the Stripe checkout.
        config(['payments.sandbox.enabled' => false]);
        [$store, $order] = $this->order('USD', 0.0);

        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])->assertStatus(503);
        $this->assertSame([], self::$stripeCalls);
        $this->assertSame(0, Transaction::where('store_id', $store->id)->count());
    }

    public function test_a_wrong_amount_currency_transaction_or_status_never_settles(): void
    {
        [$store, $order] = $this->order('KWD', 3.375);
        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])->assertOk();
        $tx = Transaction::where('payment_id', 'pi_w1')->sole();

        $this->webhook($this->succeeded('pi_w1', 3380, 'kwd', $tx->id))->assertOk(); // more than we asked for
        $this->webhook($this->succeeded('pi_w1', 3375, 'kwd', $tx->id))->assertOk(); // the unrounded total
        $this->webhook($this->succeeded('pi_w1', 3370, 'usd', $tx->id))->assertOk(); // another currency
        $this->webhook($this->succeeded('pi_w1', 3370, 'kwd', $tx->id + 999))->assertOk(); // another transaction
        $this->webhook(['status' => 'processing'] + $this->succeeded('pi_w1', 3370, 'kwd', $tx->id))->assertOk();
        $this->assertNull($tx->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);

        // Unsigned: refused outright.
        $this->call('POST', route('webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => $this->succeeded('pi_w1', 3370, 'kwd', $tx->id)]]))->assertStatus(400);
        $this->assertNull($tx->fresh()->status);
    }

    public function test_a_two_decimal_order_is_charged_and_settled_exactly(): void
    {
        [$store, $order] = $this->order('USD', 12.5);
        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])->assertOk()->assertJsonPath('amount_minor', 1250);
        $tx = Transaction::where('payment_id', 'pi_w1')->sole();

        $this->webhook($this->succeeded('pi_w1', 1249, 'usd', $tx->id))->assertOk();
        $this->assertNull($tx->fresh()->status);
        $this->webhook($this->succeeded('pi_w1', 1250, 'usd', $tx->id))->assertOk();
        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertNull(GatewayRounding::recorded($tx->fresh()));
    }
}
