<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaItemResolver;
use Juwri\Modules\Menu\Services\Catalog\CatalogExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN WV.V — partner SKU mapping, which had never once run.
 *
 * `DeliverectProvider` and `UberEatsProvider` both asked
 * `Schema::hasColumn('menus', 'external_sku')`. The table is `menu`, SINGULAR,
 * so the call answered false forever and the SKU branch was dead: an inbound
 * item resolved only if the partner echoed our bare numeric id. On top of that
 * the column itself existed in no migration at all, even though `CatalogExport`
 * has always published `external_sku ?? id` outbound and T7's
 * `IndiaItemResolver` reads it inbound.
 *
 * Both halves are fixed here — the table name, and the column — so this file
 * asserts the round trip an aggregator integration actually depends on: we
 * publish a store's own SKU, the partner echoes it back, and it resolves to the
 * right dish for the right tenant.
 */
class ParityExternalSkuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    private function store(string $provider, string $externalStoreId, string $secretKey, string $secret): array
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = \App\Models\MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1', 'position' => 0]);
        $item = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Chef Burger',
            'price' => 12, 'active' => 1, 'position' => 0, 'external_sku' => 'CHEF-BURGER',
        ]);

        AggregatorConnection::create([
            'provider' => $provider, 'store_id' => $r->id, 'branch_id' => null,
            'external_store_id' => $externalStoreId, 'status' => 'active',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => $provider, 'provider' => $provider,
            'secrets' => [$secretKey => $secret], 'active' => true,
        ]);

        return [$r, $item];
    }

    /** The column the four resolvers and CatalogExport have always assumed. */
    public function test_the_menu_table_carries_an_external_sku_column(): void
    {
        $this->assertTrue(Schema::hasColumn(new Menu()->getTable(), 'external_sku'));
        // …and the misspelled one is still not a table, which is the whole bug.
        $this->assertFalse(Schema::hasTable('menus'));
    }

    public function test_deliverect_resolves_an_item_by_its_partner_sku(): void
    {
        [$r, $item] = $this->store('deliverect', 'CHLINK-1', 'webhook_secret', 'dsecret');
        // A2: the inbound path is gated on aggregator_deliverect (dark by default),
        // and the real signature header is x-server-authorization-hmac-sha256.
        config(['settings.admin_allow_aggregator_deliverect' => true]);

        $payload = [
            'channelLinkId' => 'CHLINK-1', 'orderId' => 'DLV-SKU-1',
            'customer' => ['name' => 'Sam', 'phoneNumber' => '+123'],
            'items' => [['plu' => 'CHEF-BURGER', 'quantity' => 2, 'name' => 'Chef Burger']],
        ];
        $body = json_encode($payload);

        $this->call('POST', route('webhook.delivery.inbound', 'deliverect'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SERVER_AUTHORIZATION_HMAC_SHA256' => hash_hmac('sha256', $body, 'dsecret'),
        ], $body)->assertSuccessful();

        $order = Order::where('store_id', $r->id)->firstOrFail();
        $this->assertSame((int) $item->id, (int) $order->items->first()->item_id);
        $this->assertSame(2, (int) $order->items->first()->quantity);
    }

    public function test_uber_eats_resolves_an_item_by_its_partner_sku(): void
    {
        [$r, $item] = $this->store('uber_eats', 'UE-SKU-STORE', 'client_secret', 'usecret');

        \Juwri\Modules\Channels\Services\Aggregators\UberEats\FakeUberEatsClient::$orders['UE-SKU-1'] = [
            'id' => 'UE-SKU-1',
            'store' => ['id' => 'UE-SKU-STORE'],
            'customer' => ['name' => 'Eater', 'phone' => '+199'],
            'cart' => ['items' => [['external_data' => 'CHEF-BURGER', 'quantity' => 1, 'title' => 'Chef Burger']]],
            'payment' => ['charges' => ['total' => ['amount' => 1200]]],
        ];

        $payload = [
            'event_type' => 'orders.notification',
            'meta' => ['store_id' => 'UE-SKU-STORE', 'resource_id' => 'UE-SKU-1'],
        ];
        $body = json_encode($payload);

        $res = $this->call('POST', route('webhook.delivery.inbound', 'uber_eats'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => hash_hmac('sha256', $body, 'usecret'),
        ], $body);

        $res->assertSuccessful();
        $order = Order::where('store_id', $r->id)->firstOrFail();
        $this->assertSame((int) $item->id, (int) $order->items->first()->item_id);
    }

    /** A SKU belonging to another store must resolve to nothing, not to their dish. */
    public function test_a_partner_sku_is_scoped_to_its_tenant(): void
    {
        [$mine] = $this->store('zomato', 'ZOM-1', 'webhook_secret', 'zsecret');
        [$theirs] = $this->store('zomato', 'ZOM-2', 'webhook_secret', 'zsecret2');

        $theirItem = Menu::where('store_id', $theirs->id)->firstOrFail();
        $theirItem->update(['external_sku' => 'ONLY-THEIRS']);

        $this->assertNull(IndiaItemResolver::menuId((int) $mine->id, 'ONLY-THEIRS'));
        $this->assertSame(
            (int) $theirItem->id,
            (int) IndiaItemResolver::menuId((int) $theirs->id, 'ONLY-THEIRS')
        );
    }

    /** Outbound and inbound agree: we publish the SKU we then accept back. */
    public function test_the_catalog_publishes_the_same_sku_the_resolver_accepts(): void
    {
        [$r, $item] = $this->store('zomato', 'ZOM-9', 'webhook_secret', 'z9');

        $export = new CatalogExport()->forRestaurant((int) $r->id);
        $skus = collect(data_get($export, 'categories.*.items.*.sku', []))->all();

        $this->assertContains('CHEF-BURGER', $skus);
        $this->assertSame((int) $item->id, (int) IndiaItemResolver::menuId((int) $r->id, 'CHEF-BURGER'));
    }

    /** An item with no SKU still resolves by bare id — nothing regresses. */
    public function test_an_item_without_a_sku_still_resolves_by_id(): void
    {
        [$r] = $this->store('zomato', 'ZOM-3', 'webhook_secret', 'z3');
        $plain = Menu::create(['store_id' => $r->id, 'name' => 'Plain', 'price' => 4, 'active' => 1]);

        $this->assertSame((int) $plain->id, (int) IndiaItemResolver::menuId((int) $r->id, (string) $plain->id));
    }
}
