<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-16 (D-P1-16-5): the catalog, customer and dev MCP servers moved from hand-rolled controllers onto laravel/mcp
 * servers. This pins their whole wire contract — every response body (byte-exact, as sha256 + length + head) and
 * HTTP status for initialize, ping, tools/list, every tool call, refusals, notifications, batches and the protocol
 * header — captured from the hand-rolled controllers (MCP_SNAPSHOT=write) and replayed against the servers. The one
 * difference (D-P1-16-7, accepted): an empty HTTP 202 (notification-only request) now says `Content-Type:
 * application/json` (laravel/mcp's HttpTransport) instead of `text/html` — no body, same status.
 *
 * SQLite only: the bodies carry auto-increment ids, which MySQL/PostgreSQL do not roll back between tests (the
 * behaviour on every driver is covered by CatalogMcpTest, CustomerMcpTest and the DevMcp*Test classes).
 */
class McpToolSetServersCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('ids in the pinned bodies are only stable on SQLite');
        }
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, ThrottleRequests::class, AbuseRateLimit::class]);
        Carbon::setTestNow('2026-10-05 12:00:00');
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function snapshot(string $name, array $actual): void
    {
        $path = base_path('tests/Fixtures/mcp/'.$name.'.json');
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (getenv('MCP_SNAPSHOT') === 'write') {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $json);
        }
        $this->assertFileExists($path, "snapshot {$name} missing — run with MCP_SNAPSHOT=write");
        $this->assertSame(file_get_contents($path), $json, "{$name} drifted from the characterisation snapshot");
    }

    /** @return array{status: int, type: string, sha256: string, length: int, head: string} */
    private function pin(string $uri, mixed $body, array $headers = []): array
    {
        $res = $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json']), is_string($body) ? $body : (string) json_encode($body));
        $content = (string) $res->getContent();

        return [
            'status' => $res->getStatusCode(),
            'type' => (string) $res->headers->get('Content-Type'),
            'sha256' => hash('sha256', $content),
            'length' => strlen($content),
            'head' => mb_substr($content, 0, 160),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $calls  label => tool arguments (label "name" or "name#n")
     * @return array<string, array<string, mixed>>
     */
    private function protocol(string $uri, array $calls, array $headers = []): array
    {
        $rpc = fn (string $method, array $params = [], int $id = 1) => ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
        $out = [
            'initialize' => $this->pin($uri, $rpc('initialize', ['protocolVersion' => '2025-06-18']), $headers),
            'initialize_old' => $this->pin($uri, $rpc('initialize', ['protocolVersion' => '2024-11-05']), $headers),
            'initialize_unknown' => $this->pin($uri, $rpc('initialize', ['protocolVersion' => '1999-01-01']), $headers),
            'ping' => $this->pin($uri, $rpc('ping'), $headers),
            'tools_list' => $this->pin($uri, $rpc('tools/list'), $headers),
            'unknown_method' => $this->pin($uri, $rpc('nope/nope'), $headers),
            'unknown_tool' => $this->pin($uri, $rpc('tools/call', ['name' => 'nope', 'arguments' => []]), $headers),
            'notification' => $this->pin($uri, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers),
            'batch' => $this->pin($uri, [$rpc('ping', [], 7), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $rpc('nope', [], 8)], $headers),
            'notifications_only_batch' => $this->pin($uri, [['jsonrpc' => '2.0', 'method' => 'notifications/initialized']], $headers),
            'scalar_batch' => $this->pin($uri, '[1]', $headers),
            'invalid_json' => $this->pin($uri, '{nope', $headers),
            'bad_protocol_header' => $this->pin($uri, $rpc('ping'), $headers + ['MCP-Protocol-Version' => '1999-01-01']),
            'foreign_origin' => $this->pin($uri, $rpc('ping'), $headers + ['Origin' => 'https://evil.example']),
        ];
        foreach ($calls as $label => $args) {
            $out['call:'.$label] = $this->pin($uri, $rpc('tools/call', ['name' => explode('#', $label)[0], 'arguments' => $args]), $headers);
        }

        return $out;
    }

    private function store(string $name, string $slug, string $address, string $dish): Store
    {
        $plan = TestPlans::create(['name' => 'Plan '.$slug, 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['name' => 'Owner '.$slug, 'email' => $slug.'@owner.test', 'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => $slug, 'color' => '#111', 'title' => $name, 'sub_title' => 'Tasty',
            'description' => 'Great food', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => $address, 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $dish, 'price' => 8, 'active' => 1, 'is_vegan' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $dish.' Deluxe', 'price' => 14, 'active' => 1]);

        return $store;
    }

    public function test_catalog_wire_contract_is_pinned(): void
    {
        $a = $this->store('Shawarma House', 'shawarma-house', 'Tripoli', 'Shawarma');
        $this->store('Pizza Place', 'pizza-place', 'Benghazi', 'Pizza');

        $this->snapshot('catalog', $this->protocol('/mcp/catalog', [
            'search_stores' => [],
            'search_stores#query' => ['query' => 'Shawarma', 'limit' => 5],
            'search_stores#city' => ['city' => 'Benghazi', 'store_type' => 'restaurant'],
            'search_items' => ['query' => 'Pizza'],
            'search_items#dietary' => ['dietary' => 'vegan', 'max_price' => 10, 'city' => 'Tripoli'],
            'get_store' => ['slug' => 'shawarma-house'],
            'get_store#id' => ['id' => $a->id],
            'get_store#missing' => ['slug' => 'nope'],
            'get_store#noargs' => [],
            'list_starter_menus' => [],
        ]));
    }

    public function test_customer_wire_contract_is_pinned(): void
    {
        $store = $this->store('Diner', 'diner', 'Tripoli', 'Burger');
        $other = $this->store('Other', 'other-diner', 'Tripoli', 'Fries');
        $dana = Customer::create(['store_id' => $store->id, 'name' => 'Dana', 'phone' => '550000001']);
        $eve = Customer::create(['store_id' => $store->id, 'name' => 'Eve', 'phone' => '550000002']);
        $item = Menu::where('store_id', $store->id)->orderBy('id')->firstOrFail();
        $order = Order::create(['store_id' => $store->id, 'customer_id' => $dana->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'Dana', 'phone_number' => '550000001', 'track_token' => 'trk-dana-1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2, 'variation' => 0, 'status' => 'pending']);
        $foreign = Order::create(['store_id' => $store->id, 'customer_id' => $eve->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'Eve', 'phone_number' => '550000002']);
        $elsewhere = Order::create(['store_id' => $other->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'X', 'phone_number' => '1']);
        GiftCard::create(['store_id' => $store->id, 'code' => 'GC-DANA', 'currency' => 'USD', 'initial_value' => 25, 'status' => 'active', 'purchaser_customer_id' => $dana->id]);
        $raw = CustomerToken::issue($dana);

        $this->snapshot('customer', $this->protocol('/mcp/customer', [
            'my_orders' => [],
            'my_orders#limit' => ['limit' => 1],
            'order_detail' => ['order_id' => $order->id],
            'order_detail#foreign' => ['order_id' => $foreign->id],
            'order_detail#elsewhere' => ['order_id' => $elsewhere->id],
            'track_order' => ['order_id' => $order->id],
            'track_order#token' => ['track_token' => 'trk-dana-1'],
            'track_order#noargs' => [],
            'reorder' => ['order_id' => $order->id],
            'reorder#foreign' => ['order_id' => $foreign->id],
            'my_profile' => ['customer_id' => $eve->id, 'phone' => '550000002'],
            'my_bookings' => [],
            'my_gift_cards' => [],
        ], ['Authorization' => 'Bearer '.$raw]));

        // Auth: no token / unknown token.
        $this->postJson('/mcp/customer', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401);
        $this->postJson('/mcp/customer', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer nope'])->assertStatus(401);
    }

    public function test_dev_wire_contract_is_pinned(): void
    {
        $this->snapshot('dev', $this->protocol('/mcp/dev', [
            'learn_platform' => [],
            'learn_platform#themes' => ['topic' => 'themes'],
            'learn_platform#bad' => ['topic' => 'nope'],
            'get_liquid_reference' => ['kind' => 'filters'],
            'get_liquid_reference#bad' => ['kind' => 'nope'],
            'get_section_schema' => [],
            'get_section_schema#color' => ['setting_type' => 'color'],
            'get_section_schema#bad' => ['setting_type' => 'nope'],
            'search_docs' => ['query' => 'webhook', 'max_results' => 2],
            'search_docs#pillar' => ['query' => 'menu', 'pillar' => 'users'],
            'search_docs#empty' => [],
            'fetch_full_doc' => ['path' => 'api/webhooks'],
            'fetch_full_doc#bad' => ['path' => 'nope/nope'],
            'validate_theme' => ['files' => ['sections/hero.liquid' => '<h1>{{ section.settings.title }}</h1>{% schema %}{"name":"Hero","settings":[{"type":"text","id":"title","label":"T"}]}{% endschema %}']],
            'validate_theme#bad' => ['files' => ['sections/x.liquid' => '{{ x | shopify_only }}']],
            'validate_theme#none' => [],
            'list_webhook_events' => [],
            'submit_feedback' => ['message' => 'The webhooks doc lacks retries', 'path' => 'api/webhooks'],
            'submit_feedback#empty' => [],
        ]));
    }
}
