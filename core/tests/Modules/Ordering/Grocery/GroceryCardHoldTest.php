<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Grocery;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPicking;
use Juwri\Modules\Payments\Services\Gateways\CardHolds;
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
}
