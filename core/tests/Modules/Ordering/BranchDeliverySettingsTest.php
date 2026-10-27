<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Accounting\Services\Accounting\OrderInvoice;
use Juwri\Modules\Ordering\Models\DeliverySlotRule;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P5-31: optional branch-level overrides of the store's prep time, delivery fee and minimum order. Null = the store's
 * value. An order at a branch with its own fee charges it on the quote, the placed order, its frozen totals and the
 * receipt; the store's "free delivery over X" waives a branch's own fee too unless the owner switched that off (P5-34); the minimum order
 * is checked per branch; a branch of another store never prices this store's order.
 */
class BranchDeliverySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private ViewModelScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0));
        app()->setLocale('en');
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(KitDemoSeeder::class);
        $this->store = Store::where('slug', 'bloom-coffee-demo')->firstOrFail();
        $this->s = new ViewModelScenario($this->store, 'brew');
        DeliverySlotRule::query()->where('store_id', $this->store->id)->delete();
        StoreSettings::set((int) $this->store->id, 'restaurant_delivery_order', true);
        StoreSettings::set((int) $this->store->id, 'restaurant_delivery_charge', '7');
        StoreSettings::set((int) $this->store->id, 'min_order_value', '0');
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', '');
    }

    /** @param  array<string, mixed>  $overrides */
    private function branch(Store $store, string $name, array $overrides = []): Branch
    {
        $b = Branch::create(['store_id' => $store->id, 'name' => $name, 'active' => 1, 'accepting_orders' => 1]);
        if ($overrides !== []) {
            $b->forceFill($overrides)->save();
        }

        return $b->fresh();
    }

    /** @return array<string, mixed> */
    private function quote(array $cart, ?Branch $branch): array
    {
        $body = ['lines' => $cart, 'order_type' => 'delivery'] + ($branch ? ['branch' => $branch->public_id] : []);

        return $this->postJson("/{$this->store->slug}/quote", $body)->assertOk()->json('quote');
    }

    private function place(array $cart, ?Branch $branch): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $this->store), [
            'lines' => $cart, 'order_type' => 'delivery', 'name' => 'Sam', 'phone-number' => '+966500000001',
            'address' => 'King Fahd Rd 12, Riyadh', 'pay_via' => 'cash', 'client_order_id' => (string) Str::uuid(),
        ] + ($branch ? ['branch_id' => $branch->id] : []));
    }

    private function deliveryMinor(array $quote): int
    {
        return (int) collect($quote['rows'])->firstWhere('key', 'delivery')['amount']['minor'];
    }

    private function lastOrder(): Order
    {
        return Order::where('store_id', $this->store->id)->latest('id')->firstOrFail();
    }

    public function test_the_branch_fee_is_charged_on_the_quote_the_placed_order_and_the_receipt(): void
    {
        $branch = $this->branch($this->store, 'North', ['delivery_fee' => '12.5']);
        $this->assertSame(1250, (int) $branch->delivery_fee_minor);
        $cart = $this->s->cart();

        $quote = $this->quote($cart, $branch);
        $this->assertSame(1250, $this->deliveryMinor($quote));

        $this->place($cart, $branch)->assertOk()->assertJson(['success' => true]);
        $order = $this->lastOrder();
        $this->assertSame((int) $branch->id, (int) $order->branch_id);
        $this->assertEqualsWithDelta(12.5, $order->deliveryCharge(), 0.0001);
        $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, 'quote = placed (frozen) total');

        $receipt = OrderInvoice::fromOrder($order->fresh());
        $delivery = collect($receipt->lines)->firstWhere('kind', OrderInvoice::DELIVERY);
        $this->assertNotNull($delivery);
        $this->assertSame(1250, $delivery['amount_minor'], 'the receipt shows the branch fee');
        $this->assertSame((int) $order->grand_total_minor, $receipt->totalMinor);
    }

    public function test_a_null_override_falls_back_to_the_store_values(): void
    {
        $branch = $this->branch($this->store, 'Plain');
        $this->assertNull($branch->deliveryFeeOverride());
        $this->assertNull($branch->minOrderOverride());
        $this->assertNull($branch->prepMinutesOverride());
        $cart = $this->s->cart();

        $quote = $this->quote($cart, $branch);
        $this->assertSame(700, $this->deliveryMinor($quote));
        $this->place($cart, $branch)->assertOk()->assertJson(['success' => true]);
        $this->assertEqualsWithDelta(7.0, $this->lastOrder()->deliveryCharge(), 0.0001);

        // and a zero override is a real override (free delivery at that branch)
        $free = $this->branch($this->store, 'Free', ['delivery_fee' => '0']);
        $this->assertSame(0, $this->deliveryMinor($this->quote($cart, $free)));
    }

    /**
     * P5-34: "free delivery over X" waives a branch's own fee by default (setting unset = ON): the quote, the placed
     * order, its frozen total, the receipt, the GL fee and the Odoo line all read Order::deliveryCharge.
     */
    public function test_by_default_the_store_free_delivery_threshold_also_waives_a_branch_fee(): void
    {
        $branch = $this->branch($this->store, 'North', ['delivery_fee' => '12.5']);
        $this->assertFalse(StoreSettings::has((int) $this->store->id, 'restaurant_free_delivery_covers_branch_fee'));
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', '0.01');
        $cart = $this->s->cart();

        $quote = $this->quote($cart, $branch);
        $this->assertSame(0, $this->deliveryMinor($quote), 'the branch fee is waived on the quote');
        $this->assertNull(collect($quote['rows'])->firstWhere('key', 'free_delivery_remaining'));

        $this->place($cart, $branch)->assertOk()->assertJson(['success' => true]);
        $order = $this->lastOrder();
        $this->assertSame((int) $branch->id, (int) $order->branch_id);
        $this->assertEqualsWithDelta(0.0, $order->deliveryCharge(), 0.0001);
        $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, 'quote = placed (frozen) total');
        $receipt = OrderInvoice::fromOrder($order->fresh());
        $this->assertSame((int) $order->grand_total_minor, $receipt->totalMinor);
        $delivery = collect($receipt->lines)->firstWhere('kind', OrderInvoice::DELIVERY);
        $this->assertSame(0, (int) ($delivery['amount_minor'] ?? 0), 'the receipt charges no delivery');
    }

    public function test_switched_off_the_branch_fee_always_applies_and_the_store_fee_is_still_waived(): void
    {
        $branch = $this->branch($this->store, 'North', ['delivery_fee' => '12.5']);
        $plain = $this->branch($this->store, 'Plain');
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', '0.01');
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_covers_branch_fee', false);
        $cart = $this->s->cart();

        $this->assertSame(0, $this->deliveryMinor($this->quote($cart, $plain)), 'the store fee is waived');
        $quote = $this->quote($cart, $branch);
        $this->assertSame(1250, $this->deliveryMinor($quote), 'the branch fee is not');
        $this->assertNull(collect($quote['rows'])->firstWhere('key', 'free_delivery_remaining'), 'no "add X more" nudge for a fee that never waives');

        $this->place($cart, $branch)->assertOk()->assertJson(['success' => true]);
        $order = $this->lastOrder();
        $this->assertEqualsWithDelta(12.5, $order->deliveryCharge(), 0.0001);
        $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, 'quote = placed (frozen) total');
        $receipt = OrderInvoice::fromOrder($order->fresh());
        $this->assertSame(1250, collect($receipt->lines)->firstWhere('kind', OrderInvoice::DELIVERY)['amount_minor']);
        $this->assertSame((int) $order->grand_total_minor, $receipt->totalMinor);
    }

    public function test_below_the_threshold_the_branch_fee_is_charged_and_the_nudge_follows_the_setting(): void
    {
        $branch = $this->branch($this->store, 'North', ['delivery_fee' => '12.5']);
        $cart = $this->s->cart();
        $sub = (int) collect($this->quote($cart, null)['rows'])->firstWhere('key', 'subtotal')['amount']['minor'];
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', (string) (($sub + 1000) / 100));

        $on = $this->quote($cart, $branch);
        $this->assertSame(1250, $this->deliveryMinor($on));
        $this->assertSame(1000, (int) collect($on['rows'])->firstWhere('key', 'free_delivery_remaining')['amount']['minor']);

        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_covers_branch_fee', '0');
        $off = $this->quote($cart, $branch);
        $this->assertSame(1250, $this->deliveryMinor($off));
        $this->assertNull(collect($off['rows'])->firstWhere('key', 'free_delivery_remaining'));
    }

    public function test_the_setting_reads_default_on_and_off_only_when_saved_off(): void
    {
        $this->assertTrue(Order::freeDeliveryCoversBranchFee(null));
        $this->assertTrue(Order::freeDeliveryCoversBranchFee((object) []));
        $this->assertTrue(Order::freeDeliveryCoversBranchFee((object) ['restaurant_free_delivery_covers_branch_fee' => '']));
        $this->assertTrue(Order::freeDeliveryCoversBranchFee((object) ['restaurant_free_delivery_covers_branch_fee' => true]));
        $this->assertTrue(Order::freeDeliveryCoversBranchFee((object) ['restaurant_free_delivery_covers_branch_fee' => '1']));
        $this->assertFalse(Order::freeDeliveryCoversBranchFee((object) ['restaurant_free_delivery_covers_branch_fee' => false]));
        $this->assertFalse(Order::freeDeliveryCoversBranchFee((object) ['restaurant_free_delivery_covers_branch_fee' => '0']));
        $this->assertFalse(Order::freeDeliveryCoversBranchFee((object) ['restaurant_free_delivery_covers_branch_fee' => 0]));
    }

    public function test_the_minimum_order_is_checked_per_branch(): void
    {
        $cart = $this->s->cart();
        $sub = (int) collect($this->quote($cart, null)['rows'])->firstWhere('key', 'subtotal')['amount']['minor'];
        $high = $this->branch($this->store, 'High', ['min_order' => (string) (($sub + 10000) / 100)]);
        $plain = $this->branch($this->store, 'Plain');

        $refused = $this->place($cart, $high)->json();
        $this->assertFalse($refused['success'] ?? true, 'below the branch minimum is refused: '.json_encode($refused));
        $this->assertSame(0, Order::where('store_id', $this->store->id)->count());

        $this->place($cart, $plain)->assertOk()->assertJson(['success' => true]);

        // the store minimum applies to a branch without its own; a branch's 0 lifts it
        StoreSettings::set((int) $this->store->id, 'min_order_value', (string) (($sub + 10000) / 100));
        $this->assertFalse($this->place($cart, $plain)->json('success'));
        $none = $this->branch($this->store, 'None', ['min_order' => '0']);
        $this->place($cart, $none)->assertOk()->assertJson(['success' => true]);
    }

    public function test_a_branch_of_another_store_never_prices_this_store(): void
    {
        $other = Store::query()->whereKeyNot($this->store->id)->first()
            ?? tap($this->store->replicate(['public_id']), function (Store $s) {
                $s->slug = 'other-'.Str::lower(Str::random(6));
                $s->save();
            });
        $foreign = $this->branch($other, 'Foreign', ['delivery_fee' => '99', 'min_order' => '999', 'prep_time_minutes' => 99]);

        $this->assertNull(Branch::ofStore((int) $this->store->id, $foreign->id));
        $this->assertSame(7.0, Branch::effectiveDeliveryFee((int) $this->store->id, $foreign));
        $this->assertNull(Branch::effectiveMinOrder((int) $this->store->id, $foreign));

        $order = new Order(['store_id' => $this->store->id, 'type' => 'delivery']);
        $order->branch_id = $foreign->id;
        $this->assertNull($order->pricingBranch());
        $this->assertEqualsWithDelta(7.0, $order->deliveryCharge(), 0.0001);

        // the web checkout ignores another store's branch id: the store's own fee applies
        $cart = $this->s->cart();
        $this->place($cart, $foreign)->assertOk()->assertJson(['success' => true]);
        $placed = $this->lastOrder();
        $this->assertNotSame((int) $foreign->id, (int) $placed->branch_id);
        $this->assertEqualsWithDelta(7.0, $placed->deliveryCharge(), 0.0001);
    }

    public function test_the_owner_edits_and_clears_the_overrides(): void
    {
        $branch = $this->branch($this->store, 'North');
        $this->actingAs($this->store->user);
        $base = ['name' => 'North', 'active' => 1, 'accepting_orders' => 1];

        $this->put(route('branches.update', $branch->id), $base + ['prep_time_minutes' => '20', 'delivery_fee' => '9.75', 'min_order' => '40'])->assertRedirect();
        $branch->refresh();
        $this->assertSame(20, $branch->prep_time_minutes);
        $this->assertSame(975, (int) $branch->delivery_fee_minor);
        $this->assertSame(4000, (int) $branch->min_order_minor);

        $this->put(route('branches.update', $branch->id), $base + ['prep_time_minutes' => '', 'delivery_fee' => '', 'min_order' => ''])->assertRedirect();
        $branch->refresh();
        $this->assertNull($branch->prep_time_minutes);
        $this->assertNull($branch->delivery_fee_minor);
        $this->assertNull($branch->min_order_minor);

        $this->put(route('branches.update', $branch->id), $base + ['delivery_fee' => '-1'])->assertSessionHasErrors('delivery_fee');
    }
}
