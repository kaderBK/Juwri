<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;
use Tests\Support\AisleStore;
use Tests\Support\BasketStore;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-09 (REVIEW Opus: money + payments + idempotency + tenancy): the grocery checkout of the `basket` storefront's own pages —
 * the cart JS's calls (hourly slots, the 10-minute hold, quote, order) on the headless API's services. The delivery time is a slot
 * of the store with its capacity; a hold keeps it and belongs to the shopper's token; what the shopper is quoted (items, the weighed
 * estimate, the delivery row naming the time, express) is what the order charges; the weighed card hold and its copy follow the
 * store's refund switch; another store's holds and slots are unknown.
 */
class KitBasketCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private BasketStore $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(BasketStore::clock());
        $this->shop = BasketStore::make();
        $this->store = $this->shop->store;
    }

    private function url(string $name, array $extra = []): string
    {
        return route('storefront.'.$name, ['slug' => $this->store->slug] + $extra);
    }

    /** the first slot of Friday with room, with its day */
    private function firstOpenSlot(string $type = 'delivery'): string
    {
        $days = $this->getJson($this->url('grocery.slots').'?order_type='.$type)->assertOk()->json('days');
        foreach ($days as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['state'] === 'available') {
                    return $slot['id'];
                }
            }
        }
        $this->fail('no open slot');
    }

    /** @return array{id: string, token: string} */
    private function hold(string $slot): array
    {
        $res = $this->postJson($this->url('grocery.hold'), ['slot_id' => $slot])->assertCreated();

        return ['id' => $res->json('id'), 'token' => $res->json('token')];
    }

    /** @return array<string, mixed> Bananas x2, Tomatoes 500 g (weighed), Sourdough: 41.90 with the SAR 9 delivery */
    private function cart(array $extra = []): array
    {
        return array_merge([
            'order_type' => 'delivery',
            'lines' => [
                ['item' => ApiFixtures::BANANAS, 'qty' => 2],
                ['item' => ApiFixtures::TOMATOES, 'qty' => 1, 'options' => [ApiFixtures::TOMATO_STEPS[500]]],
                ['item' => '019e8646-1380-7aeb-91aa-3627eb8ec48e', 'qty' => 1],
            ],
            'country' => 'SA', 'line1' => 'Al Olaya, King Fahd Rd, Bldg 12', 'city' => 'Riyadh',
        ], $extra);
    }

    private function quote(array $cart): TestResponse
    {
        return $this->postJson($this->url('retail.quote'), $cart)->assertOk();
    }

    /** @param  array<string, mixed>  $extra */
    private function place(array $cart, string $hash, ?string $token = null, array $extra = []): TestResponse
    {
        return $this->postJson($this->url('retail.place'), $cart + [
            'name' => 'Noura', 'phone' => '+966551234567', 'quote_hash' => $hash,
        ] + $extra, ['Idempotency-Key' => (string) Str::uuid7()] + ($token !== null ? ['X-Hold-Token' => $token] : []));
    }

    // ------------------------------------------------------------------ slots

    public function test_the_slots_of_a_day_come_with_their_capacity_the_express_option_and_the_picker(): void
    {
        $res = $this->getJson($this->url('grocery.slots').'?order_type=delivery')->assertOk();

        $res->assertJsonPath('timezone', 'Asia/Riyadh')->assertJsonPath('express.available', true)->assertJsonPath('express.fee.minor', 500);
        $this->assertSame('no-store, private', $res->headers->get('Cache-Control'));
        $states = collect($res->json('days.0.slots'))->pluck('state', 'display.label');
        $this->assertSame('available', $states['11:00 – 12:00']);
        $this->assertSame('full', $states['13:00 – 14:00'], 'the seats taken by other orders fill it');
        $this->assertSame('few', $states['16:00 – 17:00']);
        $this->assertSame('Delivery time', $res->json('picker.title'));
        $this->assertSame('Today', $res->json('picker.days.0.label'));
        $this->assertSame('Express · 25–35 min', $res->json('picker.express.label'));
        $this->assertStringContainsString('Riyadh time (GMT+3)', $res->json('picker.timezone_note'));
    }

    public function test_the_slots_of_an_unoffered_order_type_or_a_malformed_query_are_refused(): void
    {
        $this->getJson($this->url('grocery.slots').'?order_type=dine_in')->assertStatus(422)->assertJsonPath('code', 'invalid_order_type');
        $this->getJson($this->url('grocery.slots').'?order_type=delivery&days=99')->assertStatus(422);
        $this->getJson($this->url('grocery.slots'))->assertStatus(422);
    }

    public function test_the_view_model_names_the_grocery_endpoints_the_slot_picker_and_the_out_of_stock_choice(): void
    {
        $vm = $this->builder()->build($this->request('home'));
        $this->assertSame(route('storefront.retail.quote', ['slug' => $this->store->slug], false), $vm['endpoints']['quote']);
        $this->assertSame(route('storefront.retail.place', ['slug' => $this->store->slug], false), $vm['endpoints']['place']);
        $this->assertSame(route('storefront.grocery.slots', ['slug' => $this->store->slug], false), $vm['endpoints']['slots']);
        $this->assertSame(route('storefront.grocery.hold', ['slug' => $this->store->slug], false), $vm['endpoints']['hold']);

        $checkout = $this->builder()->checkoutForm($this->request('home'));
        $this->assertSame('Delivery time', $checkout['slots']['title']);
        $this->assertSame('express', $checkout['slots']['express']['id']);
        $this->assertSame("If it's out of stock", $checkout['substitution']['label']);
        $this->assertSame(['Replace with similar', 'Call me', 'Remove it'], array_column($checkout['substitution']['options'], 'label'));
        $this->assertTrue($checkout['substitution']['options'][0]['selected'], 'the store default is preselected');
        $this->assertSame(['name', 'mobile', 'country', 'address', 'city', 'notes'], array_column($checkout['fields'], 'id'));
        $this->assertSame('hidden', $checkout['fields'][2]['type']);
    }

    // ------------------------------------------------------------------ holds

    public function test_a_hold_keeps_a_slot_for_ten_minutes_and_belongs_to_its_token(): void
    {
        $slot = $this->firstOpenSlot();
        $res = $this->postJson($this->url('grocery.hold'), ['slot_id' => $slot])->assertCreated();
        $this->assertSame(600, $res->json('ttl_seconds'));
        $this->assertSame('We hold this time for 10 minutes.', $res->json('hold_note'));
        $this->assertStringStartsWith('hld_', $res->json('token'));
        $this->assertSame(1, DB::table('slot_seats')->whereNotNull('hold_id')->count());

        $id = $res->json('id');
        $this->deleteJson($this->url('grocery.release', ['hold' => $id]), [], ['X-Hold-Token' => 'hld_not_mine'])->assertNotFound();
        $this->assertSame(1, DB::table('slot_seats')->whereNotNull('hold_id')->count(), 'a wrong token releases nothing');
        $this->deleteJson($this->url('grocery.release', ['hold' => $id]), [], ['X-Hold-Token' => $res->json('token')])->assertNoContent();
        $this->assertSame(0, DB::table('slot_seats')->whereNotNull('hold_id')->count());
    }

    public function test_a_full_slot_cannot_be_held_and_names_the_next_free_one(): void
    {
        $slots = $this->getJson($this->url('grocery.slots').'?order_type=delivery')->json('days.0.slots');
        $full = collect($slots)->firstWhere('state', 'full')['id'];
        $res = $this->postJson($this->url('grocery.hold'), ['slot_id' => $full])->assertStatus(409);
        $res->assertJsonPath('code', 'slot_full');
        $this->assertSame('available', $res->json('details.next_free_slot.state'));
        $this->postJson($this->url('grocery.hold'), ['slot_id' => 'not-a-slot'])->assertStatus(422);
        $this->postJson($this->url('grocery.hold'), [])->assertStatus(422);
    }

    public function test_another_stores_hold_is_unknown_here(): void
    {
        $hold = $this->hold($this->firstOpenSlot());
        $other = AisleStore::make('aisle')->store;
        $this->assertNotSame($this->store->id, $other->id);

        $this->deleteJson(route('storefront.grocery.release', ['slug' => $other->slug, 'hold' => $hold['id']]), [], ['X-Hold-Token' => $hold['token']])->assertNotFound();
        $this->assertSame(1, DB::table('slot_seats')->whereNotNull('hold_id')->count(), "the other store's route did not free it");
        $this->getJson(route('storefront.grocery.slots', ['slug' => 'no-such-store']).'?order_type=delivery')->assertNotFound();
    }

    // ------------------------------------------------------------------ quote

    public function test_the_quote_names_the_time_in_the_delivery_row_and_asks_for_one_when_there_is_none(): void
    {
        $bare = $this->quote($this->cart());
        $this->assertContains('slot', $bare->json('requires'), 'a store with slots needs one');
        $this->assertSame(4190, $bare->json('quote.total.minor'));

        $slot = $this->firstOpenSlot();
        $hold = $this->hold($slot);
        $res = $this->quote($this->cart(['slot_id' => $slot, 'hold_id' => $hold['id']]));
        $this->assertNotContains('slot', $res->json('requires'));
        $rows = collect($res->json('quote.rows'))->keyBy('key');
        $this->assertSame('Delivery · 11:00 – 12:00', $rows['delivery']['label']);
        $this->assertSame(900, $rows['delivery']['amount']['minor']);
        $this->assertSame(4190, $res->json('quote.total.minor'));
        $this->assertSame(649 - 590, collect($res->json('quote.rows'))->firstWhere('kind', 'estimate_cap')['amount']['minor'], 'the cap is shown, not added');
        $this->assertSame(590, $res->json('weighed.estimate.minor'));
        $this->assertSame(649, $res->json('weighed.cap.minor'));
        $this->assertSame(10, $res->json('weighed.cap_percent'));
    }

    public function test_express_adds_its_fee_to_the_delivery_fee_and_needs_no_slot(): void
    {
        $res = $this->quote($this->cart(['express' => true]));
        $rows = collect($res->json('quote.rows'))->keyBy('key');
        $this->assertSame(1400, $rows['delivery']['amount']['minor'], 'SAR 9 delivery + SAR 5 express');
        $this->assertSame(4690, $res->json('quote.total.minor'));
        $this->assertNotContains('slot', $res->json('requires'));
        $this->assertStringContainsString('Express', $rows['delivery']['label']);
    }

    public function test_the_card_hold_note_follows_the_stores_refund_switch(): void
    {
        $cart = $this->cart(['express' => false]);
        $holds = collect($this->quote($cart)->json('payment_methods'))->keyBy('id');
        $this->assertSame('We hold up to SAR 42.49 on your card and charge the final amount after we weigh your items. The rest is released.', $this->plain($holds['card']['sub']));

        // a gateway that cannot authorise and capture: offered for a weighed basket only when the store refunds the difference
        StoreSettings::setMany((int) $this->store->id, ['restaurant_stripe_install' => '', 'restaurant_paytabs_install' => '1']);
        $this->assertNotContains('hosted', $this->quote($cart)->json('payment_modes'), 'refund switch off: no hosted payment for a weighed basket');
        $this->assertSame(['cod'], collect($this->quote($cart)->json('payment_methods'))->pluck('id')->all());

        StoreSettings::set((int) $this->store->id, 'refunds_weighed_difference', '1');
        $this->assertTrue(RefundSwitches::allows((int) $this->store->id, 'weighed_difference'));
        $on = collect($this->quote($cart)->json('payment_methods'))->keyBy('id');
        $this->assertSame('We charge up to SAR 42.49 now and refund the difference after we weigh your items.', $this->plain($on['card']['sub']));

        // an item that is not weighed never gets the note
        $plain = $this->quote(array_merge($cart, ['lines' => [['item' => ApiFixtures::BANANAS, 'qty' => 1]]]));
        $this->assertSame('Pay now, securely', collect($plain->json('payment_methods'))->firstWhere('id', 'card')['sub']);
    }

    // ------------------------------------------------------------------ order

    public function test_what_the_shopper_is_quoted_is_what_the_order_charges_and_the_hold_becomes_the_orders(): void
    {
        $slot = $this->firstOpenSlot();
        $hold = $this->hold($slot);
        $cart = $this->cart(['slot_id' => $slot, 'hold_id' => $hold['id']]);
        $quote = $this->quote($cart);

        $res = $this->place($cart, $quote->json('quote_hash'), $hold['token'], ['pay_via' => 'cod'])->assertCreated()->assertJsonPath('success', true);

        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame($quote->json('quote.total.minor'), Money::minorFromMajor((float) $order->fresh(['items'])->total, 'SAR'));
        $this->assertSame('delivery', $order->type);
        $this->assertNotNull($order->slot_starts_at);
        $this->assertSame(1, DB::table('slot_seats')->where('order_id', $order->id)->count(), 'the hold became the order\'s seat');
        $this->assertSame(0, DB::table('slot_seats')->whereNotNull('hold_id')->whereNull('order_id')->count());
        $this->assertArrayNotHasKey('payment_url', $res->json());
        $this->assertStringEndsWith('/order/track/'.$order->track_token, $res->json('track_url'));
        $this->assertSame(1, (int) $this->builderOrderWeighed($order), 'the weighed line keeps its grams and cap');
    }

    private function builderOrderWeighed(Order $order): int
    {
        return OrderItem::query()->where('order_id', $order->id)->where('weight_g', 500)->where('cap_minor', 649)->count();
    }

    public function test_an_order_without_a_time_a_foreign_token_or_a_stale_quote_is_refused(): void
    {
        $quote = $this->quote($this->cart());
        $this->place($this->cart(), $quote->json('quote_hash'))->assertStatus(422)->assertJsonPath('errors.slot_id.0', 'Choose a delivery time.');

        $slot = $this->firstOpenSlot();
        $hold = $this->hold($slot);
        $cart = $this->cart(['slot_id' => $slot, 'hold_id' => $hold['id']]);
        $hash = $this->quote($cart)->json('quote_hash');
        $this->place($cart, $hash, 'hld_someone_elses')->assertStatus(409)->assertJsonPath('code', 'hold_expired');
        $this->assertSame(0, Order::query()->where('store_id', $this->store->id)->count());

        $this->place($cart, 'qh_stale', $hold['token'])->assertStatus(409)->assertJsonPath('code', 'quote_changed');
        $this->assertSame(0, Order::query()->where('store_id', $this->store->id)->count());
    }

    public function test_the_same_order_key_places_one_order_and_holds_one_seat(): void
    {
        $slot = $this->firstOpenSlot();
        $hold = $this->hold($slot);
        $cart = $this->cart(['slot_id' => $slot, 'hold_id' => $hold['id']]);
        $hash = $this->quote($cart)->json('quote_hash');
        $key = (string) Str::uuid7();
        $send = fn () => $this->postJson($this->url('retail.place'), $cart + ['name' => 'Noura', 'phone' => '+966551234567', 'quote_hash' => $hash, 'pay_via' => 'cod'], ['Idempotency-Key' => $key, 'X-Hold-Token' => $hold['token']]);
        $send()->assertCreated();
        $send()->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, Order::query()->where('store_id', $this->store->id)->count());
        $this->assertSame(1, DB::table('slot_seats')->where('slot_at', '>', now()->subDay())->whereNotNull('order_id')->where('order_id', '<', 900000)->count());
    }

    public function test_an_online_order_authorises_the_cap_not_just_the_total(): void
    {
        $slot = $this->firstOpenSlot();
        $hold = $this->hold($slot);
        $cart = $this->cart(['slot_id' => $slot, 'hold_id' => $hold['id']]);
        $res = $this->place($cart, $this->quote($cart)->json('quote_hash'), $hold['token'], ['pay_via' => 'pay_online'])->assertCreated();

        $this->assertNotEmpty($res->json('payment_url'));
        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $transaction = app(HostedPayments::class)->transactionFor($order);
        $this->assertSame(4249, (int) $transaction->amount_minor, '41.90 + the 0.59 the weighed line may add');
        $this->assertSame('manual', data_get($transaction->details, 'capture'));
    }

    public function test_the_out_of_stock_choices_are_snapshotted_per_line_and_for_the_order(): void
    {
        $slot = $this->firstOpenSlot();
        $hold = $this->hold($slot);
        $lines = $this->cart()['lines'];
        $lines[1]['substitution'] = ApiFixtures::SUB_REMOVE;
        $cart = $this->cart(['lines' => $lines, 'slot_id' => $slot, 'hold_id' => $hold['id'], 'substitution_preference' => ApiFixtures::SUB_CALL]);
        $quote = $this->quote($cart);
        $this->assertSame('Remove it', $quote->json('quote.lines.1.substitution'));

        $this->place($cart, $quote->json('quote_hash'), $hold['token'], ['pay_via' => 'cod'])->assertCreated();
        $order = Order::query()->where('store_id', $this->store->id)->firstOrFail();
        $call = Modifier::query()->where('public_id', ApiFixtures::SUB_CALL)->firstOrFail();
        $this->assertSame((int) $call->id, (int) $order->substitution_option_id);
        $tomatoes = OrderItem::query()->where('order_id', $order->id)->where('weight_g', '>', 0)->firstOrFail();
        $this->assertSame('remove_item', $tomatoes->picker_action, "this line's own choice wins");
        $bananas = OrderItem::query()->where('order_id', $order->id)->where('weight_g', null)->orderBy('id')->firstOrFail();
        $this->assertSame('call_customer', $bananas->picker_action, 'a line without a choice follows the order');
        $this->assertSame('remove_item', $bananas->picker_fallback);

        $bad = $this->cart(['lines' => [['item' => ApiFixtures::BANANAS, 'qty' => 1, 'substitution' => (string) Str::uuid7()]]]);
        $refused = $this->postJson($this->url('retail.quote'), $bad)->assertStatus(422);
        $this->assertSame("Choose one of this item's out-of-stock options.", $refused->json('errors')['lines.0.substitution'][0]);
    }

    /** the money format wraps the symbol in bidi isolates: compare the words */
    private function plain(string $text): string
    {
        return preg_replace('/[\x{2066}-\x{2069}]/u', '', $text) ?? $text;
    }

    private function builder(): ViewModelBuilder
    {
        return app(ViewModelBuilder::class);
    }

    private function request(string $page): ViewModelRequest
    {
        return new ViewModelRequest(store: $this->store, locale: 'en', page: $page, theme: 'basket');
    }
}
