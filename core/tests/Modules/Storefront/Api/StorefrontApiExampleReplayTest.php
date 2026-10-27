<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\StorefrontApi\ApiContract;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\Support\StorefrontApi\ExampleReplayer;
use Tests\TestCase;

/**
 * P3-06b phase A done-definition: the kit API examples (tools/themes/kit/api/examples) replay green through the
 * app — request valid, status, headers and body validated against openapi.json (studio-design/gesso) plus the juwri
 * rules, and the flow's fields compared (ExampleReplayer).
 *
 * {@see self::PLAN} lists EVERY example: `A` = replayed here (phases A–D); otherwise what it still needs (kiosk P3-06,
 * food schedule-ahead holds) — a skipped test names it, so the count of what
 * is still to build stays visible. A new kit example without a row fails {@see test_every_kit_example_is_planned()}.
 */
class StorefrontApiExampleReplayTest extends TestCase
{
    use RefreshDatabase;

    /** example => 'A' | reason it waits */
    public const PLAN = [
        'food/01-quote' => 'A', 'food/02-order-pay-later' => 'A', 'food/03-order-replay' => 'A', 'food/04-track' => 'A',
        'food-pickup/01-quote' => 'A',
        'food-pickup/02-pickup-slots' => 'A', 'food-pickup/03-hold-pickup-slot' => 'A', 'food-pickup/04-quote-scheduled' => 'A',
        'grocery/00-store' => 'A', 'grocery/01-item-weighed' => 'A', 'grocery/02-delivery-slots' => 'A',
        'grocery/03-hold-delivery-slot' => 'A', 'grocery/04-quote-weighed' => 'A', 'grocery/05-order-hosted' => 'A',
        'grocery/06-order-status-authorised' => 'A',
        'grocery-release/01-store' => 'A', 'grocery-release/02-release-hold' => 'A',
        'grocery-release/03-order-late-payment' => 'A', 'grocery-release/04-order-late-accepted' => 'A',
        'grocery-late-hold/01-store' => 'A', 'grocery-late-hold/02-release-hold' => 'A',
        'grocery-late-hold/03-order-late-payment' => 'A',
        'grocery-late-hold/04-order-late-accepted-needs-slot' => 'A',
        'grocery-late-hold/05-order-late-hold-expired' => 'A',
        'retail/01-variants' => 'A', 'retail/02-shipping-quote' => 'A', 'retail/03-quote' => 'A', 'retail/04-order-hosted' => 'A',
        'services/01-store' => 'A',
        'services/02-staff' => 'A', 'services/03-days' => 'A',
        'services/04-slots' => 'A', 'services/05-hold-booking-slot' => 'A',
        'services/06-quote-deposit' => 'A', 'services/07-booking-hosted-deposit' => 'A',
        'services/08-release-hold-in-use' => 'A', 'services/09-booking-confirmed' => 'A',
        'services/10-slots-reschedule' => 'A', 'services/11-hold-reschedule' => 'A',
        'services/12-reschedule' => 'A', 'services/13-cancel' => 'A',
        'services-otp/01-hold' => 'A', 'services-otp/02-quote' => 'A',
        'services-otp/03-booking-needs-verified-phone' => 'A',
        'services-otp/04-otp-request' => 'A', 'services-otp/05-otp-verify' => 'A',
        'services-otp/06-booking-verified' => 'A',
        'services-release/01-store' => 'A',
        'services-release/02-hold' => 'A', 'services-release/03-quote' => 'A',
        'services-release/04-booking-hosted-deposit' => 'A', 'services-release/05-release-hold' => 'A',
        'services-release/06-booking-late-deposit' => 'A', 'services-release/07-booking-late-accepted-needs-slot' => 'A',
        'kiosk/01-kiosk' => 'A',
        'kiosk-services/01-store' => 'A', 'kiosk-services/02-slots-today' => 'A', 'kiosk-services/03-hold' => 'A',
        'kiosk-services/04-quote' => 'A', 'kiosk-services/05-booking-walk-in' => 'A',
        'legacy/01-menu-alias' => 'A', 'legacy/02-order-legacy-error' => 'A', 'legacy/03-restaurant-alias' => 'A',
        'legacy/04-order-legacy-created' => 'A', 'legacy/05-track-alias' => 'A', 'legacy/06-public-menu-alias' => 'A',
        'errors/01-idempotency-conflict' => 'A',
        'errors/02-hold-expired-booking' => 'A',
        'errors/03-slot-full-delivery' => 'A',
        'errors/04-booking-api-not-enabled' => 'A',
        'errors/05-store-verify-guest-phone' => 'A',
        'errors/06-phone-verification-required' => 'A',
        'errors/07-return-url-not-allowed' => 'A',
        'errors/08-rate-limited' => 'A',
        'errors/09-reschedule-price-changed' => 'A',
        'errors/10-kiosk-feature-missing' => 'A',
        'errors/11-hold-in-use' => 'A',
    ];

    private ApiFixtures $fx;

    private ExampleReplayer $replay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        // Fri 2 Oct 2026, the examples' day (store time Asia/Riyadh)
        $this->travelTo(Carbon::parse('2026-10-02 18:40:00', 'UTC'));
        $this->fx = new ApiFixtures;
        $this->replay = new ExampleReplayer($this);
    }

    public function test_every_kit_example_is_planned(): void
    {
        $files = [];
        foreach (glob(ExampleReplayer::dir().'/*/*.json') ?: [] as $file) {
            $files[] = basename(dirname($file)).'/'.basename($file, '.json');
        }
        sort($files);
        $planned = array_keys(self::PLAN);
        sort($planned);
        $this->assertSame($files, $planned, 'every kit API example has a row in PLAN');
        $this->assertCount(77, $files);
    }

    /** Lane C: every kit example is replayed now (kiosk included) — a new example must come with its replay. */
    public function test_every_example_is_replayed(): void
    {
        $this->assertSame([], array_keys(array_filter(self::PLAN, fn (string $phase) => $phase !== 'A')));
    }

    public function test_food_flow_quote_order_replay_track_and_idempotency_conflict(): void
    {
        $this->fx->saffron();

        $this->replay->replay('food/01-quote');
        $this->replay->replay('food/02-order-pay-later');
        [$replayed] = $this->replay->replay('food/03-order-replay');
        $this->assertSame('true', $replayed->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, Order::query()->count(), 'the replay placed no second order');

        // the same key with another body (qty 2): 409, still one order
        $this->replay->replay('errors/01-idempotency-conflict');
        $this->assertSame(1, Order::query()->count());

        // tracking: the order went out for delivery
        $order = Order::query()->firstOrFail();
        $order->forceFill(['status' => 'preparing', 'delivery_status' => 'out_for_delivery', 'preparing_at' => now()])->save();
        $this->replay->replay('food/04-track');
    }

    public function test_food_pickup_quote(): void
    {
        $this->fx->bloom();
        $this->replay->replay('food-pickup/01-quote');
    }

    /**
     * Lane C: food schedule-ahead — the kit's `pickup` at a cafe is its takeaway; the pickup slots are the store's
     * pre-order times with live capacity (scheduled orders + held seats), a slot is held for 10 minutes like any other
     * (`delivery_slot`), and the quote takes the slot and its hold.
     */
    public function test_food_pickup_schedule_ahead_slots_hold_and_scheduled_quote(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 06:45:00', 'UTC')); // 09:45 in Riyadh, the examples' time
        $store = $this->fx->bloom();
        $this->fx->bloomPreorders($store);

        [$days, $example] = $this->replay->replay('food-pickup/02-pickup-slots');
        $mine = collect($days->json('days.0.slots'))->keyBy('starts_at');
        foreach ($example['response']['body']['days'][0]['slots'] as $slot) {
            $this->replay->record($slot['id'], $mine[$slot['starts_at']]['id'] ?? null);
        }
        $first = array_slice($days->json('days.0.slots'), 0, 4);
        $this->assertSame(['2026-10-02T10:00:00+03:00', '2026-10-02T10:15:00+03:00', '2026-10-02T10:30:00+03:00', '2026-10-02T10:45:00+03:00'], array_column($first, 'starts_at'));
        $this->assertSame([0, 2, 6, 8], array_column($first, 'capacity_remaining'));
        $this->assertSame(['full', 'few', 'available', 'available'], array_column($first, 'state'));

        [$hold] = $this->replay->replay('food-pickup/03-hold-pickup-slot');
        $held = ExampleReplayer::load('food-pickup/03-hold-pickup-slot')['response']['body'];
        $this->replay->record($held['id'], $hold->json('id'));
        $this->replay->record($held['token'], $hold->json('token'));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('slot_seats')->where('store_id', $store->id)->where('slot_at', '2026-10-02 07:30:00')->count(), 'the hold took a seat of 10:30');
        $again = $this->getJson('/api/v1/storefront/delivery/slots?order_type=pickup&from=2026-10-02&days=1', ['X-Storefront-Token' => 'sfx_demo_bloom_read_0001']);
        $this->assertSame(5, $again->json('days.0.slots.2.capacity_remaining'), 'a held seat counts like a scheduled order');

        $this->replay->replay('food-pickup/04-quote-scheduled');
    }

    public function test_legacy_aliases_keep_their_frozen_shapes_with_deprecation_headers(): void
    {
        $store = $this->fx->saffron();
        $this->replay->replay('legacy/01-menu-alias');
        $this->replay->replay('legacy/02-order-legacy-error');
        $this->replay->replay('legacy/03-restaurant-alias');

        $legacy = ExampleReplayer::load('legacy/04-order-legacy-created')['request']['body'];
        $legacy['items'] = [
            ['item_id' => $this->fx->legacyIds['101'], 'quantity' => 2],
            ['item_id' => $this->fx->legacyIds['102'], 'quantity' => 1],
        ];
        [$created] = $this->replay->replay('legacy/04-order-legacy-created', ['body' => $legacy]);
        $order = Order::query()->where('track_token', $created->json('track_token'))->firstOrFail();
        $this->assertSame('01a0fb36-4a10-7c3e-9b52-0d6e4f1a2b37', $order->client_uuid, 'the legacy body key is the order key');
        // the replayer maps the example's track token to this order's (recorded from the 201 above)
        Order::query()->whereKey($order->id)->update(['status' => 'ready', 'delivery_status' => 'out_for_delivery']);

        $this->replay->replay('legacy/05-track-alias');
        $this->replay->replay('legacy/06-public-menu-alias', ['path' => '/api/v1/menu/'.$store->id]);
    }

    /**
     * P3-08 / phase B: the retail flow — the variant matrix with stock states (API-6: an exact quantity only when
     * low), the shipping quote for the address (zone, free standard by threshold, express with its cut-off, pickup),
     * the quote with the chosen method (shipping row, "You save" informational) and the hosted order with shipping.
     */
    public function test_retail_flow_variants_shipping_quote_and_hosted_order(): void
    {
        // the examples' morning (09:44 store time), before the express cut-off
        $this->travelTo(Carbon::parse('2026-10-02 06:44:50', 'UTC'));
        $store = $this->fx->duneRetail();

        [$variants] = $this->replay->replay('retail/01-variants');
        $rows = collect($variants->json('variants'))->keyBy('sku');
        $this->assertSame(['out', null], [$rows['RLS-SAN-XS']['stock']['state'], $rows['RLS-SAN-XS']['stock']['qty']]);
        $this->assertTrue($rows['RLS-SAN-XS']['notify_available'] ?? false, 'a sold-out cell offers back-in-stock');
        $this->assertSame(['in_stock', null], [$rows['RLS-SAN-S']['stock']['state'], $rows['RLS-SAN-S']['stock']['qty']], 'API-6: no exact count above the threshold');
        $this->assertSame(['low', 2], [$rows['RLS-SAN-M']['stock']['state'], $rows['RLS-SAN-M']['stock']['qty']]);
        $this->assertSame('untracked', $rows['RLS-OLI-M']['stock']['state']);
        $this->assertSame(19900, $rows['RLS-SAN-M']['compare_at']['minor']);
        $this->assertSame(
            ['019f23cc-0b00-7a1e-9c2d-3e4f5a6b7c61' => '019f23cd-4a14-7b7b-a7c0-91d71e6a5348', '019f8488-0b00-7b2f-8d3e-4f5a6b7c8d72' => '019f6628-fcc8-7266-b5e7-8ac988ca5a9d'],
            $rows['RLS-SAN-M']['options']
        );

        [$shipping] = $this->replay->replay('retail/02-shipping-quote');
        $this->assertSame('riyadh-north', $shipping->json('zone'));
        $this->assertTrue($shipping->json('ships_to_address'));
        $methods = collect($shipping->json('methods'))->keyBy('id');
        $this->assertSame([ApiFixtures::SHIP_STANDARD, ApiFixtures::SHIP_EXPRESS, ApiFixtures::SHIP_PICKUP], $methods->keys()->all());
        $this->assertSame([0, '2026-10-04', '2026-10-06'], [$methods[ApiFixtures::SHIP_STANDARD]['fee']['minor'], $methods[ApiFixtures::SHIP_STANDARD]['earliest_date'], $methods[ApiFixtures::SHIP_STANDARD]['latest_date']], 'standard is free from SAR 200, 2–4 days');
        $this->assertSame([2500, '2026-10-03', '2026-10-02T21:00:00+03:00'], [$methods[ApiFixtures::SHIP_EXPRESS]['fee']['minor'], $methods[ApiFixtures::SHIP_EXPRESS]['earliest_date'], $methods[ApiFixtures::SHIP_EXPRESS]['cutoff_at']]);
        $this->assertSame(ApiFixtures::OLAYA_BRANCH, $methods[ApiFixtures::SHIP_PICKUP]['pickup_branch_id']);

        [$quote] = $this->replay->replay('retail/03-quote');
        $rows = collect($quote->json('quote.rows'))->keyBy('key');
        $this->assertSame(0, $rows['shipping']['amount']['minor']);
        $this->assertSame(6000, $rows['saving']['amount']['minor']);
        $this->assertTrue($rows['saving']['informational']);

        $variant = \Juwri\Modules\Menu\Models\MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->firstOrFail();
        [$created] = $this->replay->replay('retail/04-order-hosted');
        $order = Order::query()->where('track_token', $created->json('order.track_token'))->firstOrFail();
        $this->assertSame('ship', $order->getRawOriginal('type'));
        $this->assertSame('hosted', $order->payment_mode);
        $this->assertSame('2026-10-04', $order->shipping_eta_from?->toDateString());
        $this->assertSame(1, (int) $variant->fresh()->stock_qty, 'the order took one of the two left (race-safe take)');
        $this->assertSame(36800, \Juwri\Core\Money\Money::minorFromMajor((float) $order->grand_total, 'SAR'));
    }

    public function test_return_url_outside_the_token_allowlist_is_refused(): void
    {
        $this->fx->dune();
        $this->replay->replay('errors/07-return-url-not-allowed');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_store_answers_the_booking_and_hold_rules(): void
    {
        $store = $this->fx->sidra();
        $this->replay->replay('services/01-store');

        StoreSettings::set((int) $store->id, 'holds_release_during_payment', true);
        [$on] = $this->replay->replay('services-release/01-store');
        $this->assertTrue($on->json('holds.release_during_payment'));

        StoreSettings::set((int) $store->id, 'booking_verify_guest_phone', true);
        [$verify] = $this->replay->replay('errors/05-store-verify-guest-phone');
        $this->assertTrue($verify->json('booking.verify_guest_phone'));
    }

    public function test_customer_otp_request_and_verify_for_a_headless_client(): void
    {
        $store = $this->fx->sidra();
        $this->replay->replay('services-otp/04-otp-request');

        // the text message is not readable in a test: put the example's code where the store's OTP lives
        $otp = app(SmsOtpService::class);
        Cache::put($otp->key(OtpScope::store((int) $store->id), '+966551234567'), ['hash' => Hash::make('482913'), 'attempts' => 0], 600);
        [$verified] = $this->replay->replay('services-otp/05-otp-verify');
        $this->assertNotEmpty($verified->json('token'));
        $this->assertNotNull($verified->json('customer.phone_verified_at'));
    }

    public function test_late_payment_states_on_the_order_status(): void
    {
        $store = $this->fx->freshMart();
        $order = $this->fx->order($store, 'trk_f4570b652cb4506a0fc2ac64', [
            'payment_mode' => 'hosted', 'status' => 'cancelled', 'cancelled_at' => now(), 'is_paid' => 0,
            'payment_expires_at' => now()->subMinutes(30), 'late_payment_status' => 'received_late', 'late_payment_minor' => 7644,
            'late_payment_at' => now(),
        ]);
        $this->replay->replay('grocery-release/03-order-late-payment');
        $this->replay->replay('grocery-late-hold/03-order-late-payment');

        // accepted, the slot was taken: pending approval with the card hold authorised until it lapses (API-27, API-32)
        $order->forceFill(['status' => Order::STATUS_PENDING_APPROVAL, 'late_payment_status' => 'accepted', 'is_paid' => 1,
            'cancelled_at' => null, 'authorisation_expires_at' => Carbon::parse('2026-10-09T11:05:10+03:00')->utc()])->save();
        $this->replay->replay('grocery-late-hold/04-order-late-accepted-needs-slot');

        // the authorisation lapsed before the owner acted: cancelled, hold voided
        $this->travelTo(Carbon::parse('2026-10-09T12:00:00+03:00')->utc());
        $this->artisan('orders:expire-hosted-payments')->assertSuccessful();
        $this->replay->replay('grocery-late-hold/05-order-late-hold-expired');
        $this->assertSame('voided', $order->fresh()->late_payment_status);
    }

    /**
     * P3-09 / phase C: the grocery flow — store substitution group with picker actions, the weighed item, the day's
     * delivery slots with live capacity + express, a 10-minute slot hold, the weighed quote (estimate cap row, hold =
     * total + what the weighed line may add), the hosted order and its authorised card hold.
     */
    public function test_grocery_flow_slots_hold_weighed_quote_hosted_order_and_card_hold(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 07:45:30', 'UTC')); // 10:45 store time
        $store = $this->fx->freshMartGrocery();

        [$storeBody] = $this->replay->replay('grocery/00-store');
        $this->assertSame(ApiFixtures::SUB_REPLACE, $storeBody->json('substitution.default_option_id'));
        $this->assertSame([
            ['option_id' => ApiFixtures::SUB_REPLACE, 'action' => 'offer_replacement'],
            ['option_id' => ApiFixtures::SUB_CALL, 'action' => 'call_customer', 'fallback' => 'remove_item'],
            ['option_id' => ApiFixtures::SUB_REMOVE, 'action' => 'remove_item'],
        ], $storeBody->json('substitution.picker_actions'));

        [$item] = $this->replay->replay('grocery/01-item-weighed');
        $this->assertSame(['weighed', 1180, 'kg', ApiFixtures::TOMATO_WEIGHT_GROUP, 10], [
            $item->json('sell.kind'), $item->json('sell.unit_price.minor'), $item->json('sell.unit_price_per'),
            $item->json('sell.weight_option_group'), $item->json('sell.cap_percent'),
        ]);
        $this->assertSame(ApiFixtures::SUBSTITUTION_GROUP, $item->json('substitution_group_id'));

        $slots = $this->replaySlots();
        $this->assertSame([6, 4, 0, 8, 8, 2], array_column($slots, 'capacity_remaining'));
        $this->assertSame(['available', 'available', 'full', 'available', 'available', 'few'], array_column($slots, 'state'));

        [$hold] = $this->replay->replay('grocery/03-hold-delivery-slot');
        $this->recordHold($hold);
        $this->assertSame(5, \Illuminate\Support\Facades\DB::table('slot_seats')->where('store_id', $store->id)->where('slot_at', '2026-10-02 09:00:00')->count(), 'the hold took a seat of 12:00');

        [$quote] = $this->replay->replay('grocery/04-quote-weighed');
        $this->assertSame([590, 649, 10], [$quote->json('weighed.estimate.minor'), $quote->json('weighed.cap.minor'), $quote->json('weighed.cap_percent')]);

        [$created] = $this->replay->replay('grocery/05-order-hosted');
        $this->assertSame(7644, $created->json('payment.hold_amount.minor'));
        $order = Order::query()->where('track_token', $created->json('order.track_token'))->firstOrFail();
        $this->assertSame('unpaid', $order->status);
        $this->assertNotNull($order->slot_hold_id);
        $tomato = $order->items()->whereNotNull('weight_g')->firstOrFail();
        $this->assertSame([500, 649, 'offer_replacement'], [(int) $tomato->weight_g, (int) $tomato->cap_minor, $tomato->picker_action]);

        // the gateway authorises the hold inside the window; the store starts picking
        $transaction = app(\Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments::class)->transactionFor($order);
        $this->assertNotNull($transaction);
        $this->assertTrue(\Juwri\Modules\Payments\Services\Gateways\Settlement::authorise($transaction, 'stripe', 'pi_demo', now()->addDays(7)));
        $order->refresh();
        $this->assertSame(['authorised', 7644, 0], [$order->hold_status, (int) $order->hold_minor, (int) $order->is_paid]);
        $this->assertNotSame('unpaid', $order->status, 'the authorised hold placed the order inside the window');
        app(\Juwri\Modules\Ordering\Services\Grocery\GroceryPicking::class)->start($order);
        $this->replay->replay('grocery/06-order-status-authorised');
    }

    /** API-24a: the shopper releases the hold of their pending hosted order (store setting on) — and the late card hold. */
    public function test_grocery_release_during_payment_late_hold_accepted_with_the_slot_back(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 07:45:30', 'UTC'));
        $store = $this->fx->freshMartGrocery();
        StoreSettings::set((int) $store->id, 'holds_release_during_payment', true);
        [$on] = $this->replay->replay('grocery-release/01-store');
        $this->assertTrue($on->json('holds.release_during_payment'));
        $order = $this->placeHostedGroceryOrder();

        [$released] = $this->replay->replay('grocery-release/02-release-hold');
        $this->assertSame('cancelled', $released->json('cancelled.status'));
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('slot_seats')->where('order_id', $order->id)->count(), 'the slot seat went back');

        // the card hold arrives late (API-21), the owner accepts it: the slot is free again, the order is placed (API-27)
        $transaction = app(\Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments::class)->transactionFor($order);
        \Juwri\Modules\Payments\Services\Gateways\Settlement::authorise($transaction, 'stripe', 'pi_demo', now()->addDays(7));
        $order->refresh();
        $this->assertSame(['received_late', 'authorised'], [$order->late_payment_status, $order->hold_status]);
        $this->replay->replay('grocery-release/03-order-late-payment');

        $this->assertSame('placed', app(\Juwri\Modules\Ordering\Services\Payments\LatePayments::class)->accept($order));
        $order->refresh();
        $this->assertSame([0, 'authorised'], [(int) $order->is_paid, $order->hold_status], 'a card hold is not money taken');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('slot_seats')->where('order_id', $order->id)->count(), 'the slot was taken again');
        $this->replay->replay('grocery-release/04-order-late-accepted');
    }

    public function test_grocery_release_hold_of_a_pending_order_with_the_setting_off_is_hold_in_use(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 07:45:30', 'UTC'));
        $this->fx->freshMartGrocery();
        $this->replay->replay('grocery-late-hold/01-store');
        $order = $this->placeHostedGroceryOrder();
        $this->replay->replay('errors/11-hold-in-use');
        $this->assertSame('unpaid', $order->fresh()->status);
    }

    public function test_grocery_late_hold_release_with_the_setting_on(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 07:45:30', 'UTC'));
        $store = $this->fx->freshMartGrocery();
        StoreSettings::set((int) $store->id, 'holds_release_during_payment', true);
        $order = $this->placeHostedGroceryOrder();
        $this->replay->replay('grocery-late-hold/02-release-hold');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    /** API-3: the hold lapsed and the slot filled meanwhile — the submit answers 409 slot_full with the next free slot. */
    public function test_grocery_submit_with_a_lapsed_hold_on_a_full_slot_is_slot_full(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 07:45:30', 'UTC'));
        $store = $this->fx->freshMartGrocery();
        $this->replaySlots();
        [$hold] = $this->replay->replay('grocery/03-hold-delivery-slot');
        $this->recordHold($hold);
        [$quote] = $this->replay->replay('grocery/04-quote-weighed');

        $this->travel(11)->minutes(); // the hold lapsed …
        app(\Juwri\Modules\Ordering\Services\Slots\SlotHolds::class)->sweep();
        $this->fx->takeSeats($store, '2026-10-02 09:00:00', 4, 5); // … and the 12:00 slot filled up
        [$full] = $this->replay->replay('errors/03-slot-full-delivery');
        $this->assertSame('14:00 – 15:00', $full->json('details.next_free_slot.display.label'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_grocery_delivery_slots_rate_limited(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 07:45:30', 'UTC'));
        $this->fx->freshMartGrocery();
        config(['api.storefront_rate' => 1]);
        $this->getJson('/api/v1/storefront/delivery/slots?order_type=delivery', ['X-Storefront-Token' => 'sfx_demo_freshmart_read_0001'])->assertOk();
        [$limited] = $this->replay->replay('errors/08-rate-limited');
        $this->assertTrue($limited->headers->has('Retry-After'));
    }

    /** @return list<array<string, mixed>> today's slots (the example slot ids now map to the app's) */
    private function replaySlots(): array
    {
        [$days, $example] = $this->replay->replay('grocery/02-delivery-slots');
        $mine = collect($days->json('days.0.slots'))->keyBy('starts_at');
        foreach ($example['response']['body']['days'][0]['slots'] as $slot) {
            $this->replay->record($slot['id'], $mine[$slot['starts_at']]['id'] ?? null);
        }

        return $days->json('days.0.slots');
    }

    private function recordHold(TestResponse $hold): void
    {
        $example = ExampleReplayer::load('grocery/03-hold-delivery-slot')['response']['body'];
        $this->replay->record($example['id'], $hold->json('id'));
        $this->replay->record($example['token'], $hold->json('token'));
    }

    /** slots → hold → quote → hosted order (the grocery examples 02–05), returns the unpaid order. */
    private function placeHostedGroceryOrder(): Order
    {
        $this->replaySlots();
        [$hold] = $this->replay->replay('grocery/03-hold-delivery-slot');
        $this->recordHold($hold);
        $this->replay->replay('grocery/04-quote-weighed');
        [$created] = $this->replay->replay('grocery/05-order-hosted');

        return Order::query()->where('track_token', $created->json('order.track_token'))->firstOrFail();
    }

    /**
     * Phase D deviations, named once: the booking engine has no service options yet, so `option_ids` are dropped from
     * the requests (the fixture's one service is the examples' Medium + Deep conditioning: SAR 180, 75 min, deposit
     * SAR 50), and a deposit is paid on the hosted page only, so a services quote with a deposit offers `hosted` alone.
     */
    private const NO_OPTIONS = ['drop' => ['option_ids']];

    private const DEPOSIT_QUOTE = ['drop' => ['option_ids'], 'expect' => ['payment_modes' => ['hosted']]];

    /** Map the example's slot ids to the app's by start time (the ids are opaque). */
    private function recordSlots(TestResponse $slots, array $example): void
    {
        $mine = collect($slots->json('slots'))->keyBy('starts_at');
        foreach ($example['response']['body']['slots'] as $slot) {
            $this->replay->record($slot['id'], $mine[$slot['starts_at']]['id'] ?? null);
        }
    }

    private function recordBookingHold(string $example, TestResponse $hold): void
    {
        $body = ExampleReplayer::load($example)['response']['body'];
        $this->replay->record($body['id'], $hold->json('id'));
        $this->replay->record($body['token'], $hold->json('token'));
    }

    /** The app's id of a free slot of Maha at a store-local time (a flow that holds without listing first). */
    private function recordSlotAt(string $exampleSlotId, string $date, string $localStart): void
    {
        $listing = $this->getJson('/api/v1/storefront/booking/slots?'.http_build_query(['service_ids' => [ApiFixtures::SVC_HAIRCUT], 'staff_id' => ApiFixtures::STAFF_MAHA, 'date' => $date]),
            ['X-Storefront-Token' => 'sfx_demo_sidra_read_0001', 'Accept' => 'application/json'])->assertOk();
        $slot = collect($listing->json('slots'))->firstWhere('starts_at', $localStart);
        $this->assertNotNull($slot, "a slot at {$localStart}");
        $this->replay->record($exampleSlotId, $slot['id']);
    }

    private function payDeposit(string $manageToken): \Juwri\Modules\Payments\Models\Transaction
    {
        $booking = \Juwri\Modules\Bookings\Models\Booking::query()->where('public_id', explode('.', $manageToken)[0])->firstOrFail();
        $txn = app(\Juwri\Modules\Bookings\Services\DepositService::class)->start($booking, 'stripe');

        return $txn;
    }

    public function test_services_flow_staff_days_slots_hold_quote_deposit_manage_reschedule_cancel(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 06:31:05', 'UTC'));
        $this->fx->sidraBookings(settings: ['booking_deposit_refunds' => true]);
        $this->replay->replay('services/01-store');
        [$staff] = $this->replay->replay('services/02-staff');
        $this->assertSame([ApiFixtures::STAFF_MAHA, ApiFixtures::STAFF_LINA, ApiFixtures::STAFF_SARA], array_column($staff->json('staff'), 'id'));
        [$days] = $this->replay->replay('services/03-days', self::NO_OPTIONS);
        $this->assertSame(['available', 'closed', 'available', 'available', 'available'], array_column($days->json('days'), 'state'));
        $this->assertSame('2026-10-02T16:30:00+03:00', $days->json('days.0.first_slot_at'));

        [$slots, $example] = $this->replay->replay('services/04-slots', self::NO_OPTIONS);
        $this->assertSame(75, $slots->json('total_duration_min'));
        $this->recordSlots($slots, $example);
        [$hold] = $this->replay->replay('services/05-hold-booking-slot', self::NO_OPTIONS);
        $this->recordBookingHold('services/05-hold-booking-slot', $hold);
        [$quote] = $this->replay->replay('services/06-quote-deposit', self::DEPOSIT_QUOTE);
        $this->assertSame([18000, 5000, 13000], array_column(array_column($quote->json('quote.rows'), 'amount'), 'minor'));

        $this->travelTo(Carbon::parse('2026-10-02 06:38:05', 'UTC'));
        [$created] = $this->replay->replay('services/07-booking-hosted-deposit', self::NO_OPTIONS);
        $this->assertSame('2026-10-04T16:30:00+03:00', $created->json('booking.starts_at'));
        $this->assertSame('2026-10-02T09:53:05+03:00', $created->json('payment.next_action.expires_at'), 'API-10: created + 15 minutes');
        $this->replay->replay('services/08-release-hold-in-use');

        // the customer pays the deposit on the hosted page inside the window
        $txn = $this->payDeposit((string) $created->json('booking.manage_token'));
        \Juwri\Modules\Payments\Services\Gateways\Settlement::settleForBuyer($txn->fresh(), 'stripe', 'pi_sidra_1', claim: true);
        [$confirmed] = $this->replay->replay('services/09-booking-confirmed');
        $this->assertSame($confirmed->json('booking.cancel_deadline_at'), $confirmed->json('booking.reschedule_deadline_at'), 'API-9');

        $this->travelTo(Carbon::parse('2026-10-02 08:18:40', 'UTC'));
        [$again, $example] = $this->replay->replay('services/10-slots-reschedule', self::NO_OPTIONS);
        $this->assertSame($created->json('booking.public_id'), $again->json('reschedule_of'));
        $this->recordSlots($again, $example);
        [$hold2] = $this->replay->replay('services/11-hold-reschedule', self::NO_OPTIONS);
        $this->assertSame($created->json('booking.public_id'), $hold2->json('booking_id'));
        $this->recordBookingHold('services/11-hold-reschedule', $hold2);
        [$moved] = $this->replay->replay('services/12-reschedule');
        $this->assertSame('2026-10-05T15:15:00+03:00', $moved->json('booking.starts_at'));
        $this->assertSame(1, \Juwri\Modules\Payments\Models\Transaction::query()->where('transaction_method', 'booking_deposit')->count(), 'no money moved on reschedule');

        $this->travelTo(Carbon::parse('2026-10-02 09:05:33', 'UTC'));
        [$cancelled] = $this->replay->replay('services/13-cancel');
        $this->assertSame(5000, $cancelled->json('payment.refunded_amount.minor'));
    }

    public function test_services_hold_expired_and_price_changed_errors(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 06:31:05', 'UTC'));
        $store = $this->fx->sidraBookings();
        [$slots, $example] = $this->replay->replay('services/04-slots', self::NO_OPTIONS);
        $this->recordSlots($slots, $example);
        [$hold] = $this->replay->replay('services/05-hold-booking-slot', self::NO_OPTIONS);
        $this->recordBookingHold('services/05-hold-booking-slot', $hold);
        $this->replay->replay('services/06-quote-deposit', self::DEPOSIT_QUOTE);
        $this->travel(11)->minutes();
        [$expired] = $this->replay->replay('errors/02-hold-expired-booking', self::NO_OPTIONS);
        $this->assertSame('2026-10-04T16:30:00+03:00', $expired->json('details.next_free_slot.starts_at'), 'the time itself is free again');
        $this->assertSame(0, \Juwri\Modules\Bookings\Models\Booking::query()->count());

        // a confirmed booking, then the owner raises the price: a reschedule at the old price is refused (API-8)
        [$hold] = $this->replay->replay('services/05-hold-booking-slot', self::NO_OPTIONS + ['headers' => ['X-Storefront-Token' => 'sfx_demo_sidra_write_0001', 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Origin' => 'https://sidra-salon.example', 'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid7()]]);
        $this->recordBookingHold('services/05-hold-booking-slot', $hold);
        $quote = $this->replay->replay('services/06-quote-deposit', self::DEPOSIT_QUOTE)[0];
        $this->replay->record('qh_850db3341fe55326bf1076b1ff6e2556', $quote->json('quote_hash'));
        [$created] = $this->replay->replay('services/07-booking-hosted-deposit', self::NO_OPTIONS);
        $txn = $this->payDeposit((string) $created->json('booking.manage_token'));
        \Juwri\Modules\Payments\Services\Gateways\Settlement::settleForBuyer($txn->fresh(), 'stripe', 'pi_sidra_2', claim: true);
        $this->replay->record('mgt_fa27a0ac471f03dcaad42a6f2d39', (string) $created->json('booking.manage_token'));
        $made = $this->postJson('/api/v1/storefront/holds', ['kind' => 'booking_slot', 'slot_id' => $this->freeSlotId('2026-10-05', '2026-10-05T15:15:00+03:00'), 'service_ids' => [ApiFixtures::SVC_HAIRCUT]], [
            'X-Storefront-Token' => 'sfx_demo_sidra_write_0001', 'Origin' => 'https://sidra-salon.example', 'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid7(),
            'X-Manage-Token' => (string) $created->json('booking.manage_token'),
        ])->assertCreated();
        $this->replay->record('01a0fc8e-cd4e-7ec1-be55-606f607e9917', (string) $made->json('id'));
        $this->replay->record('hld_8ecd4eec13e55606f607e9917f5fd834', (string) $made->json('token'));
        \Juwri\Modules\Bookings\Models\BookableService::query()->where('store_id', $store->id)->update(['price_minor' => 20000]);
        $this->replay->replay('errors/09-reschedule-price-changed');
        $this->assertSame('2026-10-04 13:30:00', \Juwri\Modules\Bookings\Models\Booking::query()->firstOrFail()->starts_at->utc()->format('Y-m-d H:i:s'), 'the booking did not move');
    }

    private function freeSlotId(string $date, string $start): string
    {
        $listing = $this->getJson('/api/v1/storefront/booking/slots?'.http_build_query(['service_ids' => [ApiFixtures::SVC_HAIRCUT], 'staff_id' => ApiFixtures::STAFF_MAHA, 'date' => $date]),
            ['X-Storefront-Token' => 'sfx_demo_sidra_read_0001', 'Accept' => 'application/json'])->assertOk();

        return (string) collect($listing->json('slots'))->firstWhere('starts_at', $start)['id'];
    }

    public function test_booking_api_needs_its_plan_feature(): void
    {
        $owner = $this->fx->owner(['booking_api' => '0']);
        if ((string) (\App\Services\Entitlements::planTrace($owner, 'booking_api')['value'] ?? '') !== '0') {
            $this->markTestSkipped('This edition grants every plan key (Community): nothing to withhold.');
        }
        $this->fx->saffron($owner);
        $this->replay->replay('errors/04-booking-api-not-enabled');
    }

    public function test_services_verify_guest_phone_flow_with_the_storefront_otp(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 06:55:00', 'UTC'));
        $store = $this->fx->sidraBookings(settings: ['booking_verify_guest_phone' => true]);
        $this->recordSlotAt('bks_539da553bf12', '2026-10-04', '2026-10-04T15:15:00+03:00');
        [$hold] = $this->replay->replay('services-otp/01-hold', self::NO_OPTIONS);
        $this->recordBookingHold('services-otp/01-hold', $hold);
        $this->replay->replay('services-otp/02-quote', self::DEPOSIT_QUOTE);
        $this->replay->replay('services-otp/03-booking-needs-verified-phone', self::NO_OPTIONS);
        $this->replay->replay('services-otp/04-otp-request');
        $otp = app(SmsOtpService::class);
        Cache::put($otp->key(OtpScope::store((int) $store->id), '+966551234567'), ['hash' => Hash::make('482913'), 'attempts' => 0], 600);
        $this->replay->replay('services-otp/05-otp-verify');
        [$booked] = $this->replay->replay('services-otp/06-booking-verified', self::NO_OPTIONS);
        $this->assertSame('+966551234567', \Juwri\Modules\Bookings\Models\Booking::query()->where('public_id', $booked->json('booking.public_id'))->value('phone'));
    }

    /**
     * Lane C (API-13, API-24d): a kiosk device token reads its kiosk context; without the platform's `kiosk` feature
     * every kiosk-token call answers 403 kiosk_not_enabled with `details.feature = kiosk`.
     */
    public function test_kiosk_context_and_the_kiosk_feature_gate(): void
    {
        $saffron = $this->fx->saffron();
        $this->fx->kioskOn($saffron);
        [$kiosk] = $this->replay->replay('kiosk/01-kiosk');
        $this->assertTrue($kiosk->json('kiosk.on'));
        $this->assertArrayNotHasKey('exit_pin', (array) $kiosk->json('kiosk'), 'PINs are never in a response');

        $sidra = $this->fx->sidraBookings();
        $this->fx->kioskOn($sidra, feature: false);
        $this->replay->replay('errors/10-kiosk-feature-missing');
    }

    /**
     * Lane C (API-15): a kiosk walk-in booking with "Verify guest phone" ON — today's slots, a hold, a quote that never
     * lists `verified_phone` and offers pay later only (no deposit step), and the booking: confirmed, no deposit,
     * tagged `source=kiosk`, no customer token.
     */
    public function test_kiosk_walk_in_booking_is_exempt_from_guest_phone_verification(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 08:01:00', 'UTC')); // 11:01 in Riyadh
        $store = $this->fx->sidraBookings(settings: ['booking_verify_guest_phone' => true]);
        $this->fx->kioskOn($store);
        [$storeBody] = $this->replay->replay('kiosk-services/01-store');
        $this->assertTrue($storeBody->json('booking.verify_guest_phone'));
        $this->replay->replay('kiosk-services/02-slots-today', self::NO_OPTIONS);
        // Sidra opens at 16:30 on Fridays (the example's day shows morning times): hold today's first free time
        $this->recordSlotAt('bks_911302dded11', '2026-10-02', '2026-10-02T16:30:00+03:00');
        [$hold] = $this->replay->replay('kiosk-services/03-hold', self::NO_OPTIONS);
        $this->recordBookingHold('kiosk-services/03-hold', $hold);
        [$quote] = $this->replay->replay('kiosk-services/04-quote', self::NO_OPTIONS);
        $this->assertNotContains('verified_phone', $quote->json('requires'));
        [$booked] = $this->replay->replay('kiosk-services/05-booking-walk-in', self::NO_OPTIONS);

        $booking = \Juwri\Modules\Bookings\Models\Booking::query()->where('public_id', $booked->json('booking.public_id'))->firstOrFail();
        $this->assertSame(['confirmed', 'kiosk', 0, 'none', '+966500000123'], [$booking->status, $booking->source, $booking->deposit_minor, $booking->deposit_status, $booking->phone]);
    }

    public function test_services_phone_verification_required_error(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 06:31:05', 'UTC'));
        $this->fx->sidraBookings(settings: ['booking_verify_guest_phone' => true]);
        [$slots, $example] = $this->replay->replay('services/04-slots', self::NO_OPTIONS);
        $this->recordSlots($slots, $example);
        [$hold] = $this->replay->replay('services/05-hold-booking-slot', self::NO_OPTIONS);
        $this->recordBookingHold('services/05-hold-booking-slot', $hold);
        $this->replay->replay('errors/06-phone-verification-required', self::NO_OPTIONS);
    }

    /** API-26 + API-21 + API-27: release the hold of a pending deposit (setting on), the late deposit, accepted without the time. */
    public function test_services_release_during_payment_late_deposit_accepted_needs_slot(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 12:01:05', 'UTC'));
        $store = $this->fx->sidraBookings(settings: ['holds_release_during_payment' => true]);
        $this->replay->replay('services-release/01-store');
        $this->recordSlotAt('bks_3f2d5ef0853d', '2026-10-04', '2026-10-04T16:30:00+03:00');
        [$hold] = $this->replay->replay('services-release/02-hold', self::NO_OPTIONS);
        $this->recordBookingHold('services-release/02-hold', $hold);
        $this->replay->replay('services-release/03-quote', self::DEPOSIT_QUOTE);
        [$created] = $this->replay->replay('services-release/04-booking-hosted-deposit', self::NO_OPTIONS);
        $txn = $this->payDeposit((string) $created->json('booking.manage_token')); // the customer opened the gateway…
        [$released] = $this->replay->replay('services-release/05-release-hold'); // … then released the time in the app
        $this->assertSame('booking', $released->json('cancelled.kind'));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('booking_slot_cells')->count(), 'the time is free');

        \Juwri\Modules\Payments\Services\Gateways\Settlement::settleForBuyer($txn->fresh(), 'stripe', 'pi_sidra_late', claim: true);
        $this->replay->replay('services-release/06-booking-late-deposit');

        // someone else takes the time, then the owner accepts the late deposit: pending_approval, same manage token
        $other = app(\Juwri\Modules\Bookings\Services\HoldService::class);
        $other->hold($store, [ApiFixtures::SVC_HAIRCUT], null, Carbon::parse('2026-10-04 13:30:00', 'UTC'), 'venue', null);
        $booking = \Juwri\Modules\Bookings\Models\Booking::query()->where('public_id', $created->json('booking.public_id'))->firstOrFail();
        $this->assertSame('pending_approval', app(\Juwri\Modules\Bookings\Services\DepositService::class)->acceptLate($booking, $other, app(\Juwri\Modules\Bookings\Services\SlotEngine::class)));
        $this->replay->replay('services-release/07-booking-late-accepted-needs-slot');
    }

    public function test_the_contract_validator_rejects_a_float_minor(): void
    {
        $example = ExampleReplayer::load('food/01-quote');
        $body = $example['response']['body'];
        $body['quote']['total']['minor'] = 142.1;
        $response = new TestResponse(response()->json($body, 200, ['Cache-Control' => 'no-store']));
        $this->expectException(AssertionFailedError::class);
        ApiContract::assertResponse('quoteCart', 'POST', '/api/v1/storefront/quote', $response);
    }
}
