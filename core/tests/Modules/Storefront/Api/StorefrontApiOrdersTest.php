<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreTable;
use App\Services\CustomerWalletService;
use App\Services\OrderApiService;
use App\Services\Storefront\StorefrontMenuCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Marketplace\PlatformWalletTender;
use Juwri\Core\Editions\Community\NoPlatformWallet;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Ordering\Services\Payments\LatePayments;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderPlacement;
use Tests\Support\StorefrontApi\ApiContract;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-06b phase A — `POST /orders` (new body) and `GET /orders/{track_token}`: idempotency (key + request hash, the
 * race), quote parity (quote total == order total, 409 quote_changed), payment modes, the 15-minute hosted window
 * and its sweep, and late payments (API-21/27/28/32). Every answer is checked against the contract.
 */
class StorefrontApiOrdersTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 18:40:00', 'UTC'));
        $this->fx = new ApiFixtures;
        $this->fx->saffron();
    }

    /** @return array<string, mixed> */
    private function cart(int $qty = 1): array
    {
        return ['order_type' => 'takeaway', 'lines' => [
            ['line_id' => 'ln_1', 'item_id' => ApiFixtures::GRILL, 'qty' => $qty, 'option_ids' => [ApiFixtures::OPT_FOR_ONE, ApiFixtures::OPT_GARLIC]],
            ['line_id' => 'ln_2', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1],
        ]];
    }

    /** @param  array<string, mixed>  $body */
    private function quote(array $body)
    {
        $r = $this->postJson('/api/v1/storefront/quote', $body, ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001']);
        ApiContract::assertResponse('quoteCart', 'POST', '/api/v1/storefront/quote', $r);

        return $r;
    }

    /** @param  array<string, mixed>  $body */
    private function submit(array $body, ?string $key = null)
    {
        $r = $this->postJson('/api/v1/storefront/orders', $body, array_filter([
            'X-Storefront-Token' => 'sfx_demo_saffron_write_0001', 'Origin' => 'https://saffron-house.example',
            'Idempotency-Key' => $key ?? (string) Str::uuid7(),
        ]));
        ApiContract::assertResponse('createOrder', 'POST', '/api/v1/storefront/orders', $r);

        return $r;
    }

    /** @param  array<string, mixed>  $payment */
    private function placed(array $payment = ['mode' => 'pay_later'], ?string $key = null)
    {
        $q = $this->quote($this->cart())->assertOk();

        return $this->submit($this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => $payment], $key);
    }

    private function orderStatus(string $track)
    {
        $r = $this->getJson('/api/v1/storefront/orders/'.$track, ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001']);
        ApiContract::assertResponse('getOrder', 'GET', '/api/v1/storefront/orders/'.$track, $r);

        return $r;
    }

    public function test_quote_total_equals_the_placed_orders_total(): void
    {
        $q = $this->quote($this->cart() + ['coupon' => 'WELCOME10', 'tip' => ['minor' => 300]])->assertOk();
        $r = $this->submit($this->cart() + ['coupon' => 'WELCOME10', 'tip' => ['minor' => 300], 'quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later', 'method_id' => 'cash']])
            ->assertCreated()->assertJsonPath('payment.status', 'pay_later')->assertJsonPath('order.status', 'placed');

        $order = Order::query()->where('public_id', $r->json('order.public_id'))->firstOrFail();
        $this->assertSame($q->json('quote.total.minor'), (int) $order->grand_total_minor);
        $this->assertSame($q->json('quote.total'), $r->json('quote.total'));
        $this->assertSame('storefront_api', $order->source);
        $this->assertSame('pay_later', $order->payment_mode);
        $this->assertSame(['ln_1', 'ln_2'], array_column($q->json('quote.lines'), 'id'), 'the client line ids are echoed');
    }

    public function test_a_changed_total_answers_409_quote_changed_with_the_new_quote(): void
    {
        $q = $this->quote($this->cart())->assertOk();
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->firstOrFail()->update(['price' => 45]);
        StorefrontMenuCache::bust((int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('store_id'));

        $r = $this->submit($this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])
            ->assertStatus(409)->assertJsonPath('code', 'quote_changed');
        $this->assertSame($q->json('quote.total.minor') + 300 + 45, $r->json('details.quote.quote.total.minor'), '+3.00 and its 15% VAT');
        $this->assertSame(0, Order::query()->count());
        // the new hash places the order
        $this->submit($this->cart() + ['quote_hash' => $r->json('details.quote.quote_hash'), 'payment' => ['mode' => 'pay_later']])->assertCreated();
    }

    public function test_idempotency_key_is_required_and_must_be_a_uuidv7(): void
    {
        $q = $this->quote($this->cart())->assertOk();
        $body = $this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']];
        $this->postJson('/api/v1/storefront/orders', $body, ['X-Storefront-Token' => 'sfx_demo_saffron_write_0001', 'Origin' => 'https://saffron-house.example'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->submit($body, (string) Str::uuid())->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_same_key_same_body_replays_and_another_body_conflicts(): void
    {
        $key = (string) Str::uuid7();
        $first = $this->placed(['mode' => 'pay_later'], $key)->assertCreated();
        $again = $this->placed(['mode' => 'pay_later'], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('replayed', true);
        $this->assertSame($first->json('order.public_id'), $again->json('order.public_id'));

        $q = $this->quote($this->cart(2))->assertOk();
        $this->submit($this->cart(2) + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']], $key)
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The race: two submits with one key both pass "is the key used?" (the placement's check and OrderApiService's
     * replay check) before either inserts; the second INSERT loses on the unique (store_id, client_uuid) index. The
     * twin is committed right after the loser's second check (a query listener on that SELECT): the loser then
     * answers the winner's order as a replay — or 409 when the winner came from another body — never a second order.
     */
    public function test_a_concurrent_submit_with_the_same_key_replays_the_winner_or_conflicts(): void
    {
        $store = Store::query()->where('slug', 'saffron-house-demo')->firstOrFail();
        $armed = null;
        DB::listen(function ($query) use (&$armed, $store) {
            if ($armed === null || ! str_contains($query->sql, 'client_uuid') || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! in_array($armed['key'], $query->bindings, true)) {
                return;
            }
            if (++$armed['seen'] < 2) {
                return; // the placement's own check; the twin commits after OrderApiService's replay check
            }
            $twin = $armed;
            $armed = null;
            $winner = OrderApiService::create((int) $store->user_id, (int) $store->id, [
                'type' => 'takeaway', 'items' => [['item_id' => Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('id'), 'quantity' => 1]],
                'source' => 'storefront_api',
            ]);
            Order::query()->whereKey($winner->id)->update(['client_uuid' => $twin['key'], 'client_order_hash' => $twin['hash']]);
        });

        foreach (['same body' => false, 'other body' => true] as $label => $otherBody) {
            $key = (string) Str::uuid7();
            $q = $this->quote($this->cart())->assertOk();
            $body = $this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']];
            $hash = StorefrontOrderPlacement::requestHash($otherBody ? ['other' => true] : $body);
            $armed = ['key' => $key, 'hash' => $hash, 'seen' => 0];

            $r = $this->submit($body, $key);
            $this->assertNull($armed, "{$label}: the twin was committed mid-request");
            if ($otherBody) {
                $r->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
            } else {
                $r->assertCreated()->assertJsonPath('replayed', true);
            }
            $this->assertSame(1, Order::query()->where('client_uuid', $key)->count(), "{$label}: one order under the key");
        }
    }

    public function test_hosted_order_waits_unpaid_with_a_15_minute_window_then_is_cancelled_and_restocked(): void
    {
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['track_inventory' => true, 'stock_qty' => 5]);
        $r = $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/thanks'])->assertCreated()
            ->assertJsonPath('order.status', 'pending_payment')->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.next_action.type', 'redirect');
        $created = Carbon::parse('2026-10-02 18:40:00', 'UTC');
        $this->assertTrue(Carbon::parse($r->json('payment.next_action.expires_at'))->equalTo($created->copy()->addMinutes(15)), 'expires_at = creation + 15 minutes (API-10)');
        $order = Order::query()->where('public_id', $r->json('order.public_id'))->firstOrFail();
        $this->assertSame('unpaid', $order->status);
        $this->assertSame(4, (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('stock_qty'), 'stock is reserved while the window is open');

        // the hosted page is the existing pay page of the order's payment
        $tx = app(HostedOrderPayments::class)->transactionFor($order);
        $this->assertNotNull($tx);
        $this->assertStringEndsWith('/'.$tx->id, (string) $r->json('payment.next_action.url'));
        $this->assertSame('https://saffron-house.example/thanks', $tx->details->return_url);

        // 14 minutes: still open
        $this->travel(14)->minutes();
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $this->assertSame('unpaid', $order->fresh()->status);
        $this->orderStatus($order->track_token)->assertJsonPath('payment.status', 'pending');

        // 16 minutes: the sweep cancels it, the stock comes back, the payment failed
        $this->travel(2)->minutes();
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(5, (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('stock_qty'));
        $this->orderStatus($order->track_token)->assertJsonPath('order.status', 'cancelled')->assertJsonPath('payment.status', 'failed')->assertJsonPath('payment.next_action', null);

        // the pay page no longer takes payment
        $this->get('/payment/'.$tx->id)->assertStatus(410);
    }

    public function test_reading_an_expired_window_closes_it_without_waiting_for_the_sweep(): void
    {
        $r = $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/back'])->assertCreated();
        $this->travel(16)->minutes();
        $this->orderStatus((string) $r->json('order.track_token'))->assertJsonPath('order.status', 'cancelled')->assertJsonPath('payment.status', 'failed');
    }

    public function test_paid_inside_the_window_the_order_is_placed_and_reaches_the_kitchen(): void
    {
        $r = $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/back'])->assertCreated();
        $order = Order::query()->where('public_id', $r->json('order.public_id'))->firstOrFail();
        $tx = app(HostedOrderPayments::class)->transactionFor($order);
        $tx->forceFill(['transaction_gatway' => 'stripe'])->save();
        $this->travel(5)->minutes();

        ob_start();
        PaymentController::paySuccess($tx->fresh());
        $script = (string) ob_get_clean();
        $this->assertStringContainsString('"https://saffron-house.example/back"', $script, 'the shopper goes back to the return_url');

        $fresh = $order->fresh();
        $this->assertSame(1, (int) $fresh->is_paid);
        $this->assertNotSame('unpaid', $fresh->status);
        $this->orderStatus($order->track_token)->assertJsonPath('order.status', 'placed')->assertJsonPath('payment.status', 'paid');

        // later sweeps never touch it
        $this->travel(20)->minutes();
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $this->assertSame((int) 1, (int) $order->fresh()->is_paid);
        $this->assertNotSame('cancelled', $order->fresh()->status);
    }

    /**
     * P6-103: accepting a late payment re-checks the stock (a read) and then takes it. A shopper who buys the last unit
     * in between used to be overridden — the take was the unconditional deductStock, so stock went to -1 and two
     * customers were promised one unit. Now the take is the conditional reserve: it loses, nothing is taken, and the
     * money waits for the owner (pending_approval) exactly as when the stock was already gone.
     */
    public function test_p6_103_a_shopper_taking_the_last_unit_during_a_late_accept_wins_and_stock_never_goes_negative(): void
    {
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['track_inventory' => true, 'stock_qty' => 1]);
        $track = (string) $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/back'])->assertCreated()->json('order.track_token');
        $this->travel(16)->minutes();
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $order = Order::query()->where('track_token', $track)->firstOrFail();
        $tx = app(HostedOrderPayments::class)->transactionFor($order);
        $tx->forceFill(['transaction_gatway' => 'stripe'])->save();
        ob_start();
        PaymentController::paySuccess($tx->fresh());
        ob_end_clean();
        $this->assertSame(1, (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('stock_qty'));

        // the other shopper's checkout lands right after accept() has read "1 left"
        $raced = false;
        Menu::retrieved(function (Menu $m) use (&$raced) {
            if (! $raced && $m->public_id === ApiFixtures::TAWOOK) {
                $raced = true;
                $this->assertTrue(Menu::reserveStock((int) $m->id, 1), 'the other shopper takes the last unit');
            }
        });

        $this->assertSame('pending_approval', app(LatePayments::class)->accept($order->fresh()));
        $this->assertTrue($raced);
        $this->assertSame(0, (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('stock_qty'), 'never -1');
        $this->assertNotNull($order->fresh()->stock_restored_at, 'nothing was taken for the late order');
        $this->orderStatus($track)->assertJsonPath('order.status', 'pending_approval');
    }

    /**
     * money-last 1: the expiry cancel gave the store-credit part back, so the late card payment alone no longer covers
     * the order. Accepting it never places the order short: it waits as pending_approval and the owner is told the gap.
     */
    public function test_a_late_payment_whose_tenders_were_returned_waits_for_the_owner(): void
    {
        $track = (string) $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/back'])->assertCreated()->json('order.track_token');
        $order = Order::query()->where('track_token', $track)->firstOrFail();
        $customer = Customer::create(['name' => 'Noura', 'store_id' => $order->store_id, 'phone' => '+966551230090']);
        $order->forceFill(['wallet_applied' => 5.00, 'customer_id' => $customer->id])->saveQuietly();
        $tx = app(HostedOrderPayments::class)->transactionFor($order);
        $tx->forceFill(['transaction_gatway' => 'stripe', 'amount' => (float) $tx->amount - 5.0])->save(); // the card part
        $this->travel(16)->minutes();
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $this->assertSame(5.0, CustomerWalletService::balanceFor($customer), 'the expiry cancel gave the store credit back');
        ob_start();
        PaymentController::paySuccess($tx->fresh());
        ob_end_clean();

        $this->assertSame('pending_approval', app(LatePayments::class)->accept($order->fresh()));
        $this->assertSame(Order::STATUS_PENDING_APPROVAL, $order->fresh()->status);
        $logged = ActivityLog::query()->where('action', 'order.late_tenders_returned')->get();
        $this->assertCount(1, $logged);
        $this->assertSame(5.0, (float) $logged[0]->meta['short']);
    }

    /**
     * ML-1120 review SHOULD 2: the expiry cancel returns the tenders inside its own transaction. When the return fails
     * the cancel rolls back (the order stays unpaid with its tenders, so a late payment can never be placed while they
     * are given back after it), the failure is logged, and the next sweep cancels and returns them.
     */
    public function test_a_failing_tender_return_rolls_the_expiry_cancel_back_and_the_next_sweep_retries(): void
    {
        $track = (string) $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/back'])->assertCreated()->json('order.track_token');
        $order = Order::query()->where('track_token', $track)->firstOrFail();
        $customer = Customer::create(['name' => 'Noura', 'store_id' => $order->store_id, 'phone' => '+966551230091']);
        $order->forceFill(['wallet_applied' => 5.00, 'customer_id' => $customer->id])->saveQuietly();
        $this->app->instance(PlatformWalletTender::class, new class implements PlatformWalletTender
        {
            public function returnPart(Order $order, float $amount, string $key): bool
            {
                return false;
            }

            public function reverseOrder(Order $order, float $keep = 0.0): float
            {
                throw new \RuntimeException('wallet service down');
            }
        });
        $this->travel(16)->minutes();

        $this->assertFalse(app(HostedOrderPayments::class)->expire($order->fresh()));
        $this->assertSame('unpaid', $order->fresh()->status, 'the cancel rolled back with the failed return');
        $this->assertEqualsWithDelta(5.0, (float) $order->fresh()->wallet_applied, 0.001);
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer), 0.001, 'all tenders or none');
        $this->assertSame('tenders', ActivityLog::query()->where('action', 'order.cancel_step_failed')->firstOrFail()->meta['step']);

        $this->app->instance(PlatformWalletTender::class, new NoPlatformWallet);
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta(5.0, CustomerWalletService::balanceFor($customer->fresh()), 0.001, 'the retry gave the store credit back');
        $this->assertSame(500, (int) $order->fresh()->getAttribute('tenders_returned_minor'));
    }

    /**
     * ML-1120 delta NIT 3: an expiry cancel whose tender return keeps failing is counted per order, and on the third
     * failed try the platform admin gets one alert (the existing PlatformAlert channel) — not one per sweep.
     */
    public function test_an_expiry_cancel_that_keeps_failing_alerts_the_admin_once(): void
    {
        $track = (string) $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/back'])->assertCreated()->json('order.track_token');
        $order = Order::query()->where('track_token', $track)->firstOrFail();
        $customer = Customer::create(['name' => 'Noura', 'store_id' => $order->store_id, 'phone' => '+966551230092']);
        $order->forceFill(['wallet_applied' => 5.00, 'customer_id' => $customer->id])->saveQuietly();
        $this->app->instance(PlatformWalletTender::class, new class implements PlatformWalletTender
        {
            public function returnPart(Order $order, float $amount, string $key): bool
            {
                return false;
            }

            public function reverseOrder(Order $order, float $keep = 0.0): float
            {
                throw new \RuntimeException('wallet service down');
            }
        });
        $this->travel(16)->minutes();

        foreach (range(1, 5) as $sweep) {
            $this->assertFalse(app(HostedOrderPayments::class)->expire($order->fresh()));
            $this->assertSame($sweep >= 3 ? 1 : 0, PlatformAlert::query()->where('kind', 'order')->count(), "after sweep {$sweep}");
        }
        $this->assertSame([1, 2, 3, 4, 5], ActivityLog::query()->where('action', 'order.cancel_step_failed')->orderBy('id')->get()
            ->map(fn ($row) => (int) $row->meta['attempt'])->all());
        $this->assertStringContainsString('#'.$order->id, (string) PlatformAlert::query()->where('kind', 'order')->value('message'));
        $this->assertSame('unpaid', $order->fresh()->status);
    }

    public function test_a_late_payment_never_revives_the_order_and_the_owner_accepts_or_refunds_it(): void
    {
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['track_inventory' => true, 'stock_qty' => 5]);
        $tracks = [];
        foreach (['accept-free', 'accept-taken', 'refund'] as $case) {
            $r = $this->placed(['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/back'])->assertCreated();
            $tracks[$case] = (string) $r->json('order.track_token');
        }
        $this->travel(16)->minutes();
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $this->assertSame(5, (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('stock_qty'));

        foreach ($tracks as $track) {
            $order = Order::query()->where('track_token', $track)->firstOrFail();
            $tx = app(HostedOrderPayments::class)->transactionFor($order);
            $tx->forceFill(['transaction_gatway' => 'stripe'])->save();
            ob_start();
            PaymentController::paySuccess($tx->fresh());
            ob_end_clean();
            $this->assertSame('cancelled', $order->fresh()->status, 'API-21: not revived');
            $this->assertSame(0, (int) $order->fresh()->is_paid);
            $this->orderStatus($track)->assertJsonPath('order.status', 'cancelled')->assertJsonPath('payment.status', 'received_late')
                ->assertJsonPath('payment.next_action', null);
        }
        $this->assertSame(5, (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('stock_qty'), 'a late payment takes no stock');

        $late = app(LatePayments::class);
        // stock still free → placed, paid, stock taken again (API-27)
        $free = Order::query()->where('track_token', $tracks['accept-free'])->firstOrFail();
        $this->assertSame('placed', $late->accept($free));
        $this->assertSame(4, (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('stock_qty'));
        $this->orderStatus($tracks['accept-free'])->assertJsonPath('order.status', 'placed')->assertJsonPath('payment.status', 'paid');
        $this->assertNull($late->accept($free->fresh()), 'accepting twice does nothing');

        // stock gone → money accepted, the order waits for the owner (pending_approval), same record + token
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['stock_qty' => 0]);
        $taken = Order::query()->where('track_token', $tracks['accept-taken'])->firstOrFail();
        $this->assertSame('pending_approval', $late->accept($taken));
        $this->orderStatus($tracks['accept-taken'])->assertJsonPath('order.status', 'pending_approval')->assertJsonPath('payment.status', 'paid')
            ->assertJsonPath('order.public_id', $taken->public_id);

        // refund: always allowed (API-28) — the order stays cancelled, the payment reads refunded
        foreach (RefundSwitches::FLOWS as $switch) { // every D7 switch off (P3-14)
            StoreSettings::set((int) $taken->store_id, $switch, false);
        }
        $refund = Order::query()->where('track_token', $tracks['refund'])->firstOrFail();
        $result = $late->refund($refund);
        $this->assertNotNull($result);
        $this->assertSame('refunded', $refund->fresh()->late_payment_status);
        $this->orderStatus($tracks['refund'])->assertJsonPath('order.status', 'cancelled')->assertJsonPath('payment.status', 'refunded')
            ->assertJsonPath('payment.refunded_amount.minor', $refund->fresh()->late_payment_minor);
        $this->assertNull($late->refund($refund->fresh()), 'refunding twice does nothing');

        // the accepted-but-waiting one can still be refunded
        $this->assertNotNull($late->refund($taken->fresh()));
        $this->assertSame('cancelled', $taken->fresh()->status);
    }

    public function test_payment_checks_answer_their_codes(): void
    {
        $q = $this->quote($this->cart() + ['payment_mode' => 'hosted'])->assertOk();
        $this->assertContains('return_url', $q->json('requires'));
        $body = $this->cart() + ['quote_hash' => $q->json('quote_hash')];

        $this->submit($body + ['payment' => ['mode' => 'hosted', 'return_url' => 'https://evil.example/x']])->assertStatus(422)->assertJsonPath('code', 'return_url_not_allowed');
        $this->submit($body + ['payment' => ['mode' => 'hosted']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->submit($body + ['payment_mode' => 'pay_later', 'payment' => ['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/x']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->submit($body + ['payment' => ['mode' => 'pay_later', 'method_id' => 'online']])->assertStatus(422)->assertJsonPath('code', 'payment_method_unavailable');

        StoreSettings::set((int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('store_id'), 'restaurant_online_payment', false);
        $plain = $this->quote($this->cart() + ['payment_mode' => 'hosted'])->assertOk();
        $this->assertSame(['pay_later'], $plain->json('payment_modes'));
        $this->assertContains('payment_mode_unavailable', array_column($plain->json('warnings'), 'code'));
        $this->submit($this->cart() + ['quote_hash' => $plain->json('quote_hash'), 'payment' => ['mode' => 'hosted', 'return_url' => 'https://saffron-house.example/x']])
            ->assertStatus(422)->assertJsonPath('code', 'payment_mode_unavailable');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_business_rules_answer_their_codes(): void
    {
        $storeId = (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('store_id');
        $this->quote(['order_type' => 'ship', 'lines' => [['item_id' => ApiFixtures::TAWOOK, 'qty' => 1]]])->assertStatus(422)->assertJsonPath('code', 'invalid_order_type');
        $this->quote(['order_type' => 'nonsense', 'lines' => [['item_id' => ApiFixtures::TAWOOK, 'qty' => 1]]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        StoreSettings::set($storeId, 'min_order_value', '500');
        $q = $this->quote($this->cart())->assertOk();
        $this->assertContains('below_minimum', array_column($q->json('warnings'), 'code'));
        $this->submit($this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])->assertStatus(422)->assertJsonPath('code', 'below_minimum');
        StoreSettings::set($storeId, 'min_order_value', '0');

        StoreSettings::set($storeId, 'open_close_store', false);
        $q = $this->quote($this->cart())->assertOk();
        $this->submit($this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])->assertStatus(409)->assertJsonPath('code', 'store_closed');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_sold_out_line_is_a_quote_warning_and_a_submit_refusal(): void
    {
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['is_sold_out' => 1]);
        $q = $this->quote($this->cart())->assertOk();
        $this->assertSame([['code' => 'out_of_stock', 'line_id' => 'ln_2']], array_map(fn ($w) => ['code' => $w['code'], 'line_id' => $w['line_id']], $q->json('warnings')));
        $this->submit($this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])
            ->assertStatus(422)->assertJsonPath('code', 'out_of_stock')->assertJsonPath('details.line_ids', ['ln_2']);
        $this->assertSame(0, Order::query()->count());
    }

    /** P4-16: with the catalogue cards cached for 60 s, a sold-out flip that bypasses the model events may show late on the menu, but the quote warns and the order is refused */
    public function test_a_sold_out_item_is_refused_at_checkout_even_while_the_menu_cards_are_cached(): void
    {
        config(['perf.storefront_catalog_cache_ttl' => 60]);
        $h = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001', 'Accept-Language' => 'en'];
        $warm = $this->getJson('/api/v1/storefront/catalog', $h)->assertOk();
        $this->assertFalse($warm->json('catalog.items.'.ApiFixtures::TAWOOK.'.sold_out'));

        // a bulk update: no model event, so no cache bust
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->update(['is_sold_out' => 1]);
        $stale = $this->getJson('/api/v1/storefront/catalog', $h)->assertOk();
        $this->assertFalse($stale->json('catalog.items.'.ApiFixtures::TAWOOK.'.sold_out'), 'the cached card is still shown (at most 60 s)');

        $q = $this->quote($this->cart())->assertOk();
        $this->assertSame([['code' => 'out_of_stock', 'line_id' => 'ln_2']], array_map(fn ($w) => ['code' => $w['code'], 'line_id' => $w['line_id']], $q->json('warnings')));
        $this->submit($this->cart() + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])
            ->assertStatus(422)->assertJsonPath('code', 'out_of_stock');
        $this->assertSame(0, Order::query()->count());

        // a save through the model busts the token: the menu shows it at once
        Menu::query()->where('public_id', ApiFixtures::TAWOOK)->firstOrFail()->update(['is_sold_out' => 1, 'name' => 'Tawook (sold out)']);
        $fresh = $this->getJson('/api/v1/storefront/catalog', $h)->assertOk();
        $this->assertTrue($fresh->json('catalog.items.'.ApiFixtures::TAWOOK.'.sold_out'));
    }

    public function test_dine_in_needs_a_table_of_this_store(): void
    {
        $storeId = (int) Menu::query()->where('public_id', ApiFixtures::TAWOOK)->value('store_id');
        StoreTable::query()->create(['store_id' => $storeId, 'table_no' => 7, 'key' => 'tbl-key-7', 'label' => 'Table 7']);
        $cart = ['order_type' => 'dine_in', 'lines' => [['line_id' => 'a', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1]]];
        $q = $this->quote($cart)->assertOk();
        $this->assertContains('table', $q->json('requires'));
        $this->submit($cart + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->submit($cart + ['table' => 'nope', 'quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])->assertStatus(422);
        $r = $this->submit($cart + ['table' => 'tbl-key-7', 'quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later']])->assertCreated();
        $this->assertSame(7, (int) Order::query()->where('public_id', $r->json('order.public_id'))->value('table_number'));
    }
}
