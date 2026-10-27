<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorefrontToken;
use App\Services\Agents\AgentToolsService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-16 step 1 — characterisation of the owner MCP (/mcp) and the storefront MCP (/mcp/storefront) before the move
 * onto laravel/mcp + the shared agent tool registry. Pinned against snapshots in tests/Fixtures/mcp/:
 *  - tools/list: every tool's name, description, inputSchema and annotations (owner: full-ability token, every
 *    feature on; storefront: a restaurant and a retail store — create_order's `type` enum follows the vertical);
 *  - tools/call: the result SHAPE (keys and value types) of every owner tool on seeded data, and of every storefront
 *    tool;
 *  - the shared chat tool schemas (web chat / WhatsApp / SMS / voice);
 *  - auth failures and store scoping: every owner tool that takes a store (or a coupon) refuses another owner's.
 * MCP_SNAPSHOT=write regenerates the fixtures (only to record an intended change).
 */
class McpCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['settings.admin_allow_crm_suite' => 1, 'settings.admin_allow_multi_vertical' => 1]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function snapshot(string $name, mixed $actual): void
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

    /** Keys and value types, recursively (ids, timestamps and money values vary; the shape must not). */
    private function shape(mixed $v): mixed
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return $v === [] ? [] : [$this->shape($v[0])];
            }
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = $this->shape($x);
            }
            ksort($out);

            return $out;
        }

        return get_debug_type($v);
    }

    private function owner(string $type = 'restaurant'): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111', 'store_type' => $type,
            'title' => 'Resto', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);

        return [$user, $store];
    }

    private function apiToken(User $user, array $abilities = ['*']): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => $abilities]);

        return $raw;
    }

    private function rpc(string $raw, string $method, array $params = [])
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['Authorization' => 'Bearer '.$raw]);
    }

    private function callTool(string $raw, string $tool, array $args): array
    {
        return (array) $this->rpc($raw, 'tools/call', ['name' => $tool, 'arguments' => $args])->json();
    }

    /** @return array<string, mixed> seeded ids */
    private function seedData(User $user, Store $store): array
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => 30, 'active' => 1]);
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'Ali', 'phone_number' => '555', 'is_paid' => 0, 'created_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2, 'variation' => 0, 'status' => 'pending']);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Ali', 'phone' => '555', 'email' => 'ali@x.test']);
        $spare = MenuCategory::create(['store_id' => $store->id, 'name' => 'Spare']);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'options' => ['Size' => 'M'], 'price' => 31, 'active' => 1]);
        $coupon = Coupon::create(['user_id' => $user->id, 'code' => 'C'.Str::upper(Str::random(5)), 'coupon_type' => 'percentage', 'percentage_rate' => 10, 'limit' => 5]);

        return ['category' => $cat->id, 'item' => $item->id, 'order' => $order->id, 'customer' => $customer->id, 'coupon' => $coupon->id, 'spare' => $spare->id, 'variant' => $variant->id];
    }

    public function test_owner_tools_list_is_pinned(): void
    {
        [$user] = $this->owner();
        $tools = $this->rpc($this->apiToken($user), 'tools/list')->assertOk()->json('result.tools');

        $this->snapshot('owner-tools-list', $tools);
    }

    public function test_owner_initialize_and_errors_are_pinned(): void
    {
        [$user] = $this->owner();
        $raw = $this->apiToken($user, ['read']);

        $out = [
            'initialize' => $this->rpc($raw, 'initialize', ['protocolVersion' => '2025-06-18'])->json(),
            'ping_raw' => $this->rpc($raw, 'ping')->getContent(),
            'unknown_method' => $this->rpc($raw, 'nope/nope')->json(),
            'unknown_tool' => $this->callTool($raw, 'nope', []),
            'needs_write' => $this->callTool($raw, 'update_business_hours', ['store_id' => 1, 'business_hours' => 'x']),
        ];
        $this->snapshot('owner-protocol', $out);

        // Auth failures: no token, unknown token, expired token, and an HTTP-level ability miss.
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer nope'])->assertStatus(401);
        $expired = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 'e', 'token' => hash('sha256', $expired), 'abilities' => ['read'], 'expires_at' => now()->subDay()]);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer '.$expired])->assertStatus(401);
        $hooksOnly = $this->apiToken($user, ['webhooks:write']);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer '.$hooksOnly])->assertStatus(403);
    }

    /** @return array<string, array<string, mixed>> tool => valid args */
    private function validArgs(Store $store, array $ids): array
    {
        $s = ['store_id' => $store->id];

        return [
            'list_restaurants' => [],
            'get_menu' => $s,
            'list_orders' => $s,
            'get_order' => $s + ['order_id' => $ids['order']],
            'get_analytics' => $s + ['days' => 7],
            'update_order_status' => $s + ['order_id' => $ids['order'], 'status' => OrderStatus::Preparing->value],
            'add_menu_item' => $s + ['category_id' => $ids['category'], 'name' => 'New', 'price' => 12.5],
            'update_menu_item' => $s + ['item_id' => $ids['item'], 'name' => 'Kabsa 2'],
            'list_categories' => $s,
            'add_category' => $s + ['name' => 'Drinks'],
            'update_category' => $s + ['category_id' => $ids['category'], 'name' => 'Main dishes'],
            'delete_category' => $s + ['category_id' => $ids['spare']],
            'delete_menu_item' => $s + ['item_id' => $ids['item']],
            'set_item_availability' => $s + ['item_id' => $ids['item'], 'available' => false],
            'list_customers' => $s,
            'get_customer' => $s + ['customer_id' => $ids['customer']],
            'sales_report' => $s,
            'get_restaurant_settings' => $s,
            'update_business_hours' => $s + ['business_hours' => 'Daily 9-5'],
            'list_coupons' => [],
            'create_coupon' => ['code' => 'NEW'.Str::upper(Str::random(4)), 'coupon_type' => 'fixed', 'value' => 5],
            'update_coupon' => ['coupon_id' => $ids['coupon'], 'value' => 7],
            'delete_coupon' => ['coupon_id' => $ids['coupon']],
            'list_brands' => $s,
            'list_variants' => $s + ['item_id' => $ids['item']],
            'list_shipments' => $s,
            'list_fitments' => $s + ['item_id' => $ids['item']],
            'list_expiring_items' => $s,
            'add_variant' => $s + ['item_id' => $ids['item'], 'options' => ['Size' => 'L'], 'price' => 9],
            'update_variant' => $s + ['variant_id' => $ids['variant'], 'price' => 9],
            'delete_variant' => $s + ['variant_id' => $ids['variant']],
        ];
    }

    public function test_owner_tools_call_shapes_are_pinned(): void
    {
        [$user, $store] = $this->owner();
        $raw = $this->apiToken($user);
        $ids = $this->seedData($user, $store);
        $names = collect($this->rpc($raw, 'tools/list')->json('result.tools'))->pluck('name')->sort()->values()->all();
        $args = $this->validArgs($store, $ids);
        $this->assertSame($names, collect(array_keys($args))->sort()->values()->all(), 'every listed tool has characterisation args');

        $out = [];
        // Reads and non-destructive writes first, destructive ones last (they remove the seeded rows).
        $order = array_merge(array_diff(array_keys($args), ['delete_category', 'delete_menu_item', 'delete_coupon', 'delete_variant']), ['delete_variant', 'delete_menu_item', 'delete_category', 'delete_coupon']);
        foreach ($order as $tool) {
            $res = $this->callTool($raw, $tool, $args[$tool]);
            $text = $res['result']['content'][0]['text'] ?? null;
            $out[$tool] = isset($res['error'])
                ? ['error' => $res['error']]
                : ['envelope' => $this->shape(array_diff_key($res['result'], ['content' => 1])), 'data' => $this->shape(json_decode((string) $text, true))];
        }
        ksort($out);

        $this->snapshot('owner-tools-call', $out);
    }

    public function test_every_owner_tool_refuses_another_owners_store_or_coupon(): void
    {
        [$alice, $aStore] = $this->owner();
        [$bob, $bStore] = $this->owner();
        $bIds = $this->seedData($bob, $bStore);
        $raw = $this->apiToken($alice);

        foreach ($this->validArgs($bStore, $bIds) as $tool => $args) {
            if ($tool === 'list_restaurants' || $tool === 'list_coupons' || $tool === 'create_coupon') {
                continue; // no store/coupon argument: always the caller's own
            }
            $res = $this->callTool($raw, $tool, $args);
            $this->assertSame(-32004, $res['error']['code'] ?? null, "{$tool} must refuse Bob's store/coupon: ".json_encode($res));
        }
        $this->assertSame([$aStore->id], array_column(json_decode($this->callTool($raw, 'list_restaurants', [])['result']['content'][0]['text'], true), 'id'));
        $this->assertSame([], json_decode($this->callTool($raw, 'list_coupons', [])['result']['content'][0]['text'], true));
        // Bob's rows untouched.
        $this->assertTrue(Menu::whereKey($bIds['item'])->exists());
        $this->assertTrue(Coupon::whereKey($bIds['coupon'])->exists());
        $this->assertTrue(MenuVariant::whereKey($bIds['variant'])->exists());
        $this->assertTrue(MenuCategory::whereKey($bIds['spare'])->exists());
    }

    private function storefront(string $type): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'storefront_api' => '1', 'agent_api' => '1'], 'status' => 1,
        ]);
        [$owner, $store] = $this->owner($type);
        $owner->forceFill(['group_id' => (string) $plan->id])->save();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => 30, 'active' => 1]);
        $raw = Str::random(40);
        StorefrontToken::create(['store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => ['agent'], 'active' => true]);

        return [$store, $raw];
    }

    private function storefrontRpc(string $raw, string $method, array $params = [])
    {
        return $this->postJson(route('mcp.storefront'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['X-Storefront-Token' => $raw]);
    }

    public function test_storefront_tools_list_and_calls_are_pinned(): void
    {
        [$resto, $rRaw] = $this->storefront('restaurant');
        [, $shopRaw] = $this->storefront('retail');
        $item = Menu::where('store_id', $resto->id)->value('id');

        $out = [
            'list_restaurant' => $this->storefrontRpc($rRaw, 'tools/list')->json('result.tools'),
            'create_order_type_enum_retail' => collect($this->storefrontRpc($shopRaw, 'tools/list')->json('result.tools'))->firstWhere('name', 'create_order')['inputSchema']['properties']['type'] ?? null,
            'initialize' => $this->storefrontRpc($rRaw, 'initialize', ['protocolVersion' => '2025-06-18'])->json(),
        ];
        $calls = [
            'search_menu' => ['query' => 'kab'], 'get_item' => ['item_id' => $item],
            'validate_cart' => ['items' => [['item_id' => $item, 'quantity' => 1]]], 'restaurant_info' => [],
            'order_status' => ['track_token' => 'nope'], 'order_history' => ['phone' => '555'],
            'store_credit_balance' => ['phone' => '555'], 'reorder_last' => ['phone' => '555'], 'order_eta' => [],
            'suggest_upsell' => ['cart_item_ids' => [$item]], 'dietary_answer' => ['item_id' => $item, 'tag' => 'vegan'],
            'hotel_availability' => [],
            'create_order' => ['items' => [['item_id' => $item, 'quantity' => 1]], 'customer_name' => 'Ali', 'phone' => '555'],
            'nope' => [],
        ];
        foreach ($calls as $tool => $args) {
            $res = $this->storefrontRpc($rRaw, 'tools/call', ['name' => $tool, 'arguments' => $args])->json();
            $text = $res['result']['content'][0]['text'] ?? null;
            $decoded = json_decode((string) $text, true);
            $out['call'][$tool] = isset($res['error']) ? ['error' => $res['error']] : [
                'envelope' => $this->shape(array_diff_key($res['result'], ['content' => 1])),
                'data' => $decoded === null ? ['text' => $text] : $this->shape($decoded),
            ];
        }

        $this->snapshot('storefront', $out);
    }

    public function test_chat_tool_schemas_are_pinned(): void
    {
        $this->snapshot('chat-tool-schemas', AgentToolsService::toolSchemas());
    }
}
