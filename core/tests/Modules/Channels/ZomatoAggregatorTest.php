<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\PriceSchedule;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\AggregatorSyncLog;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorRegistry;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\FakeZomatoClient;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoMenuPusher;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoProvider;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T7 — Zomato driver, end to end against the mock partner.
 *
 * Everything on the wire is ASSUMED until certification, so these tests pin the
 * BEHAVIOUR (who is trusted, what is idempotent, what is refused) rather than the
 * field names — the field names live in ZomatoPayloadMapper and are expected to
 * change once partner access lands.
 */
class ZomatoAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        FakeZomatoClient::reset();
        $this->app->bind(ZomatoClientInterface::class, fn () => new FakeZomatoClient);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** @return array{0: Store, 1: Menu} */
    private function store(string $outlet = 'ZOM-1', string $secret = 'zsecret'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Paneer Tikka', 'price' => 240, 'active' => '1']);

        AggregatorConnection::create([
            'provider' => 'zomato', 'store_id' => $store->id,
            'external_store_id' => $outlet, 'status' => 'active', 'auto_accept' => true,
        ]);
        Credential::create([
            'store_id' => $store->id, 'feature' => 'zomato', 'provider' => 'zomato',
            'secrets' => ['webhook_secret' => $secret, 'api_key' => 'k', 'api_secret' => 's'], 'active' => true,
        ]);

        return [$store, $item];
    }

    private function payload(string $outlet, string $orderId, array $dishes, array $extra = []): array
    {
        return array_merge([
            'event' => 'order.placed',
            'res_id' => $outlet,
            'order' => array_merge([
                'order_id' => $orderId,
                'commission' => 0,
                'customer' => ['name' => 'Aarav', 'phone' => '+919876500000', 'address' => '12 MG Road, Bengaluru'],
                'dishes' => $dishes,
            ], $extra),
        ], []);
    }

    /** @param string|false|null $signature null = sign it, false = send none. */
    private function hook(array $payload, string|false|null $signature = null, string $secret = 'zsecret')
    {
        $body = json_encode($payload);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        if ($signature !== false) {
            $headers['HTTP_X_ZOMATO_SIGNATURE'] = $signature ?? hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', route('webhook.zomato.inbound'), [], [], [], $headers, $body);
    }

    /* ------------------------------------------------------------- registry */

    public function test_the_driver_is_registered_in_the_hardcoded_builtins(): void
    {
        // There is no auto-discovery: if the class is not in
        // AggregatorRegistry::builtins() the whole channel is unreachable.
        $this->assertInstanceOf(ZomatoProvider::class, AggregatorRegistry::get('zomato'));
        $this->assertContains('zomato', AggregatorRegistry::keys());
    }

    /* ------------------------------------------------------------- ingest */

    public function test_signed_order_ingests_maps_and_acknowledges(): void
    {
        [$store, $item] = $this->store();

        $res = $this->hook($this->payload('ZOM-1', 'Z-1', [
            ['dish_id' => 'zom_'.$item->id, 'quantity' => 3, 'name' => 'Paneer Tikka'],
        ], ['commission' => 42.5, 'instructions' => 'Less spicy']))->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertNotNull($order);
        $this->assertSame('zomato', $order->source);
        $this->assertSame('delivery', $order->type);
        $this->assertSame(3, (int) $order->items()->sum('quantity'));
        $this->assertSame('Aarav', $order->customer_name);
        $this->assertStringContainsString('12 MG Road', (string) $order->address);
        $this->assertStringContainsString('Less spicy', (string) $order->message);
        $this->assertEqualsWithDelta(42.5, (float) $order->channel_fee, 0.001);

        // The order was acknowledged inside the partner's accept window.
        $this->assertContains('acknowledgeOrder', FakeZomatoClient::ops());
        $this->assertSame('Z-1', FakeZomatoClient::lastCall('acknowledgeOrder')['orderId']);

        // …and the idempotency ledger row exists for the replay path.
        $this->assertTrue(DeliveryOrder::where('provider', 'zomato')->where('external_id', 'Z-1')->exists());
    }

    public function test_manual_accept_connection_does_not_acknowledge(): void
    {
        [$store, $item] = $this->store();
        AggregatorConnection::where('provider', 'zomato')->update(['auto_accept' => false]);

        $this->hook($this->payload('ZOM-1', 'Z-M', [['dish_id' => (string) $item->id, 'quantity' => 1]]))
            ->assertStatus(201);

        $this->assertNotContains('acknowledgeOrder', FakeZomatoClient::ops());
    }

    public function test_replayed_webhook_is_idempotent(): void
    {
        [$store, $item] = $this->store();
        $payload = $this->payload('ZOM-1', 'Z-DUP', [['dish_id' => 'zom_'.$item->id, 'quantity' => 2]]);

        $first = $this->hook($payload)->assertStatus(201)->json('order_id');
        $second = $this->hook($payload)->assertStatus(201)->json('order_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
        $this->assertSame(1, DeliveryOrder::where('external_id', 'Z-DUP')->count());
    }

    public function test_unmapped_lines_are_surfaced_not_dropped(): void
    {
        [$store, $item] = $this->store();

        $res = $this->hook($this->payload('ZOM-1', 'Z-UM', [
            ['dish_id' => 'zom_'.$item->id, 'quantity' => 1, 'name' => 'Paneer Tikka'],
            ['dish_id' => 'not-a-code', 'quantity' => 2, 'name' => 'Mystery Thali'],
        ]))->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame(1, (int) $order->items()->sum('quantity'));
        $this->assertStringContainsString('Mystery Thali', (string) $order->message);
    }

    public function test_paused_channel_is_acked_without_creating_an_order(): void
    {
        [$store, $item] = $this->store();
        AggregatorConnection::where('provider', 'zomato')->update(['status' => 'paused']);

        $this->hook($this->payload('ZOM-1', 'Z-P', [['dish_id' => (string) $item->id, 'quantity' => 1]]))
            ->assertStatus(202);

        $this->assertSame(0, Order::count());
    }

    /* ------------------------------------------------------------ security */

    public function test_bad_signature_is_rejected_with_no_side_effects(): void
    {
        [$store, $item] = $this->store();

        $this->hook(
            $this->payload('ZOM-1', 'Z-BAD', [['dish_id' => (string) $item->id, 'quantity' => 1]]),
            str_repeat('a', 64)
        )->assertStatus(401);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, DeliveryOrder::count());
        $this->assertSame([], FakeZomatoClient::ops());
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        [$store, $item] = $this->store();

        $this->hook(
            $this->payload('ZOM-1', 'Z-NOSIG', [['dish_id' => (string) $item->id, 'quantity' => 1]]),
            false
        )->assertStatus(401);

        $this->assertSame(0, Order::count());
    }

    public function test_a_connection_with_no_secret_configured_rejects_everything(): void
    {
        [$store, $item] = $this->store();
        // The vault entry is what makes a webhook verifiable. Without it the
        // abstract provider must REJECT, never fall through to "unsigned is fine".
        Credential::where('feature', 'zomato')->delete();

        $this->hook($this->payload('ZOM-1', 'Z-NC', [['dish_id' => (string) $item->id, 'quantity' => 1]]))
            ->assertStatus(401);

        $this->assertSame(0, Order::count());
    }

    public function test_unknown_outlet_is_404(): void
    {
        $this->store();

        $this->hook($this->payload('SOMEONE-ELSE', 'Z-X', []))->assertStatus(404);
        $this->assertSame(0, Order::count());
    }

    /**
     * Cross-tenant: outlet A's webhook, signed with A's secret, naming an item
     * that belongs to store B. Tenant scope comes from the stored connection, so
     * the foreign id resolves to nothing — and an order whose lines ALL failed to
     * map is refused outright by `OrderApiService` rather than created empty. A
     * hard 422 is the right answer here: a wholly unmappable order needs a human,
     * and a silent empty order would look like a successful import.
     */
    public function test_cross_tenant_item_codes_cannot_be_ordered(): void
    {
        [$storeA, $itemA] = $this->store('ZOM-A', 'secret-a');
        [$storeB, $itemB] = $this->store('ZOM-B', 'secret-b');

        $this->hook($this->payload('ZOM-A', 'Z-CT', [
            ['dish_id' => 'zom_'.$itemB->id, 'quantity' => 1, 'name' => "B's dish"],
        ]), null, 'secret-a')->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, DeliveryOrder::count());
    }

    /**
     * The mixed case: one of A's own items plus one of B's. A's item is ordered,
     * B's is surfaced on the order note for review — never quietly charged and
     * never quietly dropped.
     */
    public function test_a_foreign_item_alongside_a_local_one_is_surfaced_not_ordered(): void
    {
        [$storeA, $itemA] = $this->store('ZOM-A', 'secret-a');
        [$storeB, $itemB] = $this->store('ZOM-B', 'secret-b');

        $res = $this->hook($this->payload('ZOM-A', 'Z-CT2', [
            ['dish_id' => 'zom_'.$itemA->id, 'quantity' => 2, 'name' => "A's dish"],
            ['dish_id' => 'zom_'.$itemB->id, 'quantity' => 5, 'name' => "B's dish"],
        ]), null, 'secret-a')->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame((int) $storeA->id, (int) $order->store_id);
        $this->assertSame(2, (int) $order->items()->sum('quantity'));
        $this->assertSame((int) $itemA->id, (int) $order->items()->first()->item_id);
        $this->assertStringContainsString("B's dish", (string) $order->message);
    }

    /**
     * A store's secret must not verify another store's outlet — otherwise one
     * leaked tenant secret would open every outlet on the platform.
     */
    public function test_another_tenants_secret_cannot_sign_for_this_outlet(): void
    {
        $this->store('ZOM-A', 'secret-a');
        $this->store('ZOM-B', 'secret-b');

        $this->hook($this->payload('ZOM-A', 'Z-XS', []), null, 'secret-b')->assertStatus(401);
        $this->assertSame(0, Order::count());
    }

    /* -------------------------------------------------------- cancellation */

    public function test_cancellation_event_cancels_the_ingested_order(): void
    {
        [$store, $item] = $this->store();
        $orderId = $this->hook($this->payload('ZOM-1', 'Z-C', [
            ['dish_id' => 'zom_'.$item->id, 'quantity' => 1],
        ]))->assertStatus(201)->json('order_id');

        $cancel = $this->payload('ZOM-1', 'Z-C', []);
        $cancel['event'] = 'order.cancelled';

        $this->hook($cancel)->assertStatus(200);
        $this->assertSame('cancelled', Order::find($orderId)->status);

        // A cancellation for an order we never ingested is acked, not an error.
        $unknown = $this->payload('ZOM-1', 'Z-NEVER', []);
        $unknown['event'] = 'order.cancelled';
        $this->hook($unknown)->assertStatus(202);
    }

    /** A partner cancel restores the stock the ingest deducted (was a leak). */
    public function test_cancellation_restores_deducted_stock(): void
    {
        [$store, $item] = $this->store();
        $item->update(['track_inventory' => 1, 'stock_qty' => 10]);

        $this->hook($this->payload('ZOM-1', 'Z-CS', [
            ['dish_id' => 'zom_'.$item->id, 'quantity' => 3],
        ]))->assertStatus(201);
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'ingest deducted 3');

        $cancel = $this->payload('ZOM-1', 'Z-CS', []);
        $cancel['event'] = 'order.cancelled';
        $this->hook($cancel)->assertStatus(200);

        // Pre-fix the cancel never restored — stock stayed at 7 (a permanent leak).
        $this->assertSame(10, (int) $item->fresh()->stock_qty, 'cancel restored the 3');
    }

    /* -------------------------------------------------------- status push */

    public function test_local_status_change_pushes_to_the_partner(): void
    {
        [$store, $item] = $this->store();
        $orderId = $this->hook($this->payload('ZOM-1', 'Z-S', [
            ['dish_id' => 'zom_'.$item->id, 'quantity' => 1],
        ]))->assertStatus(201)->json('order_id');

        $order = Order::find($orderId);
        $order->status = 'ready';
        $order->save();

        $call = FakeZomatoClient::lastCall('updateOrderStatus');
        $this->assertNotNull($call, 'A status change must reach the aggregator.');
        $this->assertSame('Z-S', $call['orderId']);
        $this->assertSame('READY_FOR_PICKUP', $call['status']);
    }

    public function test_status_push_is_skipped_for_a_paused_channel(): void
    {
        [$store, $item] = $this->store();
        $orderId = $this->hook($this->payload('ZOM-1', 'Z-SP', [
            ['dish_id' => 'zom_'.$item->id, 'quantity' => 1],
        ]))->assertStatus(201)->json('order_id');

        AggregatorConnection::where('provider', 'zomato')->update(['status' => 'paused']);
        FakeZomatoClient::reset();

        $order = Order::find($orderId);
        $order->status = 'preparing';
        $order->save();

        $this->assertNotContains('updateOrderStatus', FakeZomatoClient::ops());
    }

    public function test_unmapped_status_is_not_pushed(): void
    {
        [$store, $item] = $this->store();
        $orderId = $this->hook($this->payload('ZOM-1', 'Z-SU', [
            ['dish_id' => 'zom_'.$item->id, 'quantity' => 1],
        ]))->assertStatus(201)->json('order_id');

        FakeZomatoClient::reset();
        $order = Order::find($orderId);
        $order->status = 'held_for_review'; // not in STATUS_MATRIX
        $order->save();

        $this->assertNotContains('updateOrderStatus', FakeZomatoClient::ops());
    }

    /* ------------------------------------------------------------ menu push */

    public function test_menu_push_serializes_the_catalog_export_at_list_prices(): void
    {
        [$store, $item] = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $veg = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dal Makhani',
            'price' => 180, 'active' => '1', 'position' => 1, 'is_vegan' => true,
        ]);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Gone',
            'price' => 90, 'active' => '1', 'position' => 2, 'is_sold_out' => 1,
        ]);

        $payload = new ZomatoMenuPusher()->build((int) $store->id, null, 'ZOM-1');

        $this->assertSame('ZOM-1', $payload['res_id']);
        $this->assertSame('list', $payload['price_basis']);

        $dal = collect($payload['items'])->firstWhere('name', 'Dal Makhani');
        $this->assertSame('zom_'.$veg->id, $dal['item_id']);
        $this->assertEqualsWithDelta(180.0, $dal['price'], 0.001);
        $this->assertSame('veg', $dal['food_type']);
        $this->assertTrue($dal['in_stock']);

        // An 86'd item is published as out of stock rather than omitted, so the
        // partner keeps the listing and simply greys it out.
        $gone = collect($payload['items'])->firstWhere('name', 'Gone');
        $this->assertFalse($gone['in_stock']);
    }

    /**
     * The W2/T5 seam, asserted rather than assumed: a happy-hour schedule must
     * NOT change the price we publish to the marketplace, because a pushed price
     * is frozen at the partner until the next successful push.
     */
    public function test_menu_push_ignores_scheduled_happy_hour_prices(): void
    {
        [$store, $item] = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $dish = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Biryani',
            'price' => 300, 'active' => '1', 'position' => 1,
        ]);

        PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'Half price all day', 'active' => true,
            'adjustment_type' => 'percent', 'adjustment_value' => 50,
            'scope' => 'store', 'days' => null,
            'starts_at' => null, 'ends_at' => null, 'priority' => 1,
        ]);
        MenuScheduleService::flush();

        // The schedule really is live: the storefront/POS quote is half price…
        $quote = MenuScheduleService::quote($dish->fresh());
        $this->assertEqualsWithDelta(150.0, (float) $quote['price'], 0.001);

        // …and the marketplace feed is deliberately NOT.
        $payload = new ZomatoMenuPusher()->build((int) $store->id, null, 'ZOM-1');
        $biryani = collect($payload['items'])->firstWhere('name', 'Biryani');

        $this->assertEqualsWithDelta(300.0, $biryani['price'], 0.001, 'Aggregator menus must carry LIST prices.');
        $this->assertSame((float) $dish->price, (float) $biryani['price']);
    }

    /* --------------------------------------------------------- availability */

    public function test_marking_an_item_sold_out_syncs_to_the_channel(): void
    {
        [$store, $item] = $this->store();

        $item->is_sold_out = true;
        $item->save();

        $call = FakeZomatoClient::lastCall('setItemAvailability');
        $this->assertNotNull($call, 'An 86 toggle must reach the marketplace.');
        $this->assertSame('zom_'.$item->id, $call['itemCode']);
        $this->assertFalse($call['available']);

        // …and back in stock again.
        $item->is_sold_out = false;
        $item->save();
        $this->assertTrue(FakeZomatoClient::lastCall('setItemAvailability')['available']);

        // Desired state is mirrored locally so the page is truthful during an outage.
        $settings = AggregatorConnection::where('provider', 'zomato')->first()->settings;
        $this->assertSame([], $settings['unavailable_items']);
    }

    public function test_availability_sync_is_skipped_when_the_channel_is_not_entitled(): void
    {
        [$store, $item] = $this->store();

        config(['settings.admin_allow_aggregator_zomato' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $item->is_sold_out = true;
        $item->save();

        $this->assertSame([], FakeZomatoClient::ops());
        $this->assertFalse(IndiaAggregatorSync::enabled((int) $store->id, 'zomato'));
    }

    public function test_a_partner_outage_cannot_fail_a_menu_save(): void
    {
        [$store, $item] = $this->store();
        FakeZomatoClient::$failNext = 'zomato 503';

        $item->is_sold_out = true;
        $item->save();

        // The local write survived…
        $this->assertTrue((bool) $item->fresh()->is_sold_out);
        // …and the failure is visible to the owner rather than silent.
        $log = AggregatorSyncLog::where('provider', 'zomato')->where('status', 'failed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('zomato 503', (string) $log->message);
    }

    public function test_outlet_open_close_toggles_on_the_platform(): void
    {
        [$store] = $this->store();
        $conn = AggregatorConnection::where('provider', 'zomato')->first();

        $this->assertTrue(IndiaAggregatorSync::isOutletOnline($conn));

        IndiaAggregatorSync::setOutletOnline($conn, false);
        $this->assertSame('ZOM-1', FakeZomatoClient::lastCall('setOutletOnline')['storeId']);
        $this->assertFalse(FakeZomatoClient::lastCall('setOutletOnline')['online']);
        $this->assertFalse(IndiaAggregatorSync::isOutletOnline($conn->fresh()));
    }
}
