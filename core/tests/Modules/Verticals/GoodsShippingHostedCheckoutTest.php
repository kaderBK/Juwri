<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-08 — the goods (theme) checkout with shipping methods and online payment on the hosted page (F3, API-2):
 *
 *  - shipping money: the chosen method's fee (minor units, free from the method's threshold) is the order's delivery
 *    fee and is in the total; the delivery window is stored; an address outside every zone, or no method, is refused;
 *  - the order paid online waits `unpaid` with a hosted payment window and a pay link to the store's OWN gateway
 *    (the Stub gateway here); the gateway's success places it (`new`, paid);
 *  - the customer's take of a variant is race-safe and strict: the last unit cannot be sold twice.
 */
class GoodsShippingHostedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(Carbon::parse('2026-10-02 06:44:50', 'UTC')); // 09:44 Riyadh
        $this->store = (new ApiFixtures)->duneRetail();
    }

    private function addShirtM(int $qty = 1): void
    {
        $shirt = Menu::query()->where('public_id', ApiFixtures::SHIRT)->firstOrFail();
        $variant = MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->firstOrFail();
        $this->post(route('storefront.goods.cart.add', ['slug' => $this->store->slug, 'menu' => $shirt->id]), [
            'variation' => $variant->id, 'quantity' => $qty,
        ])->assertRedirect();
    }

    /** @param  array<string, mixed>  $extra */
    private function place(array $extra = []): TestResponse
    {
        return $this->postJson(route('storefront.checkout.place', ['slug' => $this->store->slug]), array_merge([
            'type' => 'ship',
            'address' => 'Al Olaya, King Fahd Rd, Bldg 12',
            'city' => 'Riyadh', 'country' => 'SA', 'postal_code' => '12611',
            'customer_name' => 'Noura', 'phone_number' => '+966551234567',
            'shipping_method' => ApiFixtures::SHIP_STANDARD,
        ], $extra));
    }

    public function test_the_shipping_fee_is_the_orders_delivery_fee_and_in_the_total(): void
    {
        $this->addShirtM();
        $this->place()->assertOk()->assertJsonPath('success', true);

        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        // SAR 139 is under the SAR 200 free threshold: standard costs SAR 15.00
        $this->assertSame(1500, Money::minorFromMajor((float) $order->delivery_fee, 'SAR'));
        $this->assertSame(15400, Money::minorFromMajor((float) $order->fresh(['items'])->total, 'SAR'));
        $this->assertSame('2026-10-04', $order->shipping_eta_from?->toDateString());
        $this->assertSame('2026-10-06', $order->shipping_eta_to?->toDateString());
        $this->assertNotNull($order->shipping_method_id);
    }

    public function test_the_threshold_makes_standard_free_and_express_keeps_its_fee(): void
    {
        $this->addShirtM(2); // SAR 278 ≥ 200
        $this->place()->assertOk();
        $order = Order::query()->where('store_id', $this->store->id)->latest('id')->firstOrFail();
        $this->assertSame(0, Money::minorFromMajor((float) $order->delivery_fee, 'SAR'));
        $this->assertSame(27800, Money::minorFromMajor((float) $order->fresh(['items'])->total, 'SAR'));
    }

    public function test_express_fee_and_its_next_day_window(): void
    {
        $this->addShirtM();
        $this->place(['shipping_method' => ApiFixtures::SHIP_EXPRESS])->assertOk();
        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame(2500, Money::minorFromMajor((float) $order->delivery_fee, 'SAR'));
        $this->assertSame('2026-10-03', $order->shipping_eta_from?->toDateString());
    }

    public function test_an_address_outside_every_zone_and_a_missing_method_are_refused(): void
    {
        $this->addShirtM();
        $this->place(['country' => 'AE', 'city' => 'Dubai'])->assertStatus(422)->assertJsonValidationErrors('shipping_method');
        $this->place(['shipping_method' => ''])->assertStatus(422)->assertJsonValidationErrors('shipping_method');
        $this->assertSame(0, Order::query()->where('store_id', $this->store->id)->count());
        $this->assertSame(2, (int) MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->value('stock_qty'), 'a refused order takes no stock');
    }

    public function test_the_shipping_quote_endpoint_lists_the_methods_for_the_cart_and_address(): void
    {
        $this->addShirtM();
        $res = $this->postJson(route('storefront.shipping.quote', ['slug' => $this->store->slug]), ['country' => 'sa', 'city' => 'Riyadh'])->assertOk();
        $res->assertJsonPath('zone', 'riyadh-north');
        $this->assertSame([ApiFixtures::SHIP_STANDARD, ApiFixtures::SHIP_EXPRESS, ApiFixtures::SHIP_PICKUP], array_column($res->json('methods'), 'id'));
        $this->assertSame([1500, 2500, 0], array_column($res->json('methods'), 'fee_minor'));

        $out = $this->postJson(route('storefront.shipping.quote', ['slug' => $this->store->slug]), ['country' => 'AE'])->assertOk();
        $out->assertJsonPath('ships_to_address', false);
        $this->assertSame([ApiFixtures::SHIP_PICKUP], array_column($out->json('methods'), 'id'), 'only pickup outside the zones');
    }

    public function test_an_online_order_waits_for_the_hosted_page_and_the_stores_gateway_places_it(): void
    {
        config(['app.e2e_stubs' => true]);
        PaymentGateway::query()->updateOrCreate(['payment_folder' => 'stub'], ['payment_install' => '1', 'payment_title' => 'E2E Stub Gateway']);
        $this->addShirtM();

        $res = $this->place(['payment_mode' => 'hosted'])->assertOk();
        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame('unpaid', $order->status);
        $this->assertSame('hosted', $order->payment_mode);
        $this->assertNotNull($order->payment_expires_at);
        $tx = app(HostedPayments::class)->transactionFor($order);
        $this->assertNotNull($tx, 'the hosted payment row was opened');
        $res->assertJsonPath('payment_url', route('payment.index', $tx->getKey()));
        $this->assertSame(1, (int) MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->value('stock_qty'), 'the stock is held for the window');

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stub', 'e2e_outcome' => 'success'])->assertOk();
        $order->refresh();
        $this->assertSame('new', $order->status);
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame((int) $this->store->id, (int) $tx->fresh()->store_id, 'the store\'s own payment, never the platform\'s');
    }

    public function test_the_last_unit_cannot_be_sold_twice(): void
    {
        MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->update(['stock_qty' => 1]);
        $this->addShirtM();
        $this->place()->assertOk();

        // a second shopper had the same unit in their cart before it sold
        $this->flushSession();
        $this->addShirtM();
        MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->update(['stock_qty' => 0]);
        $this->place()->assertStatus(422);
        $this->assertSame(1, Order::query()->where('store_id', $this->store->id)->count());
        $this->assertSame(0, (int) MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->value('stock_qty'));
    }
}
