<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use App\Models\StorefrontToken;
use Juwri\Core\Models\User;
use App\Models\VehicleFitment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/mv-mcp-parity — the MCP surface must serve every vertical, not just
 * restaurants:
 *  1. Storefront MCP create_order is OPEN to non-food stores (it used to be
 *     hidden + refused for anything without takeaway/on-table), advertising the
 *     store's own fulfillment modes, and can actually place a ship order.
 *  2. restaurant_info / search_menu / get_item / admin get_menu return the
 *     goods/services attrs a non-food store has instead of only food fields.
 */
class MvMcpParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $type = 'restaurant'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'storefront_api' => '1', 'agent_api' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'store_type' => $type,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $store, array $extra = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat', 'active' => 1]);

        return Menu::create(array_merge([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Thing '.Str::random(4), 'price' => 9, 'pricing_unit' => 'each', 'active' => 1,
        ], $extra));
    }

    private function agentToken(Store $store): string
    {
        $raw = Str::random(48);
        StorefrontToken::create(['store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => ['agent'], 'active' => true]);

        return $raw;
    }

    private function storefrontRpc(string $raw, array $body)
    {
        return $this->postJson(route('mcp.storefront'), $body, ['X-Storefront-Token' => $raw]);
    }

    private function adminRpc(User $user, string $name, array $args)
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);

        return $this->postJson('/mcp',
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]],
            ['Authorization' => 'Bearer '.$raw]
        );
    }

    // ── P0: create_order open to verticals ──────────────────────────────────────

    public function test_storefront_mcp_lists_create_order_with_the_stores_own_modes(): void
    {
        $store = $this->store('retail');
        $raw = $this->agentToken($store);

        $tools = $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()->json('result.tools');

        $createOrder = collect($tools)->firstWhere('name', 'create_order');
        $this->assertNotNull($createOrder, 'create_order must be listed for a retail store');
        $enum = $createOrder['inputSchema']['properties']['type']['enum'];
        // Retail's own modes are advertised, not the food pair.
        $this->assertContains('ship', $enum);
        $this->assertNotContains('on-table', $enum);
    }

    public function test_storefront_mcp_places_a_ship_order_for_a_goods_store(): void
    {
        $store = $this->store('retail');
        $item = $this->item($store);
        $raw = $this->agentToken($store);

        $res = $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'create_order',
            'arguments' => ['items' => [['item_id' => $item->id, 'quantity' => 1]], 'type' => 'ship', 'address' => '1 Test St'],
        ]])->assertOk();

        $this->assertNull($res->json('result.isError'), 'ship order must not be refused for a retail store');
        $this->assertStringContainsString('track_token', (string) $res->json('result.content.0.text'));
        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'type' => 'ship']);
    }

    public function test_restaurant_info_advertises_vertical_order_types(): void
    {
        $store = $this->store('retail');
        $raw = $this->agentToken($store);

        $text = $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'restaurant_info', 'arguments' => [],
        ]])->assertOk()->json('result.content.0.text');

        $info = json_decode((string) $text, true);
        $this->assertSame('retail', $info['store_type']);
        $this->assertContains('ship', $info['order_types']);
        $this->assertNotContains('on-table', $info['order_types']);
    }

    // ── P1: goods attrs on the read tools ───────────────────────────────────────

    public function test_search_menu_returns_goods_attributes_for_a_non_food_store(): void
    {
        $store = $this->store('retail');
        $this->item($store, ['name' => 'Widget', 'track_inventory' => 1, 'stock_qty' => 7]);
        $raw = $this->agentToken($store);

        $text = $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [
            'name' => 'search_menu', 'arguments' => ['query' => 'Widget'],
        ]])->assertOk()->json('result.content.0.text');

        $rows = json_decode((string) $text, true);
        $this->assertSame(7, $rows[0]['stock']);
        $this->assertTrue($rows[0]['in_stock']);
        $this->assertArrayNotHasKey('is_vegan', $rows[0]);
    }

    public function test_get_item_exposes_otc_fields_for_a_pharmacy(): void
    {
        $store = $this->store('pharmacy');
        $item = $this->item($store, ['age_restricted' => true, 'expiry_date' => now()->addYear()->toDateString()]);
        $raw = $this->agentToken($store);

        $text = $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => [
            'name' => 'get_item', 'arguments' => ['item_id' => $item->id],
        ]])->assertOk()->json('result.content.0.text');

        $data = json_decode((string) $text, true);
        $this->assertTrue($data['age_restricted']);
        $this->assertArrayHasKey('expiry_date', $data);
    }

    public function test_admin_get_menu_exposes_fitment_for_car_parts(): void
    {
        $store = $this->store('carparts');
        $item = $this->item($store);
        VehicleFitment::create([
            'menu_id' => $item->id, 'store_id' => $store->id,
            'year' => 2016, 'make' => 'Honda', 'model' => 'Civic',
        ]);

        $text = $this->adminRpc($store->user, 'get_menu', ['store_id' => $store->id])
            ->assertOk()->json('result.content.0.text');

        $menu = json_decode((string) $text, true);
        $this->assertSame(1, $menu[0]['items'][0]['fitment_count']);
    }
}
