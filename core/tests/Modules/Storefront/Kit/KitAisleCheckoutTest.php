<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Tests\Support\AisleStore;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-08 (REVIEW Opus: money + payments + idempotency + tenancy): the goods checkout of the `aisle` storefront's own pages —
 * the cart JS's three calls (quote, shipping methods, order) on the headless API's services. What the shopper is quoted is
 * what the order charges (SAR, and KWD with a fixed tax and a shipping fee); the hosted payment goes through the store's own
 * gateway; an out-of-stock variant cannot be bought; another store's ids are unknown.
 */
class KitAisleCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private AisleStore $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(Carbon::parse('2026-10-02 06:44:50', 'UTC')); // 09:44 in Riyadh
        $this->shop = AisleStore::make();
        $this->store = $this->shop->store;
    }

    private function url(string $name): string
    {
        return route('storefront.retail.'.$name, ['slug' => $this->store->slug]);
    }

    /** @return array<string, mixed> */
    private function cart(int $qty = 1, string $variant = ApiFixtures::SHIRT_SAND_M, string $item = ApiFixtures::SHIRT): array
    {
        return [
            'order_type' => 'ship',
            'lines' => [['item' => $item, 'qty' => $qty, 'variant' => $variant]],
            'shipping_method' => ApiFixtures::SHIP_STANDARD,
            'address' => ['country' => 'SA', 'line1' => 'Al Olaya, King Fahd Rd, Bldg 12', 'city' => 'Riyadh', 'postal_code' => '12611'],
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private function place(array $cart, string $hash, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->postJson($this->url('place'), $cart + [
            'name' => 'Noura', 'phone' => '+966551234567', 'quote_hash' => $hash,
        ] + $extra, ['Idempotency-Key' => $key ?? (string) Str::uuid7()]);
    }

    private function hash(array $cart): string
    {
        return (string) $this->postJson($this->url('quote'), $cart)->assertOk()->json('quote_hash');
    }

    public function test_the_shipping_methods_for_an_address_come_with_their_dates_and_fees(): void
    {
        $res = $this->postJson($this->url('shippingQuote'), $this->cart())->assertOk();

        $res->assertJsonPath('zone', 'riyadh-north')->assertJsonPath('ships_to_address', true);
        $methods = $res->json('methods');
        $this->assertSame([ApiFixtures::SHIP_STANDARD, ApiFixtures::SHIP_EXPRESS, ApiFixtures::SHIP_PICKUP], array_column($methods, 'id'));
        $standard = $methods[0]['display'];
        $this->assertSame('Standard', $standard['label']);
        $this->assertStringContainsString('2–4 days', $standard['sub']);
        $this->assertStringContainsString('by Tue 6 Oct', $standard['sub'], 'the date comes from the server, in the store language');
        $this->assertSame('Free', $methods[2]['display']['amount_label']);
        $this->assertTrue($standard['selected']);
    }

    public function test_an_address_outside_every_zone_only_offers_pickup(): void
    {
        $cart = $this->cart();
        $cart['address'] = ['country' => 'AE', 'city' => 'Dubai'];
        $res = $this->postJson($this->url('shippingQuote'), $cart)->assertOk();

        $res->assertJsonPath('ships_to_address', false);
        $this->assertSame([ApiFixtures::SHIP_PICKUP], array_column($res->json('methods'), 'id'));
    }

    public function test_the_quote_carries_the_shipping_row_the_free_shipping_progress_and_a_hash(): void
    {
        $res = $this->postJson($this->url('quote'), $this->cart())->assertOk();

        $rows = collect($res->json('quote.rows'))->keyBy('key');
        $this->assertSame(1500, $rows['shipping']['amount']['minor']);
        $this->assertSame('Shipping · Standard', $rows['shipping']['label']);
        $this->assertSame(15400, $res->json('quote.total.minor'));
        $this->assertStringStartsWith('qh_', $res->json('quote_hash'));
        $this->assertStringContainsString('61.00', $res->json('quote.free_delivery.label'));
        $this->assertStringContainsString('more for free shipping', $res->json('quote.free_delivery.label'));
        $this->assertEqualsWithDelta(0.695, $res->json('quote.free_delivery.progress'), 0.001);
        $this->assertContains('pay_later', $res->json('payment_modes'));

        $free = $this->postJson($this->url('quote'), $this->cart(2))->assertOk();
        $this->assertSame(27800, $free->json('quote.total.minor'), 'SAR 278 is over the SAR 200 threshold: standard is free');
        $this->assertSame(0, collect($free->json('quote.rows'))->firstWhere('key', 'shipping')['amount']['minor']);
        $this->assertSame(0, $free->json('quote.free_delivery.remaining.minor'));
    }

    public function test_what_the_shopper_is_quoted_is_what_the_order_charges(): void
    {
        $cart = $this->cart();
        $quote = $this->postJson($this->url('quote'), $cart)->assertOk();
        $res = $this->place($cart, $quote->json('quote_hash'))->assertCreated()->assertJsonPath('success', true);

        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame($quote->json('quote.total.minor'), Money::minorFromMajor((float) $order->fresh(['items'])->total, 'SAR'));
        $this->assertSame(1500, Money::minorFromMajor((float) $order->delivery_fee, 'SAR'));
        $this->assertSame('ship', $order->type);
        $this->assertNotNull($order->shipping_method_id);
        $this->assertStringEndsWith('/order/track/'.$order->track_token, $res->json('track_url'));
        $this->assertArrayNotHasKey('payment_url', $res->json(), 'pay later: no payment page');
        $this->assertSame(1, (int) MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->value('stock_qty'), 'the order took the unit');
    }

    public function test_kuwaiti_dinar_fixed_tax_and_shipping_quote_equals_the_order(): void
    {
        StoreSettings::setMany((int) $this->store->id, ['currency_code' => 'KWD', 'currency_sign' => 'KWD', 'restaurant_tax_type' => 'fixed', 'restaurant_taxx_charge' => '0.750', 'restaurant_tax_label' => 'Service']);
        ShippingMethod::query()->where('store_id', $this->store->id)->update(['currency_code' => 'KWD', 'fee_minor' => 1500, 'free_over_minor' => null]);

        $cart = $this->cart();
        $quote = $this->postJson($this->url('quote'), $cart)->assertOk();
        // KWD has three decimals: 139.000 + 1.500 shipping + 0.750 fixed tax
        $this->assertSame(141250, $quote->json('quote.total.minor'));
        $this->place($cart, $quote->json('quote_hash'))->assertCreated();

        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame(141250, Money::minorFromMajor((float) $order->fresh(['items'])->total, 'KWD'));
        $this->assertSame(750, Money::minorFromMajor((float) $order->fresh(['items'])->tax_total, 'KWD'));
    }

    public function test_a_price_that_moved_since_the_quote_is_refused_with_the_new_total(): void
    {
        $cart = $this->cart();
        $hash = $this->hash($cart);
        MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->update(['price_minor' => 15000]);

        $res = $this->place($cart, $hash)->assertStatus(409);
        $res->assertJsonPath('code', 'quote_changed');
        $this->assertSame(0, Order::query()->where('store_id', $this->store->id)->count());
    }

    public function test_an_out_of_stock_variant_cannot_be_quoted_or_ordered(): void
    {
        $xs = 'RLS-SAN-XS';
        $cart = $this->cart(1, $xs);
        $this->postJson($this->url('quote'), $cart)->assertStatus(422)->assertJsonPath('code', 'out_of_stock');
        $this->place($cart, 'qh_x')->assertStatus(422);
        $this->assertSame(0, Order::query()->where('store_id', $this->store->id)->count());
    }

    public function test_the_last_unit_is_not_sold_twice(): void
    {
        MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->update(['stock_qty' => 1]);
        $cart = $this->cart();
        $hash = $this->hash($cart);
        $this->place($cart, $hash)->assertCreated();

        $second = $this->place($cart, $hash)->assertStatus(422);
        $this->assertSame(1, Order::query()->where('store_id', $this->store->id)->count());
        $this->assertSame(0, (int) MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->value('stock_qty'));
        $this->assertNotNull($second->json('code'));
    }

    public function test_a_resend_with_the_same_key_is_the_same_order_and_another_cart_under_it_is_refused(): void
    {
        $cart = $this->cart();
        $hash = $this->hash($cart);
        $key = (string) Str::uuid7();
        $first = $this->place($cart, $hash, [], $key)->assertCreated();
        $again = $this->place($cart, $hash, [], $key)->assertOk();

        $this->assertTrue($again->json('duplicate'));
        $this->assertSame($first->json('track_url'), $again->json('track_url'));
        $this->assertSame(1, Order::query()->where('store_id', $this->store->id)->count());

        $other = $this->cart(1, 'RLS-SAN-S');
        $this->place($other, $this->hash($other), [], $key)->assertStatus(409);
        $this->assertSame(1, Order::query()->where('store_id', $this->store->id)->count());
    }

    public function test_an_order_needs_its_idempotency_key(): void
    {
        $cart = $this->cart();
        $this->postJson($this->url('place'), $cart + ['name' => 'N', 'quote_hash' => $this->hash($cart)])->assertStatus(422);
    }

    public function test_an_online_order_goes_to_the_stores_own_pay_page_and_returns_to_its_tracking_page(): void
    {
        config(['app.e2e_stubs' => true]);
        PaymentGateway::query()->updateOrCreate(['payment_folder' => 'stub'], ['payment_install' => '1', 'payment_title' => 'E2E Stub Gateway']);
        $cart = $this->cart();

        $quote = $this->postJson($this->url('quote'), $cart)->assertOk();
        $this->assertContains('hosted', $quote->json('payment_modes'));
        $res = $this->place($cart, $quote->json('quote_hash'), ['pay_via' => 'pay_online'])->assertCreated();

        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame('unpaid', $order->status);
        $this->assertSame('hosted', $order->payment_mode);
        $tx = app(HostedPayments::class)->transactionFor($order);
        $this->assertNotNull($tx);
        $this->assertSame(route('payment.index', $tx->getKey()), $res->json('payment_url'));
        $this->assertSame((int) $this->store->id, (int) $tx->store_id, 'the store\'s own payment');
        $this->assertArrayNotHasKey('return_url', (array) $tx->fresh()->details, 'no outside address is kept: the pay page hands the shopper to the tracking page');

        $paid = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stub', 'e2e_outcome' => 'success'])->assertOk();
        $this->assertStringContainsString('/order/track/'.$order->track_token, $paid->getContent());
        $order->refresh();
        $this->assertSame('new', $order->status);
        $this->assertTrue((bool) $order->is_paid);
    }

    public function test_another_stores_item_is_unknown_here(): void
    {
        $other = (new ApiFixtures)->freshMart();
        $foreign = \Juwri\Modules\Menu\Models\Menu::query()->where('store_id', $other->id)->first()
            ?? $this->makeForeignItem($other);
        $cart = $this->cart(1, ApiFixtures::SHIRT_SAND_M, (string) $foreign->public_id);

        $this->postJson($this->url('quote'), $cart)->assertStatus(422);
        $this->assertSame(0, Order::query()->where('store_id', $this->store->id)->count());
        // and the store is the route's, never the body's
        $this->postJson(route('storefront.retail.quote', ['slug' => 'no-such-store']), $this->cart())->assertStatus(404);
    }

    private function makeForeignItem(Store $store): \Juwri\Modules\Menu\Models\Menu
    {
        $category = \App\Models\MenuCategory::create(['store_id' => $store->id, 'name' => 'Basics', 'active' => 1]);

        return (new ApiFixtures)->item($store, $category, (string) Str::uuid7(), 'Foreign thing', 5);
    }
}
