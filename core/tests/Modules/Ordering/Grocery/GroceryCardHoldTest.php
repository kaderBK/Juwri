<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Grocery;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Marketplace\PlatformWalletTender;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPicking;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPickingException;
use Juwri\Modules\Ordering\Services\Orders\OrderCancellation;
use Juwri\Modules\Ordering\Services\Payments\CardHoldOrders;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Payments\Services\Gateways\CardHolds;
use Juwri\Modules\Payments\Services\Gateways\PaypalAdapter;
use Tests\Modules\Ordering\Grocery\Concerns\GroceryOrders;
use Tests\TestCase;

/**
 * P3-09 (REVIEW, Opus: money) — the card hold of a weighed basket, per capture gateway class: the Stub (in-process),
 * Stripe (PaymentIntent manual capture) and PayPal (AUTHORIZE intent) with sandbox fakes. The hold authorises the
 * estimate + what the weighed lines may add; after picking the final amount is captured, never above the authorised
 * amount; nothing left voids the hold; a line removed after the capture is always refunded (API-31); a cancelled
 * order releases its hold; the customer hears the final amount (N4) when the store sends status messages.
 */
class GroceryCardHoldTest extends TestCase
{
    use GroceryOrders;
    use RefreshDatabase;

    private const HOLD = 4249; // 41.90 + 0.59

    /** @var list<string> */
    private array $sms = [];

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $store = $this->store = $this->bootGrocery();
        StoreSettings::set((int) $store->id, 'customer_status_sms', '1');
        $this->mock(MessageQueue::class, function ($mock) {
            $mock->shouldReceive('sms')->andReturnUsing(function (string $to, string $message) {
                $this->sms[] = $message;
            });
            $mock->shouldIgnoreMissing();
        });
    }

    private function pick(Order $order, int $tomatoGrams): GroceryPicking
    {
        $picking = app(GroceryPicking::class);
        $picking->start($order);
        $picking->weigh($order, (int) $this->tomatoLine($order)->id, $tomatoGrams);

        return $picking;
    }

    public function test_stub_hold_authorises_the_cap_and_captures_the_actual_weight(): void
    {
        $order = $this->placeGroceryOrder();
        $transaction = $this->authorise($order);
        $order->refresh();
        $this->assertSame([self::HOLD, 'authorised', 0], [(int) $order->hold_minor, $order->hold_status, (int) $order->is_paid]);
        $this->assertSame(self::HOLD, (int) $transaction->amount_minor);

        // 540 g of tomatoes: 5.90 × 540 / 500 = 6.372 → 6.37 (under the 6.49 cap)
        $result = $this->pick($order, 540)->finish($order);
        $this->assertSame(['captured', 4237, 12], [$result['outcome'], $result['final_minor'], $result['released_minor']]);
        $order->refresh();
        $transaction->refresh();
        $this->assertSame(['captured', 4237, 1], [$order->hold_status, (int) $order->captured_minor, (int) $order->is_paid]);
        $this->assertSame(4237, (int) $transaction->amount_minor, 'the transaction carries the captured amount');
        $this->assertSame(self::HOLD, (int) data_get($transaction->details, 'authorised_minor'));
        $this->assertSame(637, (int) $this->tomatoLine($order)->final_minor);
        $this->assertCount(1, array_filter($this->sms, fn ($m) => str_contains($m, 'final amount is SAR 42.37') && str_contains($m, 'SAR 0.12 of the card hold was released')), 'N4: grocery.final_amount');
    }

    public function test_a_heavier_pick_never_captures_more_than_the_line_cap_or_the_hold(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        // 2 kg picked instead of 500 g: the line stops at its cap 6.49, the capture at the authorised amount
        $result = $this->pick($order, 2000)->finish($order);
        $this->assertSame(649, (int) $this->tomatoLine($order)->final_minor);
        $this->assertSame(self::HOLD, $result['final_minor']);
        $this->assertSame(0, $result['released_minor']);
        $this->assertLessThanOrEqual((int) $order->fresh()->hold_minor, (int) $order->fresh()->captured_minor);
    }

    public function test_capture_above_the_authorised_amount_is_refused_and_a_second_capture_is_not_open(): void
    {
        $order = $this->placeGroceryOrder();
        $transaction = $this->authorise($order);
        $holds = app(CardHolds::class);

        $this->assertFalse($holds->capture($transaction, self::HOLD + 1)['ok']);
        $this->assertFalse($holds->capture($transaction, 0)['ok']);
        $this->assertSame('authorised', $transaction->fresh()->status, 'a refused capture leaves the hold open');

        $this->assertTrue($holds->capture($transaction->fresh(), 4000)['ok']);
        $this->assertFalse($holds->capture($transaction->fresh(), 100)['ok'], 'captured once only');
    }

    public function test_every_line_removed_voids_the_hold(): void
    {
        $order = $this->placeGroceryOrder();
        $transaction = $this->authorise($order);
        $picking = app(GroceryPicking::class);
        $picking->start($order);
        foreach ($order->items()->get() as $line) {
            $picking->remove($order, (int) $line->id);
        }
        $result = $picking->finish($order);
        // nothing is delivered: not even the delivery fee is charged
        $this->assertSame(['voided', 0, self::HOLD], [$result['outcome'], $result['final_minor'], $result['released_minor']]);
        $this->assertSame(0, (int) OrderAdjustment::query()->where('order_id', $order->id)->count(), 'a released hold is not a refund');
        $this->assertSame('voided', $transaction->fresh()->status);
        $this->assertSame('voided', $order->fresh()->hold_status);
    }

    public function test_a_line_removed_after_the_capture_is_always_refunded_api_31(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $picking = $this->pick($order, 500);
        $picking->finish($order);
        $this->assertSame('captured', $order->fresh()->hold_status);
        $this->assertFalse(StoreSettings::bool((int) $order->store_id, 'refunds_weighed_difference'), 'D7 switch off');

        $bread = $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e');
        $refunded = $picking->removeAfterFinish($order, (int) $bread->id);
        $this->assertSame(1400, $refunded);
        $this->assertSame(1400, (int) $bread->fresh()->refunded_minor);
        $this->assertSame(14.0, (float) OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->get()->sum(fn ($a) => (float) $a->amount));

        $status = $this->getJson('/api/v1/storefront/orders/'.$order->track_token, ['X-Storefront-Token' => 'sfx_demo_freshmart_read_0001'])->assertOk();
        $this->assertSame('partially_refunded', $status->json('payment.status'));
        $this->assertTrue(collect($this->sms)->contains(fn ($m) => str_contains($m, 'We refunded SAR 14.00')), 'N4: grocery.final_amount_refunded');
    }

    /** money-last 3: the provider refunded but the refund row failed to save: logged as unrecorded (not failed), with the reference. */
    public function test_a_provider_refund_whose_row_fails_to_save_is_logged_unrecorded_not_failed(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $picking = $this->pick($order, 500);
        $picking->finish($order);
        OrderAdjustment::creating(fn () => throw new \RuntimeException('db write failed'));

        $bread = $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e');
        $this->assertSame(1400, $picking->removeAfterFinish($order, (int) $bread->id), 'the money moved: reported as refunded');

        $logged = ActivityLog::query()->where('action', 'order.refund_unrecorded')->get();
        $this->assertCount(1, $logged);
        $this->assertSame(14.0, (float) $logged[0]->meta['amount']);
        $this->assertSame('removed-line:'.$bread->id, $logged[0]->meta['key']);
        $this->assertArrayHasKey('reference', $logged[0]->meta);
        $this->assertSame(0, ActivityLog::query()->where('action', 'order.capture_above_final')->count());
    }

    /**
     * ML-1120 review NIT 3: a refund row whose first write fails is written on the second try, so the refund caps (the
     * store-credit cap reads only refund rows) count it; nothing is logged as unrecorded.
     */
    public function test_a_provider_refund_whose_first_row_write_fails_is_recorded_on_the_retry(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $picking = $this->pick($order, 500);
        $picking->finish($order);
        $failures = 0;
        OrderAdjustment::creating(function () use (&$failures) {
            if ($failures++ === 0) {
                throw new \RuntimeException('db write failed once');
            }
        });

        $bread = $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e');
        $this->assertSame(1400, $picking->removeAfterFinish($order, (int) $bread->id));

        $rows = OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('removed-line:'.$bread->id, $rows[0]->idempotency_key);
        $this->assertSame(14.0, (float) $rows[0]->amount);
        $this->assertSame(0, ActivityLog::query()->where('action', 'order.refund_unrecorded')->count());
    }

    /** grocery-snap: picked for less than authorised — the stored totals follow the picked lines, the refund cap the capture. */
    public function test_finish_snapshots_the_picked_total_and_the_refund_cap_follows_it(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $this->assertSame(4190, (int) $order->fresh()->grand_total_minor, 'stored at order time: the estimate');

        // 400 g of tomatoes: 4.72 → 13.00 + 4.72 + 14.00 + 9.00 = 40.72, under the 42.49 hold
        $result = $this->pick($order, 400)->finish($order);
        $this->assertSame(['captured', 4072], [$result['outcome'], $result['final_minor']]);
        $order->refresh();
        $this->assertSame([4072, 4072], [(int) $order->grand_total_minor, (int) $order->captured_minor], 'the stored grand total is the picked total');
        $this->assertSame(3172, (int) $order->subtotal_minor);

        $owner = User::query()->findOrFail($this->store->user_id);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 40.73])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 40.72])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToCredit', $order->id), ['amount' => 0.01])->assertStatus(422);
    }

    /** grocery-snap: a line removed after the capture re-snapshots the total; its refund row is not subtracted twice. */
    public function test_remove_after_finish_snapshots_the_total_and_leaves_the_rest_refundable(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $picking = $this->pick($order, 400);
        $picking->finish($order);
        $bread = $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e');
        $this->assertSame(1400, $picking->removeAfterFinish($order, (int) $bread->id));
        $this->assertSame(4072 - 1400, (int) $order->fresh()->grand_total_minor);

        $owner = User::query()->findOrFail($this->store->user_id);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 26.73])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 26.72])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.refundOrderToOriginal', $order->id), ['amount' => 0.01])->assertStatus(422);
    }

    /** grocery-snap2 SHOULD 2: a weighed line removed after the capture refunds what was charged for it, not its estimate. */
    public function test_remove_after_finish_refunds_the_picked_price_of_the_line(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $picking = $this->pick($order, 400); // 4.72, estimate 5.90
        $picking->finish($order);
        $this->assertSame(472, $picking->removeAfterFinish($order, (int) $this->tomatoLine($order)->id));
        $this->assertSame(4.72, (float) OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->get()->sum(fn ($a) => (float) $a->amount));
    }

    /** grocery-snap2 backlog: a capture that failed after picking finished can be retried while the hold is open. */
    public function test_a_failed_capture_can_be_retried(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        Http::fakeSequence('api.stripe.com/*')
            ->push(['error' => ['message' => 'try again']], 402)
            ->push(['id' => 'pi_retry', 'status' => 'succeeded', 'latest_charge' => 'ch_1']);
        $order = $this->placeGroceryOrder();
        $this->authorise($order, 'stripe', 'pi_retry');
        $picking = $this->pick($order, 540);
        try {
            $picking->finish($order);
            $this->fail('the first capture is refused');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['picked', 'authorised'], [$order->fresh()->picking_status, $order->fresh()->hold_status]);

        $result = $picking->finish($order);
        $this->assertSame(['captured', 4237], [$result['outcome'], $result['final_minor']]);
        $this->assertSame(['captured', 4237], [$order->fresh()->hold_status, (int) $order->fresh()->captured_minor]);
        try {
            $picking->finish($order);
            $this->fail('a settled hold is never captured again');
        } catch (GroceryPickingException) {
        }
        Http::assertSentCount(2);
    }

    /** grocery-snap2 backlog: a stored-value tender pays part of the final amount, the card hold only the rest. */
    public function test_the_capture_leaves_out_the_stored_value_tenders(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $order->refresh()->forceFill(['wallet_applied' => 5.00])->saveQuietly();
        $result = $this->pick($order, 400)->finish($order); // final 40.72, 5.00 of it from the wallet
        $this->assertSame(['captured', 3572], [$result['outcome'], $result['final_minor']]);
        $this->assertSame(3572, (int) $order->fresh()->captured_minor);
    }

    public function test_a_cancelled_order_releases_its_card_hold(): void
    {
        $order = $this->placeGroceryOrder();
        $transaction = $this->authorise($order);
        $order->refresh()->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame('voided', $order->fresh()->hold_status);
        $this->assertSame('voided', $transaction->fresh()->status);
        $this->assertSame(0, DB::table('slot_seats')->where('order_id', $order->id)->count(), 'and its slot');
    }

    public function test_stripe_captures_the_final_amount_of_a_manual_payment_intent_and_voids_by_cancel(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        Http::fake([
            'api.stripe.com/v1/payment_intents/pi_hold/capture' => Http::response(['id' => 'pi_hold', 'status' => 'succeeded', 'latest_charge' => 'ch_1']),
            'api.stripe.com/v1/payment_intents/pi_void/cancel' => Http::response(['id' => 'pi_void', 'status' => 'canceled']),
        ]);
        $order = $this->placeGroceryOrder();
        $this->authorise($order, 'stripe', 'pi_hold');
        $result = $this->pick($order, 540)->finish($order);
        $this->assertSame(['captured', 4237], [$result['outcome'], $result['final_minor']]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payment_intents/pi_hold/capture') && (int) $r['amount_to_capture'] === 4237);

        $second = $this->placeGroceryOrder();
        $tx = $this->authorise($second, 'stripe', 'pi_void');
        $this->assertTrue(app(CardHolds::class)->void($tx)['ok']);
        $this->assertSame('voided', $tx->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payment_intents/pi_void/cancel'));
    }

    public function test_a_stripe_refusal_leaves_the_hold_authorised_and_the_order_uncaptured(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'expired']], 402)]);
        $order = $this->placeGroceryOrder();
        $tx = $this->authorise($order, 'stripe', 'pi_dead');
        try {
            $this->pick($order, 500)->finish($order);
            $this->fail('the refused capture throws for the screen');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['authorised', null], [$order->fresh()->hold_status, $order->fresh()->captured_minor]);
        $this->assertSame('authorised', $tx->fresh()->status);
    }

    public function test_paypal_captures_the_authorisation_with_final_capture(): void
    {
        StoreSettings::setMany((int) $this->store->id, [
            'restaurant_paypal_api_client_id' => 'cid', 'restaurant_paypal_api_secret' => 'sec', 'restaurant_paypal_sandbox_mode' => '1',
        ]);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*/v2/payments/authorizations/AUTH-1/capture' => Http::response(['id' => 'CAP-1', 'status' => 'COMPLETED']),
        ]);
        $order = $this->placeGroceryOrder();
        $tx = $this->authorise($order, 'paypal', 'PAYID');
        $tx->forceFill(['details' => array_merge((array) $tx->details, ['paypal_authorization_id' => 'AUTH-1'])])->save();
        $result = $this->pick($order, 540)->finish($order);
        $this->assertSame(['captured', 4237], [$result['outcome'], $result['final_minor']]);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/payments/authorizations/AUTH-1/capture')
            && $r['amount']['value'] === '42.37' && $r['amount']['currency_code'] === 'SAR' && $r['final_capture'] === true);
    }

    /** grocery-nits 1: a picked order that was never paid nor held reports no collected money. */
    public function test_collected_money_needs_a_paid_or_held_order(): void
    {
        $order = $this->placeGroceryOrder(); // hosted, unpaid: its transaction exists, no hold
        $order->refresh()->forceFill(['picking_status' => 'picked'])->saveQuietly();
        $this->assertNotNull(app(HostedOrderPayments::class)->transactionFor($order));
        $this->assertNull(app(CardHoldOrders::class)->collectedMinor($order->fresh()));
    }

    /** grocery-nits 2: two settlements racing on a hold with nothing to capture reach the gateway's void once. */
    public function test_two_racing_voids_call_the_gateway_once(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        $order = $this->placeGroceryOrder();
        $this->authorise($order, 'stripe', 'pi_race');
        $picking = app(GroceryPicking::class);
        $picking->start($order);
        foreach ($order->items()->get() as $line) {
            $picking->remove($order, (int) $line->id);
        }
        $cancels = 0;
        $inner = null;
        Http::fake(['api.stripe.com/v1/payment_intents/pi_race/cancel' => function () use (&$cancels, &$inner, $order) {
            $cancels++;
            if ($cancels === 1) {
                try {
                    $inner = app(CardHoldOrders::class)->settleFinal(Order::query()->findOrFail($order->id)); // the racer
                } catch (\RuntimeException $e) {
                    $inner = $e->getMessage();
                }
            }

            return Http::response(['id' => 'pi_race', 'status' => 'canceled']);
        }]);

        $result = app(CardHoldOrders::class)->settleFinal($order);
        $this->assertSame('voided', $result['outcome']);
        $this->assertSame(1, $cancels, 'the hold is voided once');
        $this->assertNull($inner, 'the racer finds the hold claimed and settles nothing');
        $this->assertSame('voided', $order->fresh()->hold_status);
    }

    /** grocery-nits 2: a refused void leaves the hold authorised, so it can be retried. */
    public function test_a_refused_void_leaves_the_hold_authorised(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'try again']], 500)]);
        $order = $this->placeGroceryOrder();
        $tx = $this->authorise($order, 'stripe', 'pi_novoid');
        $order->refresh()->forceFill(['wallet_applied' => 45.00])->saveQuietly(); // the card owes nothing
        try {
            app(CardHoldOrders::class)->settleFinal($order);
            $this->fail('the refused void throws for the screen');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['authorised', null], [$order->fresh()->hold_status, $order->fresh()->captured_minor]);
        $this->assertSame('authorised', $tx->fresh()->status);
    }

    /** grocery-nits 3: PayPal captures carry a request id; a retry after a timeout reuses it, so PayPal captures once. */
    public function test_paypal_capture_retry_after_a_timeout_reuses_the_request_id(): void
    {
        StoreSettings::setMany((int) $this->store->id, [
            'restaurant_paypal_api_client_id' => 'cid', 'restaurant_paypal_api_secret' => 'sec', 'restaurant_paypal_sandbox_mode' => '1',
        ]);
        $ids = [];
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*/v2/payments/authorizations/AUTH-T/capture' => function (HttpRequest $r) use (&$ids) {
                $ids[] = $r->header('PayPal-Request-Id')[0] ?? '';
                if (count($ids) === 1) {
                    throw new ConnectionException('timed out');
                }

                return Http::response(['id' => 'CAP-T', 'status' => 'COMPLETED']);
            },
        ]);
        $order = $this->placeGroceryOrder();
        $tx = $this->authorise($order, 'paypal', 'PAYID');
        $tx->forceFill(['details' => array_merge((array) $tx->details, ['paypal_authorization_id' => 'AUTH-T'])])->save();
        $picking = $this->pick($order, 540);
        try {
            $picking->finish($order);
            $this->fail('the timed-out capture throws');
        } catch (\RuntimeException) {
        }
        $this->assertSame('authorised', $tx->fresh()->status, 'an idempotent gateway can be retried');
        $this->assertSame(['captured', 4237], [$picking->finish($order)['outcome'], (int) $order->fresh()->captured_minor]);
        $this->assertCount(2, $ids);
        $this->assertNotSame('', $ids[0]);
        $this->assertSame($ids[0], $ids[1], 'the retry is the same request for PayPal');
    }

    /** grocery-nits 3: a gateway without idempotent captures is never retried after an unknown outcome; it is logged. */
    public function test_an_unknown_capture_outcome_on_a_gateway_without_idempotency_is_not_retried(): void
    {
        StoreSettings::setMany((int) $this->store->id, [
            'restaurant_paypal_api_client_id' => 'cid', 'restaurant_paypal_api_secret' => 'sec', 'restaurant_paypal_sandbox_mode' => '1',
        ]);
        $this->app->instance(PaypalAdapter::class, new class extends PaypalAdapter
        {
            public function capturesIdempotently(): bool
            {
                return false;
            }
        });
        $captures = 0;
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*/v2/payments/authorizations/AUTH-U/capture' => function () use (&$captures) {
                $captures++;
                throw new ConnectionException('timed out');
            },
        ]);
        $order = $this->placeGroceryOrder();
        $tx = $this->authorise($order, 'paypal', 'PAYID');
        $tx->forceFill(['details' => array_merge((array) $tx->details, ['paypal_authorization_id' => 'AUTH-U'])])->save();
        $picking = $this->pick($order, 540);
        foreach ([1, 2] as $attempt) {
            try {
                $picking->finish($order);
                $this->fail('attempt '.$attempt.' throws');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(1, $captures, 'never sent a second time');
        $this->assertSame('capturing', $tx->fresh()->status, 'kept for a manual check');
        $this->assertSame(1, ActivityLog::query()->where('action', 'payment.capture_unknown')->count());
    }

    /** grocery-nits 4: wallet money above the picked total goes back to store credit by itself, once. */
    public function test_tender_above_the_picked_total_returns_to_store_credit_once(): void
    {
        $order = $this->placeGroceryOrder();
        $this->authorise($order);
        $customer = Customer::create(['name' => 'Noura', 'store_id' => $this->store->id, 'phone' => '+966551230078']);
        $order->refresh()->forceFill(['wallet_applied' => 45.00, 'customer_id' => $customer->id])->saveQuietly();
        $picking = $this->pick($order, 400); // final 40.72: the wallet covers it, 4.28 too much
        $result = $picking->finish($order);
        $this->assertSame(['voided', 0], [$result['outcome'], $result['final_minor']]);
        $this->assertSame(4.28, CustomerWalletService::balanceFor($customer));

        app(CardHoldOrders::class)->settleFinal($order->fresh()); // a repeat returns nothing more
        $this->assertSame(4.28, CustomerWalletService::balanceFor($customer));
        $this->assertSame(40.72, (float) $order->fresh()->wallet_applied, 'the wallet tender is what paid for the goods');
        $this->assertSame(0, OrderAdjustment::query()->where('order_id', $order->id)->count(), 'no refund row: the tender itself was lowered');

        // a later cancel reverses only the tender that is left, never the returned part again
        $order->refresh()->forceFill(['status' => 'cancelled'])->save();
        OrderCancellation::reverse($order->fresh());
        $this->assertSame(45.0, CustomerWalletService::balanceFor($customer));
    }

    /** grocery-nits2 MUST: a Stripe capture that timed out is an unknown outcome; the retry is the same request (same key). */
    public function test_stripe_capture_retry_after_a_timeout_reuses_the_idempotency_key(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        $keys = [];
        Http::fake(['api.stripe.com/v1/payment_intents/pi_t/capture' => function (HttpRequest $r) use (&$keys) {
            $keys[] = $r->header('Idempotency-Key')[0] ?? '';
            if (count($keys) === 1) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['id' => 'pi_t', 'status' => 'succeeded', 'latest_charge' => 'ch_t']);
        }]);
        $order = $this->placeGroceryOrder();
        $tx = $this->authorise($order, 'stripe', 'pi_t');
        $picking = $this->pick($order, 540);
        try {
            $picking->finish($order);
            $this->fail('the timed-out capture throws');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['authorised', 'authorised'], [$tx->fresh()->status, $order->fresh()->hold_status]);
        $this->assertSame(['captured', 4237], [$picking->finish($order)['outcome'], (int) $order->fresh()->captured_minor]);
        $this->assertCount(2, $keys);
        $this->assertNotSame('', $keys[0]);
        $this->assertSame($keys[0], $keys[1], 'Stripe answers the retry with the first capture');
    }

    /** grocery-nits2 SHOULD 1: a 5xx or 429 answer is an unknown outcome too (Stripe and PayPal replay it per key). */
    public function test_a_server_error_or_rate_limit_is_an_unknown_capture_outcome_and_the_retry_reuses_the_key(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        StoreSettings::setMany((int) $this->store->id, [
            'restaurant_paypal_api_client_id' => 'cid', 'restaurant_paypal_api_secret' => 'sec', 'restaurant_paypal_sandbox_mode' => '1',
        ]);
        $stripe = [];
        $paypal = [];
        Http::fake([
            'api.stripe.com/v1/payment_intents/pi_5xx/capture' => function (HttpRequest $r) use (&$stripe) {
                $stripe[] = $r->header('Idempotency-Key')[0] ?? '';

                return count($stripe) === 1
                    ? Http::response(['error' => ['message' => 'busy']], 503)
                    : Http::response(['id' => 'pi_5xx', 'status' => 'succeeded', 'latest_charge' => 'ch_5']);
            },
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*/v2/payments/authorizations/AUTH-R/capture' => function (HttpRequest $r) use (&$paypal) {
                $paypal[] = $r->header('PayPal-Request-Id')[0] ?? '';

                return count($paypal) === 1
                    ? Http::response(['name' => 'RATE_LIMIT_REACHED'], 429)
                    : Http::response(['id' => 'CAP-R', 'status' => 'COMPLETED']);
            },
        ]);

        $first = $this->placeGroceryOrder();
        $this->authorise($first, 'stripe', 'pi_5xx');
        $second = $this->placeGroceryOrder();
        $tx = $this->authorise($second, 'paypal', 'PAYID');
        $tx->forceFill(['details' => array_merge((array) $tx->details, ['paypal_authorization_id' => 'AUTH-R'])])->save();
        foreach ([$first, $second] as $order) {
            $picking = $this->pick($order, 540);
            try {
                $picking->finish($order);
                $this->fail('the 5xx / 429 capture throws');
            } catch (\RuntimeException) {
            }
            $this->assertSame('captured', $picking->finish($order)['outcome']);
        }
        $this->assertCount(2, $stripe);
        $this->assertSame($stripe[0], $stripe[1], 'Stripe: a 503 is retried as the same request');
        $this->assertCount(2, $paypal);
        $this->assertSame($paypal[0], $paypal[1], 'PayPal: a 429 is retried as the same request');
    }

    /**
     * grocery-nits2 SHOULD 2: the capture key is a UUID stored on the transaction with the amount it asked for. A
     * definite refusal moves to a new key; an unknown outcome keeps key and amount, so a retry asking for another amount
     * is still the first request, and the order records what was really captured.
     */
    public function test_the_capture_key_is_a_stored_uuid_kept_with_its_amount_until_a_definite_refusal(): void
    {
        StoreSettings::set((int) $this->store->id, 'restaurant_stripe_secret_key', 'sk_test_x');
        $sent = [];
        Http::fake(['api.stripe.com/v1/payment_intents/pi_k/capture' => function (HttpRequest $r) use (&$sent) {
            $sent[] = [$r->header('Idempotency-Key')[0] ?? '', (int) $r['amount_to_capture']];

            return match (count($sent)) {
                1 => Http::response(['error' => ['message' => 'declined']], 402),
                2 => throw new ConnectionException('timed out'),
                default => Http::response(['id' => 'pi_k', 'status' => 'succeeded', 'latest_charge' => 'ch_k']),
            };
        }]);
        $order = $this->placeGroceryOrder();
        $tx = $this->authorise($order, 'stripe', 'pi_k');
        $picking = $this->pick($order, 540);
        foreach ([0.00, 1.00] as $wallet) {
            $order->refresh()->forceFill(['wallet_applied' => $wallet])->saveQuietly();
            try {
                $picking->finish($order);
                $this->fail('refused, then unknown');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(4137, (int) data_get($tx->fresh()->details, 'capture_pending.minor'));

        $order->refresh()->forceFill(['wallet_applied' => 2.00])->saveQuietly(); // the card part is 40.37 now
        $result = $picking->finish($order);
        // money-tail 2: the retry is the 41.37 request; the 1.00 above the 40.37 card part goes back at once
        $this->assertSame(['captured', 4037, 100], [$result['outcome'], $result['final_minor'], $result['refunded_minor']]);
        $this->assertSame(4137, (int) $order->fresh()->captured_minor);
        $drift = OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->get();
        $this->assertSame([['capture-drift:'.$order->id, 1.0]], $drift->map(fn ($a) => [$a->idempotency_key, (float) $a->amount])->all());
        app(CardHoldOrders::class)->settleFinal($order->fresh()); // a repeat refunds nothing more
        $this->assertSame(1, OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->count());
        $this->assertSame(4137, (int) $tx->fresh()->amount_minor);
        $this->assertSame([4237, 4137, 4137], array_column($sent, 1));
        $this->assertTrue(Str::isUuid($sent[0][0]) && Str::isUuid($sent[1][0]), 'a stored UUID, unique across environments');
        $this->assertNotSame($sent[0][0], $sent[1][0], 'a refusal moves to a new key');
        $this->assertSame($sent[1][0], $sent[2][0], 'an unknown outcome keeps it');
        $this->assertNull(data_get($tx->fresh()->details, 'capture_pending'));
    }

    /** grocery-nits2 SHOULD 3: a capture stuck in `capturing` (unknown outcome, no safe retry) is resolved by an audited admin command. */
    public function test_a_stuck_capture_is_resolved_by_the_admin_command(): void
    {
        StoreSettings::setMany((int) $this->store->id, [
            'restaurant_paypal_api_client_id' => 'cid', 'restaurant_paypal_api_secret' => 'sec', 'restaurant_paypal_sandbox_mode' => '1',
        ]);
        $this->app->instance(PaypalAdapter::class, new class extends PaypalAdapter
        {
            public function capturesIdempotently(): bool
            {
                return false;
            }
        });
        $ids = [];
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*/v2/payments/authorizations/AUTH-S/capture' => fn () => throw new ConnectionException('timed out'),
            '*/v2/payments/authorizations/AUTH-N/capture' => function (HttpRequest $r) use (&$ids) {
                $ids[] = $r->header('PayPal-Request-Id')[0] ?? '';
                if (count($ids) === 1) {
                    throw new ConnectionException('timed out');
                }

                return Http::response(['id' => 'CAP-N', 'status' => 'COMPLETED']);
            },
        ]);
        $stuck = [];
        foreach (['AUTH-S', 'AUTH-N'] as $authorization) {
            $order = $this->placeGroceryOrder();
            $tx = $this->authorise($order, 'paypal', 'PAYID-'.$authorization);
            $tx->forceFill(['details' => array_merge((array) $tx->details, ['paypal_authorization_id' => $authorization])])->save();
            $picking = $this->pick($order, 540);
            try {
                $picking->finish($order);
                $this->fail('the unknown capture throws');
            } catch (\RuntimeException) {
            }
            $this->assertSame('capturing', $tx->fresh()->status);
            $stuck[] = [$order, $tx, $picking];
        }

        // the provider shows the first one captured
        [$order, $tx] = $stuck[0];
        $this->artisan('orders:resolve-capture', ['transaction' => $tx->id])->assertFailed(); // say which
        // money-tail 1: a mark-captured needs the provider's capture id and who checked it
        $this->artisan('orders:resolve-capture', ['transaction' => $tx->id, '--captured' => true, '--by' => 'ops@juwri'])->assertFailed();
        $this->artisan('orders:resolve-capture', ['transaction' => $tx->id, '--captured' => true, '--reference' => 'CAP-S'])->assertFailed();
        $this->assertSame(['capturing', 'authorised'], [$tx->fresh()->status, $order->fresh()->hold_status]);
        $this->artisan('orders:resolve-capture', ['transaction' => $tx->id, '--captured' => true, '--reference' => 'CAP-S', '--by' => 'ops@juwri'])->assertSuccessful();
        $order->refresh();
        $this->assertSame(['captured', 4237, 1], [$order->hold_status, (int) $order->captured_minor, (int) $order->is_paid]);
        $this->assertNotSame('capturing', $tx->fresh()->status);
        $this->assertSame(4237, (int) $tx->fresh()->amount_minor);
        $this->assertSame('CAP-S', data_get($tx->fresh()->details, 'capture_reference'));
        $this->artisan('orders:resolve-capture', ['transaction' => $tx->id, '--captured' => true])->assertFailed(); // resolved once

        // the provider shows the second one not captured: back to authorised, and the next capture is a new request
        [$order, $tx, $picking] = $stuck[1];
        $this->artisan('orders:resolve-capture', ['transaction' => $tx->id, '--not-captured' => true, '--by' => 'ops@juwri'])->assertSuccessful();
        $this->assertSame(['authorised', 'authorised'], [$tx->fresh()->status, $order->fresh()->hold_status]);
        $this->assertSame('captured', $picking->finish($order)['outcome']);
        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);

        $logged = ActivityLog::query()->where('action', 'payment.capture_resolved')->get();
        $this->assertCount(2, $logged);
    }

    /**
     * grocery-nits2 SHOULD 4 a+b: the wallet part of a tender excess goes back even while the store cannot issue store
     * credit (it is money the customer already spent), and every return has its own ledger ref (a second return after
     * removeAfterFinish is posted too).
     */
    public function test_wallet_excess_returns_without_store_credit_issuance_and_each_return_is_posted(): void
    {
        config(['settings.admin_allow_customer_wallet' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        $this->assertFalse(FeatureAccess::storeAllows((int) $this->store->id, 'customer_wallet'));
        $order = $this->placeGroceryOrder('pay_later');
        $customer = Customer::create(['name' => 'Noura', 'store_id' => $this->store->id, 'phone' => '+966551230079']);
        $order->refresh()->forceFill(['wallet_applied' => 45.00, 'customer_id' => $customer->id])->saveQuietly();
        $picking = $this->pick($order, 400);
        $picking->finish($order); // final 40.72
        $this->assertSame(4.28, CustomerWalletService::balanceFor($customer));

        $bread = $this->lineFor($order, '019e8646-1380-7aeb-91aa-3627eb8ec48e');
        $picking->removeAfterFinish($order, (int) $bread->id); // final 26.72
        app(CardHoldOrders::class)->settleFinal($order->fresh());
        $this->assertSame(18.28, CustomerWalletService::balanceFor($customer), 'the second return is posted, not dropped');
        $this->assertSame(26.72, (float) $order->fresh()->wallet_applied);
        $this->assertSame(0, ActivityLog::query()->where('action', 'order.tender_excess_unreturned')->count());
    }

    /** grocery-nits2 SHOULD 4c: the gift-card part of a tender excess goes back to that gift card, not to store credit. */
    public function test_gift_card_excess_goes_back_to_the_card(): void
    {
        StoreSettings::set((int) $this->store->id, 'gift_cards_enabled', '1');
        ['card' => $card, 'transaction' => $payment] = GiftCardService::initiate((int) $this->store->id, 45, 'stripe');
        GiftCardService::activate($card, $payment);
        $order = $this->placeGroceryOrder('pay_later');
        $this->assertEqualsWithDelta(41.90, GiftCardService::applyToOrder($card->fresh(), $order->fresh()), 0.001);
        $this->assertEqualsWithDelta(3.10, $card->fresh()->balance(), 0.001);

        $this->pick($order, 400)->finish($order); // final 40.72: 1.18 too much
        $this->assertEqualsWithDelta(4.28, $card->fresh()->balance(), 0.001);
        $this->assertSame(40.72, (float) $order->fresh()->gift_card_applied);

        // a later cancel gives back only what is left on the order
        $order->refresh()->forceFill(['status' => 'cancelled'])->save();
        OrderCancellation::reverse($order->fresh());
        $this->assertEqualsWithDelta(45.00, $card->fresh()->balance(), 0.001);
    }

    /** money-tail 3: an expired gift card's part of the excess goes to the customer's store credit, once (not only logged). */
    public function test_an_expired_gift_cards_excess_goes_to_store_credit_once(): void
    {
        config(['settings.admin_allow_customer_wallet' => true]);
        StoreSettings::set((int) $this->store->id, 'gift_cards_enabled', '1');
        ['card' => $card, 'transaction' => $payment] = GiftCardService::initiate((int) $this->store->id, 45, 'cash');
        GiftCardService::activate($card, $payment);
        $order = $this->placeGroceryOrder('pay_later');
        $customer = Customer::create(['name' => 'Noura', 'store_id' => $this->store->id, 'phone' => '+966551230081']);
        $order->refresh()->forceFill(['customer_id' => $customer->id])->saveQuietly();
        $this->assertEqualsWithDelta(41.90, GiftCardService::applyToOrder($card->fresh(), $order->fresh()), 0.001);
        $card->fresh()->forceFill(['expires_at' => now()->subDay()])->save();

        $this->pick($order, 400)->finish($order); // final 40.72: 1.18 too much, the card cannot take it back
        $this->assertEqualsWithDelta(1.18, CustomerWalletService::balanceFor($customer), 0.001);
        $this->assertEqualsWithDelta(3.10, $card->fresh()->balance(), 0.001, 'nothing back on the dead card');
        $this->assertSame(40.72, (float) $order->fresh()->gift_card_applied);
        $this->assertSame(1, ActivityLog::query()->where('action', 'giftcard.refunded_to_store_credit')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'order.tender_excess_unreturned')->count());

        app(CardHoldOrders::class)->settleFinal($order->fresh()); // a repeat pays nothing more
        $this->assertEqualsWithDelta(1.18, CustomerWalletService::balanceFor($customer), 0.001);

        // a later cancel pays out only what is left on the order, under its own ref (never `already_done`)
        $order->refresh()->forceFill(['status' => 'cancelled'])->save();
        OrderCancellation::reverse($order->fresh());
        $this->assertEqualsWithDelta(41.90, CustomerWalletService::balanceFor($customer), 0.001);
    }

    /** grocery-nits2 SHOULD 4c: the platform-wallet part goes back to the platform wallet (the edition's extension point). */
    public function test_platform_wallet_excess_goes_back_to_the_platform_wallet(): void
    {
        $wallet = new class implements PlatformWalletTender
        {
            /** @var list<array{int, float, string}> */
            public array $calls = [];

            public function returnPart(Order $order, float $amount, string $key): bool
            {
                $this->calls[] = [(int) $order->id, $amount, $key];

                return true;
            }

            public function reverseOrder(Order $order, float $keep = 0.0): float
            {
                return 0.0;
            }
        };
        $this->app->instance(PlatformWalletTender::class, $wallet);
        $order = $this->placeGroceryOrder('pay_later');
        $order->refresh()->forceFill(['mk_wallet_applied' => 45.00])->saveQuietly();
        $this->pick($order, 400)->finish($order);
        $this->assertSame([[(int) $order->id, 4.28, 'tender-excess:4072']], $wallet->calls);
        $this->assertSame(40.72, (float) $order->fresh()->mk_wallet_applied);
    }
}
