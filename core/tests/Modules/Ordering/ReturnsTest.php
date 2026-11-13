<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CustomerWalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\Returns;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\ReturnRequest;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-08 (D7) — goods returns: the window (counted from the handoff), the resolutions (exchange / store credit; a
 * refund only with returns refunds on), quantities that can be asked for once, restock on receipt, and the money of
 * completion capped by what is left to refund on the order (the owner refunds' ledger).
 */
class ReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(Carbon::parse('2026-10-02 09:00:00', 'UTC'));
        $this->store = (new ApiFixtures)->duneRetail();
        $this->owner = User::query()->findOrFail($this->store->user_id);
        $this->customer = Customer::create(['name' => 'Noura', 'store_id' => $this->store->id, 'phone' => '+966551234567']);
    }

    /** A paid, shipped order: 2 × shirt Sand·M (139) + 1 × trousers (229) = 507.00 */
    private function shippedOrder(): Order
    {
        $shirt = Menu::query()->where('public_id', ApiFixtures::SHIRT)->firstOrFail();
        $trousers = Menu::query()->where('public_id', ApiFixtures::TROUSERS)->firstOrFail();
        $m = MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->firstOrFail();
        $navy = MenuVariant::query()->where('public_id', ApiFixtures::TROUSERS_NAVY_M)->firstOrFail();
        $order = Order::create(['store_id' => $this->store->id, 'customer_id' => $this->customer->id, 'type' => 'ship', 'status' => 'shipped',
            'shipped_at' => now(), 'is_paid' => 1, 'payment_method' => 'card', 'track_token' => 'trk_returns_'.Str::lower(Str::random(12)), 'currency_code' => 'SAR']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $shirt->id, 'quantity' => 2, 'variation' => $m->id]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $trousers->id, 'quantity' => 1, 'variation' => $navy->id]);

        return $order->fresh();
    }

    private function line(Order $order, string $variantPublicId): OrderItem
    {
        $variant = MenuVariant::query()->where('public_id', $variantPublicId)->firstOrFail();

        return OrderItem::query()->where('order_id', $order->id)->where('variation', $variant->id)->firstOrFail();
    }

    private function returns(): Returns
    {
        return app(Returns::class);
    }

    public function test_the_window_counts_from_the_handoff_and_closes(): void
    {
        $order = $this->shippedOrder();
        $e = $this->returns()->eligibility($order);
        $this->assertTrue($e['offered']);
        $this->assertSame(['exchange', 'store_credit'], $e['resolutions'], 'D7: no refund while returns refunds are off');
        $this->assertSame([2, 1], array_column($e['lines'], 'qty_returnable'));
        $this->assertSame([13900, 22900], array_column($e['lines'], 'unit_minor'));

        $this->travel(15)->days();
        $this->assertFalse($this->returns()->eligibility($order)['offered']);
        $this->expectExceptionMessage('returns_closed');
        $this->returns()->request($order, [['order_item_id' => $this->line($order, ApiFixtures::SHIRT_SAND_M)->id, 'qty' => 1]], 'exchange');
    }

    public function test_no_window_no_returns_and_an_order_not_handed_over_cannot_be_returned(): void
    {
        $order = $this->shippedOrder();
        StoreSettings::set((int) $this->store->id, 'returns_window_days', 0);
        $this->assertFalse($this->returns()->eligibility($order)['offered']);

        StoreSettings::set((int) $this->store->id, 'returns_window_days', 14);
        $order->forceFill(['status' => 'packing', 'shipped_at' => null])->save();
        $this->assertFalse($this->returns()->eligibility($order->fresh())['offered']);
    }

    public function test_a_refund_is_only_offered_with_returns_refunds_on(): void
    {
        $order = $this->shippedOrder();
        $line = $this->line($order, ApiFixtures::SHIRT_SAND_M);
        try {
            $this->returns()->request($order, [['order_item_id' => $line->id, 'qty' => 1]], 'refund');
            $this->fail('a refund was accepted with returns refunds off');
        } catch (\DomainException $e) {
            $this->assertSame('resolution', $e->getMessage());
        }

        StoreSettings::set((int) $this->store->id, 'refunds_returns', true);
        $return = $this->returns()->request($order, [['order_item_id' => $line->id, 'qty' => 1]], 'refund');
        $this->assertSame(13900, $return->amount_minor);
    }

    public function test_a_line_can_be_asked_for_once_up_to_its_quantity(): void
    {
        $order = $this->shippedOrder();
        $line = $this->line($order, ApiFixtures::SHIRT_SAND_M);
        $this->returns()->request($order, [['order_item_id' => $line->id, 'qty' => 2]], 'exchange');
        try {
            $this->returns()->request($order, [['order_item_id' => $line->id, 'qty' => 1]], 'exchange');
            $this->fail('the same units were asked for twice');
        } catch (\DomainException $e) {
            $this->assertSame('line_qty', $e->getMessage());
        }
        // a line of another order is not a line of this one
        $other = $this->shippedOrder();
        $this->expectExceptionMessage('lines');
        $this->returns()->request($order, [['order_item_id' => $this->line($other, ApiFixtures::TROUSERS_NAVY_M)->id, 'qty' => 1]], 'exchange');
    }

    public function test_store_credit_flow_restocks_and_credits_the_lines_value(): void
    {
        $order = $this->shippedOrder();
        $variant = MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->firstOrFail();
        $before = (int) $variant->stock_qty;
        $return = $this->returns()->request($order, [['order_item_id' => $this->line($order, ApiFixtures::SHIRT_SAND_M)->id, 'qty' => 1]], 'store_credit', 'Too big');

        $return = $this->returns()->decide($return, true, 'OK');
        $this->assertSame('approved', $return->status);
        $return = $this->returns()->receive($return, true);
        $this->assertSame($before + 1, (int) $variant->fresh()->stock_qty, 'the returned unit is back on stock');
        $return = $this->returns()->complete($return);

        $this->assertSame('completed', $return->status);
        $this->assertSame(13900, $return->credited_minor);
        $this->assertEqualsWithDelta(139.0, CustomerWalletService::balanceFor($this->customer), 0.001);
        $this->assertSame(1, OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->count());
        $this->assertSame('store_credit', $order->fresh()->refund_method);
    }

    public function test_an_exchange_moves_no_money(): void
    {
        $order = $this->shippedOrder();
        $return = $this->returns()->request($order, [['order_item_id' => $this->line($order, ApiFixtures::TROUSERS_NAVY_M)->id, 'qty' => 1]], 'exchange');
        $return = $this->returns()->complete($this->returns()->receive($this->returns()->decide($return, true), false));
        $this->assertSame('completed', $return->status);
        $this->assertSame(0, OrderAdjustment::query()->where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($this->customer), 0.001);
    }

    public function test_completion_is_capped_by_what_is_left_to_refund(): void
    {
        $order = $this->shippedOrder();
        // the owner already refunded 450.00 of 507.00 by hand
        OrderAdjustment::create(['order_id' => $order->id, 'type' => 'refund', 'amount' => 450, 'method' => 'online', 'reason' => 'manual']);
        $return = $this->returns()->request($order, [['order_item_id' => $this->line($order, ApiFixtures::SHIRT_SAND_M)->id, 'qty' => 2]], 'store_credit');
        $return = $this->returns()->complete($this->returns()->receive($this->returns()->decide($return, true), false));
        $this->assertSame(5700, $return->credited_minor, 'only the 57.00 left is credited, not the lines\' 278.00');
        $this->assertEqualsWithDelta(57.0, CustomerWalletService::balanceFor($this->customer), 0.001);
    }

    public function test_steps_out_of_order_are_refused(): void
    {
        $order = $this->shippedOrder();
        $return = $this->returns()->request($order, [['order_item_id' => $this->line($order, ApiFixtures::SHIRT_SAND_M)->id, 'qty' => 1]], 'exchange');
        foreach ([fn () => $this->returns()->receive($return), fn () => $this->returns()->complete($return)] as $step) {
            try {
                $step();
                $this->fail('a step ran before the return was approved');
            } catch (\DomainException $e) {
                $this->assertSame('status', $e->getMessage());
            }
        }
        $this->returns()->decide($return, false);
        $this->expectExceptionMessage('status');
        $this->returns()->decide($return, true);
    }

    public function test_the_shopper_starts_a_return_from_the_tracking_token_and_the_owner_completes_it(): void
    {
        $order = $this->shippedOrder();
        $line = $this->line($order, ApiFixtures::SHIRT_SAND_M);
        $this->getJson(route('storefront.returns.show', ['token' => $order->track_token]))->assertOk()->assertJsonPath('offered', true);
        $this->postJson(route('storefront.returns.store', ['token' => $order->track_token]), [
            'resolution' => 'store_credit', 'lines' => [['order_item_id' => $line->id, 'qty' => 1]],
        ])->assertCreated()->assertJsonPath('amount_minor', 13900);
        $this->postJson(route('storefront.returns.store', ['token' => 'trk_unknown_000000']), [
            'resolution' => 'exchange', 'lines' => [['order_item_id' => $line->id, 'qty' => 1]],
        ])->assertNotFound();

        $return = ReturnRequest::query()->firstOrFail();
        $q = ['return' => $return->id, 'store_id' => $this->store->id];
        $this->actingAs($this->owner)->get(route('stores.retail.returns', ['store_id' => $this->store->id]))->assertOk()->assertSee('Store credit');
        $this->actingAs($this->owner)->post(route('stores.retail.returns.decide', $q), ['decision' => 'approve'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('stores.retail.returns.receive', $q), ['restock' => '1'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('stores.retail.returns.complete', $q))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('completed', $return->fresh()->status);
    }

    public function test_another_owner_cannot_touch_the_return(): void
    {
        $order = $this->shippedOrder();
        $return = $this->returns()->request($order, [['order_item_id' => $this->line($order, ApiFixtures::SHIRT_SAND_M)->id, 'qty' => 1]], 'exchange');
        $intruderStore = (new ApiFixtures)->freshMart();
        $intruder = User::query()->findOrFail($intruderStore->user_id);

        // their own store: the return is not in it (404); the victim's store id: not theirs (403)
        $this->actingAs($intruder)->post(route('stores.retail.returns.decide', ['return' => $return->id, 'store_id' => $intruderStore->id]), ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($intruder)->post(route('stores.retail.returns.decide', ['return' => $return->id, 'store_id' => $this->store->id]), ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('requested', $return->fresh()->status);
    }
}
