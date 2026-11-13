<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Contracts\TransactionFulfiller;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Juwri\Modules\Payments\Support\Fulfilment;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * J25-7: webhook_receipts — one processing per verified (provider, event id); duplicate and concurrent deliveries are
 * 200 no-ops; a failed run is released for the provider's retry; a dead worker's claim is taken over; retention ≥ 7d.
 */
class WebhookReceiptsTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /* ------------------------------------------------------------------ the service */

    public function test_an_event_is_processed_once_and_a_duplicate_gets_the_duplicate_answer(): void
    {
        $runs = 0;
        $first = WebhookReceipts::once('stripe', 'evt_1', function () use (&$runs) {
            $runs++;

            return 'done';
        }, 'dup');
        $second = WebhookReceipts::once('stripe', 'evt_1', function () use (&$runs) {
            $runs++;

            return 'done';
        }, 'dup');

        $this->assertSame(['done', 'dup', 1], [$first, $second, $runs]);
        $this->assertTrue(WebhookReceipts::processed('stripe', 'evt_1'));
        // Same event id at another provider is another event.
        $this->assertSame('done', WebhookReceipts::once('paddle', 'evt_1', fn () => 'done'));
    }

    public function test_a_failed_run_is_released_so_the_retry_processes_it(): void
    {
        try {
            WebhookReceipts::once('stripe', 'evt_fail', fn () => throw new \RuntimeException('boom'));
            $this->fail('the exception must reach the gateway handler');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(0, DB::table('webhook_receipts')->count());

        // An error answer (4xx/5xx) releases too.
        WebhookReceipts::once('stripe', 'evt_fail', fn () => response('settlement failed', 500));
        $this->assertSame(0, DB::table('webhook_receipts')->count());

        $this->assertSame('ok', WebhookReceipts::once('stripe', 'evt_fail', fn () => 'ok'));
        $this->assertTrue(WebhookReceipts::processed('stripe', 'evt_fail'));
    }

    /** P6-139: the copy is not processed AND not acknowledged — 503 + Retry-After keeps the gateway retrying in case the first run was killed. */
    public function test_a_concurrent_delivery_is_a_no_op_while_the_first_is_processing(): void
    {
        // The first delivery has claimed the event and is still running (its claim is fresh).
        $this->assertNotNull(WebhookReceipts::claim('stripe', 'evt_busy'));

        $ran = false;
        $answer = WebhookReceipts::once('stripe', 'evt_busy', function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertSame(200, $answer->getStatusCode(), 'outside a server-to-server POST (a GET / browser return): the old no-op answer');

        $this->app->instance('request', Request::create('/webhook/stripe', 'POST'));
        $answer = WebhookReceipts::once('stripe', 'evt_busy', function () use (&$ran) {
            $ran = true;
        });
        $this->assertFalse($ran);
        $this->assertSame(503, $answer->getStatusCode());
        $this->assertSame('60', $answer->headers->get('Retry-After'));
    }

    public function test_a_dead_workers_claim_is_taken_over_once(): void
    {
        $this->assertNotNull(WebhookReceipts::claim('stripe', 'evt_stale'));
        DB::table('webhook_receipts')->update(['claimed_at' => now()->subMinutes(WebhookReceipts::STALE_AFTER_MINUTES + 1)]);

        $token = WebhookReceipts::claim('stripe', 'evt_stale');
        $this->assertNotNull($token);
        $this->assertNull(WebhookReceipts::claim('stripe', 'evt_stale'), 'the taken-over claim is fresh again');
        $this->assertSame(2, (int) DB::table('webhook_receipts')->value('attempts'));
    }

    public function test_keys_come_only_from_scalar_parts_and_long_ids_fit(): void
    {
        $this->assertSame('tr_1:paid', WebhookReceipts::key('tr_1', 'paid'));
        $this->assertSame('7:SUCCESS', WebhookReceipts::key(7, 'SUCCESS'));
        $this->assertNull(WebhookReceipts::key('tr_1', null));
        $this->assertNull(WebhookReceipts::key(['x'], 'paid'));
        $this->assertNull(WebhookReceipts::key(true));
        $this->assertNull(WebhookReceipts::key(''));

        // No key → processed without a receipt (settlement is still claimed per transaction downstream).
        $this->assertSame('ran', WebhookReceipts::once('stripe', null, fn () => 'ran'));
        $this->assertSame(0, DB::table('webhook_receipts')->count());

        $long = str_repeat('x', 400);
        WebhookReceipts::once('stripe', $long, fn () => null);
        $this->assertTrue(WebhookReceipts::processed('stripe', $long));
        $this->assertStringStartsWith('sha256:', (string) DB::table('webhook_receipts')->value('event_id'));
    }

    public function test_receipts_are_kept_at_least_seven_days_and_pruned_daily(): void
    {
        foreach (['old' => 31, 'week' => 6, 'new' => 0] as $id => $days) {
            WebhookReceipts::once('stripe', $id, fn () => null);
            DB::table('webhook_receipts')->where('event_id', $id)->update(['created_at' => now()->subDays($days)]);
        }

        $this->artisan('payments:prune-webhooks', ['--days' => 1])->assertSuccessful(); // clamped to 7
        $this->assertSame(['new', 'week'], DB::table('webhook_receipts')->orderBy('event_id')->pluck('event_id')->all());

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
        $this->assertStringContainsString('payments:prune-webhooks', $events);
        $this->assertStringContainsString('payments:outbox-relay', $events);
    }

    /* ------------------------------------------------------------------ a gateway end to end (Stripe) */

    private function stripeSetUp(): void
    {
        PaymentGateway::create(['payment_folder' => 'stripe', 'payment_title' => 'Stripe', 'payment_install' => '1']);
        config(['settings.stripe_secret_key' => 'sk_test_fake_platform', 'settings.stripe_webhook_secret' => 'whsec_fake', 'settings.currency_code' => 'USD']);
    }

    private function postStripe(string $payload): TestResponse
    {
        $t = time();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, 'whsec_fake')];

        return $this->call('POST', route('webhook', 'stripe'), [], [], [], $server, $payload);
    }

    private function sessionEvent(string $eventId, string $sessionId): string
    {
        return json_encode(['id' => $eventId, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $sessionId, 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'status' => 'complete',
            'amount_total' => 2000, 'currency' => 'usd', 'payment_intent' => 'pi_fake_1',
        ]]]);
    }

    /** The receipt key the Stripe adapter uses: the signed event id + the request fingerprint. */
    private function stripeKey(string $eventId, string $payload): string
    {
        return $eventId.':'.WebhookReceipts::fingerprint(Request::create('/webhook/stripe', 'POST', content: $payload));
    }

    public function test_the_same_stripe_event_twice_settles_once_and_the_copy_is_a_200_no_op(): void
    {
        $this->stripeSetUp();
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 20.0, ['secret_key' => 'sk_test_fake_store'], 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $tx->update(['payment_id' => 'cs_dup_1']);
        $payload = $this->sessionEvent('evt_dup_1', 'cs_dup_1');

        $this->postStripe($payload)->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSettledOnce($order, $tx);
        StoreOption::flushOptionMemo();
        $wallet = StoreOption::getStoreOption($store->id, 'wallet_amount');

        $this->postStripe($payload)->assertOk()->assertJson(['message' => 'duplicate']);
        $this->assertSettledOnce($order, $tx);
        StoreOption::flushOptionMemo();
        $this->assertSame($wallet, StoreOption::getStoreOption($store->id, 'wallet_amount'), 'credited once');
        $this->assertTrue(WebhookReceipts::processed('stripe', $this->stripeKey('evt_dup_1', $payload)));
    }

    public function test_two_concurrent_stripe_deliveries_one_wins_the_other_is_told_to_retry(): void
    {
        $this->stripeSetUp();
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 20.0, ['secret_key' => 'sk_test_fake_store'], 'USD');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');
        $tx->update(['payment_id' => 'cs_conc_1']);
        $payload = $this->sessionEvent('evt_conc_1', 'cs_conc_1');

        // Delivery A has claimed the event and is mid-way; delivery B arrives now.
        WebhookReceipts::claim('stripe', $this->stripeKey('evt_conc_1', $payload));
        $this->postStripe($payload)->assertStatus(503)->assertHeader('Retry-After', '60'); // P6-139: never a 2xx while in flight
        $this->assertNotSettled($order, $tx);

        // A dies without finishing; Stripe's retry after the stale window takes the event over and settles it.
        DB::table('webhook_receipts')->update(['claimed_at' => now()->subMinutes(WebhookReceipts::STALE_AFTER_MINUTES + 1)]);
        $this->postStripe($payload)->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSettledOnce($order, $tx);
    }

    public function test_an_unsigned_request_never_writes_a_receipt(): void
    {
        $this->stripeSetUp();
        $payload = $this->sessionEvent('evt_forged', 'cs_x');
        $this->call('POST', route('webhook', 'stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad'], $payload)->assertStatus(400);

        $this->assertSame(0, DB::table('webhook_receipts')->count(), 'a forged copy must not spend the genuine event id');
    }

    /* ------------------------------------------------------------------ a re-fetch gateway (Telr) */

    private function telrOrder(): array
    {
        PaymentGateway::create(['payment_folder' => 'telr', 'payment_title' => 'Telr', 'payment_install' => '1']);
        config(['settings.telr_store_id' => '10001', 'settings.telr_authkey' => 'fake-platform-authkey', 'settings.telr_sandbox_mode' => 'live', 'settings.currency_code' => 'AED']);
        [$store, $order, $tx] = $this->makeOrderTransaction('telr', 12.345, ['store_id' => '20002', 'authkey' => 'fake-store-authkey'], 'BHD');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'BHD');
        $tx->update(['payment_id' => 'REF'.$tx->id]);

        return [$order, $tx];
    }

    private function telrCheck(Transaction $tx, int $code): array
    {
        return ['method' => 'check', 'order' => ['ref' => 'REF'.$tx->id, 'cartid' => (string) $tx->id, 'amount' => '12.345', 'currency' => 'BHD',
            'status' => ['code' => $code, 'text' => $code === 3 ? 'Paid' : 'Pending']]];
    }

    public function test_a_re_fetch_gateway_never_drops_a_copy_that_arrives_before_the_provider_confirms(): void
    {
        [$order, $tx] = $this->telrOrder();
        Http::fake(['secure.telr.com/gateway/order.json' => Http::sequence()
            ->push($this->telrCheck($tx, 1))   // the notification arrives before Telr reports the cart paid
            ->push($this->telrCheck($tx, 3))]);

        $this->post(route('webhook', 'telr'), ['tran_cartid' => (string) $tx->id])->assertOk()->assertJson(['message' => 'not paid']);
        $this->assertNotSettled($order, $tx);
        $this->assertSame(0, DB::table('webhook_receipts')->count(), 'nothing confirmed, nothing claimed');

        $this->post(route('webhook', 'telr'), ['tran_cartid' => (string) $tx->id])->assertOk()->assertJson(['message' => 'successful']);
        $this->assertSettledOnce($order, $tx);
        $this->assertTrue(WebhookReceipts::processed('telr', $tx->id.':paid'));
    }

    public function test_a_re_fetch_gateway_settles_once_when_two_confirmations_race(): void
    {
        [$order, $tx] = $this->telrOrder();
        Http::fake(['secure.telr.com/gateway/order.json' => Http::response($this->telrCheck($tx, 3))]);

        // The other confirmation (a concurrent copy) holds the claim and is settling right now.
        WebhookReceipts::claim('telr', $tx->id.':paid');
        $this->post(route('webhook', 'telr'), ['tran_cartid' => (string) $tx->id])->assertStatus(503); // P6-139: retry later
        $this->assertNotSettled($order, $tx);
    }

    /* ------------------------------------------------------------------ Fulfilment::webhook (bug fix) */

    /**
     * Bug (J25-7): CheckoutController::processWebhook dedupes a subscription/MoR payment with exists() then insert —
     * two concurrent deliveries of one renewal could both pass and both extend; a sequential duplicate was answered
     * 400, so the provider kept retrying. Now the (gateway, payment id) receipt lets exactly one through and answers
     * the copy 200.
     */
    public function test_fulfilment_webhook_runs_the_fulfiller_once_per_payment(): void
    {
        $fulfiller = new class implements TransactionFulfiller
        {
            public int $webhooks = 0;

            public function fulfil(Transaction $transaction): mixed
            {
                return null;
            }

            public function webhook(string $gateway, mixed $metadata, mixed $paymentId, mixed $subscriptionId): mixed
            {
                $this->webhooks++;

                return response()->json(['message' => 'successful', 'status' => 200]);
            }
        };
        $this->app->instance(TransactionFulfiller::class, $fulfiller);

        $this->assertSame(200, Fulfilment::webhook('paddle', (object) [], 'txn_r1', 'sub_1')->getStatusCode());
        $copy = Fulfilment::webhook('paddle', (object) [], 'txn_r1', 'sub_1');
        $this->assertSame([200, 'duplicate'], [$copy->getStatusCode(), $copy->getData(true)['message']]);

        // A concurrent copy (the first still running) never reaches the fulfiller either.
        WebhookReceipts::claim('paddle', 'payment:txn_r2');
        $this->app->instance('request', Request::create('/webhook/paddle', 'POST')); // server-to-server
        $this->assertSame(503, Fulfilment::webhook('paddle', (object) [], 'txn_r2', 'sub_1')->getStatusCode());

        // The next renewal is a new payment id.
        Fulfilment::webhook('paddle', (object) [], 'txn_r3', 'sub_1');
        $this->assertSame(2, $fulfiller->webhooks);
    }
}
