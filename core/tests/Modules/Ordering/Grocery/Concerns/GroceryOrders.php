<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Grocery\Concerns;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Tests\Support\StorefrontApi\ApiFixtures;

/**
 * P3-09 test helper: a Fresh Mart grocery order placed through the headless API (slot hold → quote → order), the
 * same path a shopper's client takes, at 10:45 store time on Fri 2 Oct 2026. Basket: 2 × Bananas 6.50, Tomatoes
 * 500 g (weighed, estimate 5.90, cap 6.49), Sourdough 14.00 + delivery 9 = 41.90; a card hold adds 0.59 → 42.49.
 */
trait GroceryOrders
{
    protected ApiFixtures $fx;

    protected function bootGrocery(): Store
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 07:45:30', 'UTC'));
        $this->fx = new ApiFixtures;

        return $this->fx->freshMartGrocery();
    }

    /** @param  array<string, mixed>  $extra */
    protected function placeGroceryOrder(string $mode = 'hosted', ?string $preference = ApiFixtures::SUB_REPLACE, array $extra = []): Order
    {
        $read = ['X-Storefront-Token' => 'sfx_demo_freshmart_read_0001', 'Accept' => 'application/json'];
        $write = ['X-Storefront-Token' => 'sfx_demo_freshmart_write_0001', 'Accept' => 'application/json', 'Origin' => 'https://fresh-mart.example'];
        $slots = $this->getJson('/api/v1/storefront/delivery/slots?order_type=delivery', $read)->assertOk()->json('days.0.slots');
        $slot = collect($slots)->firstWhere('state', 'available')['id'];
        $hold = $this->postJson('/api/v1/storefront/holds', ['kind' => 'delivery_slot', 'slot_id' => $slot], $write + ['Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $body = array_merge([
            'order_type' => 'delivery',
            'lines' => [
                ['line_id' => 'ln_1', 'item_id' => ApiFixtures::BANANAS, 'option_ids' => [], 'qty' => 2],
                ['line_id' => 'ln_2', 'item_id' => ApiFixtures::TOMATOES, 'option_ids' => [ApiFixtures::TOMATO_STEPS[500]], 'qty' => 1],
                ['line_id' => 'ln_3', 'item_id' => '019e8646-1380-7aeb-91aa-3627eb8ec48e', 'option_ids' => [], 'qty' => 1],
            ],
            'address' => ['line1' => 'Al Olaya, King Fahd Rd, Bldg 12', 'city' => 'Riyadh', 'country' => 'SA'],
            'slot_id' => $slot,
            'hold_id' => $hold->json('id'),
            'payment_method_id' => $mode === 'hosted' ? 'card' : 'cod',
        ], $preference !== null ? ['substitution_preference' => $preference] : [], $extra);
        $quote = $this->postJson('/api/v1/storefront/quote', $body + ['payment_mode' => $mode], $read)->assertOk();
        $order = $this->postJson('/api/v1/storefront/orders', $body + [
            'quote_hash' => $quote->json('quote_hash'),
            'customer' => ['name' => 'Noura', 'phone' => '+966551234567', 'marketing_opt_in' => false],
            'payment' => ['mode' => $mode, 'method_id' => $mode === 'hosted' ? 'card' : 'cod'] + ($mode === 'hosted' ? ['return_url' => 'https://fresh-mart.example/checkout/return'] : []),
        ], $write + ['Idempotency-Key' => (string) Str::uuid7(), 'X-Hold-Token' => (string) $hold->json('token')]);
        $order->assertCreated();

        return Order::query()->where('track_token', $order->json('order.track_token'))->firstOrFail();
    }

    /** The gateway authorises the order's hosted transaction (card hold). */
    protected function authorise(Order $order, string $gateway = 'stub', ?string $paymentId = null): Transaction
    {
        $transaction = app(HostedOrderPayments::class)->transactionFor($order);
        $this->assertNotNull($transaction);
        $this->assertTrue(Settlement::authorise($transaction, $gateway, $paymentId ?? 'pay_'.$order->id, now()->addDays(7)));

        return $transaction->refresh();
    }

    protected function tomatoLine(Order $order): OrderItem
    {
        return OrderItem::query()->where('order_id', $order->id)->where('weight_g', '>', 0)->firstOrFail();
    }

    protected function lineFor(Order $order, string $publicId): OrderItem
    {
        $menuId = Menu::query()->where('public_id', $publicId)->value('id');

        return OrderItem::query()->where('order_id', $order->id)->where('item_id', $menuId)->firstOrFail();
    }
}
