<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

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
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Models\DeliverySlotRule;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * Owner decisions 2026-10-08 (pricing): a store-wide "free delivery over X" — the basket quote shows what is still
 * missing, the server waives the store's delivery fee once the items reach X (quote, placed order, frozen totals) — and
 * a delivery pin at web checkout, so the store's delivery radius is checked server side (no pin under a radius is a
 * refusal, not a pass).
 */
class FreeDeliveryAndPinTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private ViewModelScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(12, 0)); // the kit café is open 07:00-23:00 store time
        app()->setLocale('en');
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, ThrottleRequests::class]);
        $this->seed(KitDemoSeeder::class);
        $this->store = Store::where('slug', 'bloom-coffee-demo')->firstOrFail();
        $this->s = new ViewModelScenario($this->store, 'brew');
        StoreSettings::set((int) $this->store->id, 'restaurant_delivery_order', true);
        StoreSettings::set((int) $this->store->id, 'restaurant_delivery_charge', '7');
        StoreSettings::set((int) $this->store->id, 'min_order_value', '0');
    }

    /** @return array<string, mixed> */
    private function quote(array $cart): array
    {
        return $this->postJson("/{$this->store->slug}/quote", ['lines' => $cart, 'order_type' => 'delivery'])->assertOk()->json('quote');
    }

    private function subtotalMinor(array $quote): int
    {
        return (int) collect($quote['rows'])->firstWhere('key', 'subtotal')['amount']['minor'];
    }

    /** @param  array<string, mixed>  $extra */
    private function place(array $cart, array $extra = []): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $this->store), array_merge([
            'lines' => $cart, 'order_type' => 'delivery', 'name' => 'Sam', 'phone-number' => '+966500000001',
            'address' => 'King Fahd Rd 12, Riyadh', 'pay_via' => 'cash', 'client_order_id' => (string) Str::uuid(),
        ], $extra));
    }

    public function test_below_the_threshold_the_fee_is_charged_and_the_basket_shows_what_is_missing(): void
    {
        $cart = $this->s->cart();
        $sub = $this->subtotalMinor($this->quote($cart));
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', (string) (($sub + 1000) / 100));

        $quote = $this->quote($cart);
        $rows = collect($quote['rows'])->keyBy('key');
        $this->assertSame(700, (int) $rows['delivery']['amount']['minor']);
        $this->assertSame(1000, (int) $rows['free_delivery_remaining']['amount']['minor']);
        $this->assertTrue($rows['free_delivery_remaining']['informational']);
        $this->assertStringContainsString('more for free delivery', $rows['free_delivery_remaining']['label']);

        $this->place($cart)->assertOk()->assertJson(['success' => true]);
        $order = Order::where('store_id', $this->store->id)->latest('id')->firstOrFail();
        $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, 'the fee is in the placed total');
        $this->assertEqualsWithDelta(7.0, $order->deliveryCharge(), 0.0001);
    }

    public function test_at_the_threshold_the_server_waives_the_fee_on_the_quote_and_the_order(): void
    {
        $cart = $this->s->cart();
        $before = $this->quote($cart);
        $sub = $this->subtotalMinor($before);
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', (string) ($sub / 100));

        $quote = $this->quote($cart);
        $rows = collect($quote['rows'])->keyBy('key');
        $this->assertSame(0, (int) $rows['delivery']['amount']['minor']);
        $this->assertSame('Free', $rows['delivery']['amount_label']);
        $this->assertArrayNotHasKey('free_delivery_remaining', $rows->all());
        $this->assertSame($before['total']['minor'] - 700, $quote['total']['minor']);

        $this->place($cart)->assertOk()->assertJson(['success' => true]);
        $order = Order::where('store_id', $this->store->id)->latest('id')->firstOrFail();
        $this->assertSame($quote['total']['minor'], (int) $order->grand_total_minor, 'the waived fee is not charged');
        $this->assertSame(0.0, $order->deliveryCharge());
    }

    public function test_threshold_off_or_zero_changes_nothing_and_a_takeaway_ignores_it(): void
    {
        $cart = $this->s->cart();
        foreach (['', '0', 'abc'] as $off) {
            StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', $off);
            $rows = collect($this->quote($cart)['rows'])->keyBy('key');
            $this->assertSame(700, (int) $rows['delivery']['amount']['minor'], "threshold '{$off}'");
            $this->assertArrayNotHasKey('free_delivery_remaining', $rows->all());
        }
        StoreSettings::set((int) $this->store->id, 'restaurant_free_delivery_over', '1');
        $take = $this->postJson("/{$this->store->slug}/quote", ['lines' => $cart, 'order_type' => 'takeaway'])->assertOk()->json('quote');
        $this->assertNotContains('free_delivery_remaining', array_column($take['rows'], 'key'));
    }

    private function radius(): void
    {
        DeliverySlotRule::query()->where('store_id', $this->store->id)->delete();
        DeliverySlotRule::query()->create(['store_id' => $this->store->id, 'active' => true, 'windows' => [['06:00', '23:00']],
            'capacity_per_hour' => 50, 'lead_minutes' => 0, 'days_ahead' => 7, 'radius_km' => '5', 'base_lat' => '24.7136', 'base_lng' => '46.6753']);
    }

    public function test_web_checkout_refuses_a_delivery_without_a_pin_or_outside_the_radius(): void
    {
        $this->radius();
        $cart = $this->s->cart();

        $this->place($cart)->assertOk()->assertJson(['success' => false, 'code' => 'pin_required']);
        $this->place($cart, ['delivery_lat' => '21.4858', 'delivery_lng' => '39.1925'])->assertOk()->assertJson(['success' => false, 'code' => 'out_of_zone']);
        $this->place($cart, ['delivery_lat' => 'x', 'delivery_lng' => '46.6'])->assertJson(['success' => false, 'code' => 'pin_required']);
        $this->assertSame(0, Order::where('store_id', $this->store->id)->count(), 'no order without a checked pin');

        $inside = $this->place($cart, ['delivery_lat' => '24.7200', 'delivery_lng' => '46.6800'])->json();
        $this->assertNotContains($inside['code'] ?? null, ['pin_required', 'out_of_zone'], json_encode($inside));
    }

    public function test_without_a_radius_a_delivery_needs_no_pin(): void
    {
        DeliverySlotRule::query()->where('store_id', $this->store->id)->delete();
        $this->place($this->s->cart())->assertOk()->assertJson(['success' => true]);
    }

    public function test_the_kit_checkout_asks_for_a_pin_only_under_a_radius(): void
    {
        $cart = $this->s->cart();
        $vm = fn () => $this->s->build('en', 'checkout', ['orderType' => 'delivery', 'cart' => $cart])['page']['checkout'];
        DeliverySlotRule::query()->where('store_id', $this->store->id)->delete();
        $this->assertArrayNotHasKey('pin', $vm());

        $this->radius();
        $pin = $vm()['pin'] ?? null;
        $this->assertNotNull($pin, 'a radius store asks for a pin');
        $this->assertEqualsWithDelta(24.7136, $pin['center'][0], 0.0001);
    }
}
