<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\DeliveryOrder;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorRegistry;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\FakeSwiggyClient;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyMenuPusher;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyProvider;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\FakeZomatoClient;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T7 — Swiggy driver, end to end against the mock partner.
 *
 * Swiggy differs from Zomato in three ways that are worth their own tests: the
 * signature is sent with a `sha256=` PREFIX, the order envelope nests commission
 * under `charges`, and the status vocabulary is its own. Everything else is the
 * shared pipeline and is asserted once, here, on the Swiggy route.
 */
class SwiggyAggregatorTest extends TestCase
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
        FakeSwiggyClient::reset();
        $this->app->bind(SwiggyClientInterface::class, fn () => new FakeSwiggyClient);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** @return array{0: Store, 1: Menu} */
    private function store(string $outlet = 'SWG-1', string $secret = 'ssecret'): array
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
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Masala Dosa', 'price' => 120, 'active' => '1']);

        AggregatorConnection::create([
            'provider' => 'swiggy', 'store_id' => $store->id,
            'external_store_id' => $outlet, 'status' => 'active', 'auto_accept' => true,
        ]);
        Credential::create([
            'store_id' => $store->id, 'feature' => 'swiggy', 'provider' => 'swiggy',
            'secrets' => ['webhook_secret' => $secret, 'client_id' => 'c', 'client_secret' => 's'], 'active' => true,
        ]);

        return [$store, $item];
    }

    private function payload(string $outlet, string $orderId, array $items, array $extra = []): array
    {
        return array_merge([
            'event_type' => 'NEW_ORDER',
            'restaurant_id' => $outlet,
            'order' => array_merge([
                'order_id' => $orderId,
                'charges' => ['commission' => 0],
                'customer' => [
                    'name' => 'Diya',
                    'phone' => '+919812300000',
                    'address' => ['flat_no' => '4B', 'address' => 'Indiranagar', 'city' => 'Bengaluru'],
                ],
                'items' => $items,
            ], $extra),
        ], []);
    }

    /**
     * Swiggy prefixes the signature. The prefix is stripped by
     * AbstractAggregatorProvider::normalizeSignature(), and this helper sends the
     * REAL wire shape so that stripping stays covered.
     *
     * @param  string|false|null  $signature  null = sign it, false = send none.
     */
    private function hook(array $payload, string|false|null $signature = null, string $secret = 'ssecret')
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signature !== false) {
            $headers['HTTP_X_SWIGGY_SIGNATURE'] = $signature ?? ('sha256='.hash_hmac('sha256', $body, $secret));
        }

        return $this->call('POST', route('webhook.swiggy.inbound'), [], [], [], $headers, $body);
    }

    public function test_the_driver_is_registered_in_the_hardcoded_builtins(): void
    {
        $this->assertInstanceOf(SwiggyProvider::class, AggregatorRegistry::get('swiggy'));
        $this->assertContains('swiggy', AggregatorRegistry::keys());
    }

    public function test_prefixed_signature_is_accepted_and_the_order_ingests(): void
    {
        [$store, $item] = $this->store();

        $res = $this->hook($this->payload('SWG-1', 'S-1', [
            ['item_id' => 'swg_'.$item->id, 'quantity' => 2, 'name' => 'Masala Dosa'],
        ], ['charges' => ['commission' => 18.0], 'instructions' => 'Extra chutney']))->assertStatus(201);

        $order = Order::find($res->json('order_id'));
        $this->assertSame('swiggy', $order->source);
        $this->assertSame(2, (int) $order->items()->sum('quantity'));
        $this->assertSame('Diya', $order->customer_name);
        $this->assertStringContainsString('Indiranagar', (string) $order->address);
        $this->assertStringContainsString('Extra chutney', (string) $order->message);
        $this->assertEqualsWithDelta(18.0, (float) $order->channel_fee, 0.001);
        $this->assertContains('confirmOrder', FakeSwiggyClient::ops());
    }

    /** A raw hex signature (no prefix) must still verify — partners drift. */
    public function test_unprefixed_signature_is_also_accepted(): void
    {
        [$store, $item] = $this->store();
        $payload = $this->payload('SWG-1', 'S-RAW', [['item_id' => (string) $item->id, 'quantity' => 1]]);

        $this->hook($payload, hash_hmac('sha256', json_encode($payload), 'ssecret'))->assertStatus(201);
    }

    public function test_bad_signature_is_rejected_with_no_side_effects(): void
    {
        [$store, $item] = $this->store();

        $this->hook(
            $this->payload('SWG-1', 'S-BAD', [['item_id' => (string) $item->id, 'quantity' => 1]]),
            'sha256='.str_repeat('b', 64)
        )->assertStatus(401);

        $this->assertSame(0, Order::count());
        $this->assertSame([], FakeSwiggyClient::ops());
    }

    public function test_missing_secret_rejects_everything(): void
    {
        [$store, $item] = $this->store();
        Credential::where('feature', 'swiggy')->delete();

        $this->hook($this->payload('SWG-1', 'S-NC', [['item_id' => (string) $item->id, 'quantity' => 1]]))
            ->assertStatus(401);

        $this->assertSame(0, Order::count());
    }

    public function test_replayed_webhook_is_idempotent(): void
    {
        [$store, $item] = $this->store();
        $payload = $this->payload('SWG-1', 'S-DUP', [['item_id' => 'swg_'.$item->id, 'quantity' => 1]]);

        $a = $this->hook($payload)->assertStatus(201)->json('order_id');
        $b = $this->hook($payload)->assertStatus(201)->json('order_id');

        $this->assertSame($a, $b);
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
        $this->assertSame(1, DeliveryOrder::where('external_id', 'S-DUP')->count());
    }

    public function test_unknown_outlet_is_404(): void
    {
        $this->store();
        $this->hook($this->payload('NOT-OURS', 'S-X', []))->assertStatus(404);
    }

    public function test_cross_tenant_outlets_do_not_share_a_secret(): void
    {
        $this->store('SWG-A', 'secret-a');
        $this->store('SWG-B', 'secret-b');

        $this->hook($this->payload('SWG-A', 'S-XS', []), null, 'secret-b')->assertStatus(401);
        $this->assertSame(0, Order::count());
    }

    public function test_cancellation_event_cancels_the_order(): void
    {
        [$store, $item] = $this->store();
        $orderId = $this->hook($this->payload('SWG-1', 'S-C', [
            ['item_id' => 'swg_'.$item->id, 'quantity' => 1],
        ]))->assertStatus(201)->json('order_id');

        $cancel = $this->payload('SWG-1', 'S-C', []);
        $cancel['event_type'] = 'ORDER_CANCELLED';

        $this->hook($cancel)->assertStatus(200);
        $this->assertSame('cancelled', Order::find($orderId)->status);
    }

    public function test_status_matrix_uses_swiggys_own_vocabulary(): void
    {
        [$store, $item] = $this->store();
        $orderId = $this->hook($this->payload('SWG-1', 'S-S', [
            ['item_id' => 'swg_'.$item->id, 'quantity' => 1],
        ]))->assertStatus(201)->json('order_id');

        $order = Order::find($orderId);
        $order->status = 'out_for_delivery';
        $order->save();

        $call = FakeSwiggyClient::lastCall('updateOrderStatus');
        $this->assertNotNull($call);
        $this->assertSame('S-S', $call['orderId']);
        $this->assertSame('ORDER_PICKED_UP', $call['status']);
    }

    public function test_menu_push_marks_veg_items_and_disables_sold_out_ones(): void
    {
        [$store, $item] = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'South Indian', 'active' => '1', 'position' => 1]);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Idli',
            'price' => 60, 'active' => '1', 'position' => 1, 'is_vegan' => true,
        ]);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Chicken 65',
            'price' => 220, 'active' => '1', 'position' => 2, 'is_sold_out' => 1,
        ]);

        $payload = new SwiggyMenuPusher()->build((int) $store->id, null, 'SWG-1');

        $this->assertSame('SWG-1', $payload['restaurant_id']);
        $this->assertSame('list', $payload['price_basis']);

        $idli = collect($payload['items'])->firstWhere('name', 'Idli');
        $this->assertTrue($idli['is_veg']);
        $this->assertTrue($idli['enabled']);

        $chicken = collect($payload['items'])->firstWhere('name', 'Chicken 65');
        $this->assertFalse($chicken['is_veg']);
        $this->assertFalse($chicken['enabled']);
    }

    public function test_owner_availability_sync_pushes_every_item(): void
    {
        [$store, $item] = $this->store();
        Menu::create(['store_id' => $store->id, 'name' => 'Vada', 'price' => 40, 'active' => '1', 'is_sold_out' => 1]);

        $conn = AggregatorConnection::where('provider', 'swiggy')->first();
        $count = IndiaAggregatorSync::syncAvailability($conn);

        $this->assertSame(2, $count);
        $this->assertCount(2, array_filter(FakeSwiggyClient::ops(), fn ($op) => $op === 'setItemAvailability'));
        $this->assertFalse(FakeSwiggyClient::$itemAvailability['swg_'.Menu::where('name', 'Vada')->value('id')]);
        $this->assertTrue(FakeSwiggyClient::$itemAvailability['swg_'.$item->id]);
    }

    public function test_the_two_channels_are_independent(): void
    {
        // A store connected to Swiggy only must not have Zomato calls made on its
        // behalf, and vice versa — one shared sync service, two isolated channels.
        [$store, $item] = $this->store();
        FakeZomatoClient::reset();

        $item->is_sold_out = true;
        $item->save();

        $this->assertContains('setItemAvailability', FakeSwiggyClient::ops());
        $this->assertSame([], FakeZomatoClient::ops());
    }
}
