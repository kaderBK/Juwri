<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** MCP-expansion P2: public platform Catalog MCP (JSON-RPC), cross-store discovery. */
class CatalogMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function rpc(array $body, array $headers = [])
    {
        return $this->postJson(route('mcp.catalog'), $body, $headers);
    }

    private function callTool(string $name, array $args = [])
    {
        return $this->rpc(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
    }

    private function payload($res): array
    {
        return json_decode((string) $res->json('result.content.0.text'), true);
    }

    /**
     * A catalog-eligible restaurant: active owner + enabled plan.
     * $ownerStatus/$planStatus let a test make it ineligible.
     */
    private function store(string $name, string $address = 'Tripoli', int $ownerStatus = 1, int $planStatus = 1, string $dish = 'Shawarma'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999],
            'status' => $planStatus,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => $ownerStatus, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $name, 'sub_title' => 'Tasty', 'description' => 'Great food',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => $address, 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $dish, 'price' => 8, 'active' => 1]);

        return $store;
    }

    public function test_initialize_returns_catalog_server_info(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'juwri-catalog-mcp')
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_public_no_auth_required(): void
    {
        // No token header at all — must still work (public server).
        $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'search_stores']);
    }

    public function test_protocol_negotiation_and_bad_header(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18');

        $this->rpc(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'], ['MCP-Protocol-Version' => 'bogus'])
            ->assertStatus(400);
    }

    public function test_unknown_method_and_tool_error(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'bogus/method'])
            ->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_route_is_csrf_exempt(): void
    {
        $prop = new \ReflectionProperty(PreventRequestForgery::class, 'except');
        $prop->setAccessible(true);
        $this->assertContains('mcp/catalog', $prop->getDefaultValue());
    }

    // --- P2-2 search_stores -----------------------------------------------

    public function test_search_stores_finds_by_keyword(): void
    {
        $this->store('Falafel House', 'Tripoli');
        $this->store('Pizza Palace', 'Benghazi');

        $out = $this->payload($this->callTool('search_stores', ['query' => 'Falafel']));
        $names = collect($out['stores'])->pluck('name');
        $this->assertTrue($names->contains('Falafel House'));
        $this->assertFalse($names->contains('Pizza Palace'));
    }

    public function test_search_stores_filters_by_city(): void
    {
        $this->store('Alpha Grill', 'Benghazi');
        $this->store('Beta Grill', 'Tripoli');

        $out = $this->payload($this->callTool('search_stores', ['query' => 'Grill', 'city' => 'Benghazi']));
        $names = collect($out['stores'])->pluck('name');
        $this->assertTrue($names->contains('Alpha Grill'));
        $this->assertFalse($names->contains('Beta Grill'));
    }

    public function test_search_stores_excludes_inactive_owner_and_disabled_plan(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $this->store('Live Diner', 'Tripoli');
        $this->store('Dead Owner', 'Tripoli', ownerStatus: 0);
        $this->store('No Plan', 'Tripoli', planStatus: 0);

        $out = $this->payload($this->callTool('search_stores', ['query' => '', 'city' => 'Tripoli', 'limit' => 20]));
        $names = collect($out['stores'])->pluck('name');
        $this->assertTrue($names->contains('Live Diner'));
        $this->assertFalse($names->contains('Dead Owner'), 'inactive owner must not appear');
        $this->assertFalse($names->contains('No Plan'), 'disabled plan must not appear');
    }

    public function test_search_stores_never_leaks_owner_pii(): void
    {
        $this->store('PII Test', 'Tripoli');
        $out = $this->payload($this->callTool('search_stores', ['query' => 'PII']));
        $card = $out['stores'][0];
        // Pin the exact key set — no email, phone, revenue, user_id, id.
        $this->assertSame(
            ['name', 'slug', 'tagline', 'address', 'ordering_enabled', 'menu_url', 'manifest_url', 'storefront_mcp', 'store_type'],
            array_keys($card)
        );
    }

    public function test_search_stores_caps_at_20(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->store("Capme {$i}", 'Tripoli');
        }
        $out = $this->payload($this->callTool('search_stores', ['query' => 'Capme', 'limit' => 50]));
        $this->assertLessThanOrEqual(20, count($out['stores']));
    }

    // --- P2-3 search_items ------------------------------------------------

    public function test_search_items_groups_by_store(): void
    {
        $a = $this->store('Store A', 'Tripoli', dish: 'Chicken Shawarma');
        $b = $this->store('Store B', 'Tripoli', dish: 'Beef Shawarma');
        $this->store('Store C', 'Tripoli', dish: 'Pizza');

        $out = $this->payload($this->callTool('search_items', ['query' => 'Shawarma']));
        $stores = collect($out['stores'])->pluck('store');
        $this->assertTrue($stores->contains('Store A'));
        $this->assertTrue($stores->contains('Store B'));
        $this->assertFalse($stores->contains('Store C'));
        // each store block carries a menu deep link + currency, and item price
        $this->assertArrayHasKey('menu_url', $out['stores'][0]);
        $this->assertArrayHasKey('price', $out['stores'][0]['items'][0]);
    }

    public function test_search_items_dietary_and_max_price(): void
    {
        $veganStore = $this->store('Vegan Spot', 'Tripoli', dish: 'Veg Bowl');
        Menu::where('store_id', $veganStore->id)->get()->each->update(['is_vegan' => 1, 'price' => 5]); // P1-05: price is price_minor
        $meatStore = $this->store('Meat Spot', 'Tripoli', dish: 'Veg Bowl'); // same name, not vegan, pricier
        Menu::where('store_id', $meatStore->id)->get()->each->update(['is_vegan' => 0, 'price' => 40]);

        // dietary filter
        $vegan = $this->payload($this->callTool('search_items', ['query' => 'Veg Bowl', 'dietary' => 'vegan']));
        $this->assertTrue(collect($vegan['stores'])->pluck('store')->contains('Vegan Spot'));
        $this->assertFalse(collect($vegan['stores'])->pluck('store')->contains('Meat Spot'));

        // max_price filter
        $cheap = $this->payload($this->callTool('search_items', ['query' => 'Veg Bowl', 'max_price' => 10]));
        $this->assertTrue(collect($cheap['stores'])->pluck('store')->contains('Vegan Spot'));
        $this->assertFalse(collect($cheap['stores'])->pluck('store')->contains('Meat Spot'));
    }

    public function test_search_items_excludes_invisible_stores(): void
    {
        $this->store('Good Kitchen', 'Tripoli', dish: 'Unique Kofta');
        $this->store('Bad Kitchen', 'Tripoli', ownerStatus: 0, dish: 'Unique Kofta');

        $out = $this->payload($this->callTool('search_items', ['query' => 'Unique Kofta']));
        $stores = collect($out['stores'])->pluck('store');
        $this->assertTrue($stores->contains('Good Kitchen'));
        $this->assertFalse($stores->contains('Bad Kitchen'));
    }

    public function test_search_items_shows_min_variant_price_when_base_is_zero(): void
    {
        $s = $this->store('Variant Diner', 'Tripoli', dish: 'Combo Meal');
        $item = Menu::where('store_id', $s->id)->first();
        $item->update(['price' => 0]); // variant-priced item
        MenuVariant::create(['menu_id' => $item->id, 'price' => 30, 'active' => 1]);
        MenuVariant::create(['menu_id' => $item->id, 'price' => 20, 'active' => 1]);

        $out = $this->payload($this->callTool('search_items', ['query' => 'Combo Meal']));
        $block = collect($out['stores'])->firstWhere('store', 'Variant Diner');
        // "from" price = cheapest variant, not 0
        $this->assertEquals(20, $block['items'][0]['price']);
    }

    public function test_search_items_max_price_uses_effective_variant_price(): void
    {
        $cheap = $this->store('Cheap Variant', 'Tripoli', dish: 'Xy Meal');
        $ci = Menu::where('store_id', $cheap->id)->first();
        $ci->update(['price' => 0]);
        MenuVariant::create(['menu_id' => $ci->id, 'price' => 8, 'active' => 1]);

        $pricey = $this->store('Pricey Variant', 'Tripoli', dish: 'Xy Meal');
        $pi = Menu::where('store_id', $pricey->id)->first();
        $pi->update(['price' => 0]);
        MenuVariant::create(['menu_id' => $pi->id, 'price' => 50, 'active' => 1]);

        $out = $this->payload($this->callTool('search_items', ['query' => 'Xy Meal', 'max_price' => 10]));
        $stores = collect($out['stores'])->pluck('store');
        $this->assertTrue($stores->contains('Cheap Variant'), 'from-8 item within max_price');
        $this->assertFalse($stores->contains('Pricey Variant'), 'from-50 variant item excluded by max_price');
    }

    // --- P2-4 get_store ---------------------------------------------------

    public function test_get_store_by_slug_and_id(): void
    {
        $s = $this->store('Detail Diner', 'Tripoli');
        $s->update(['timing' => 'Daily 10-22']);

        $bySlug = $this->payload($this->callTool('get_store', ['slug' => $s->slug]));
        $this->assertSame('Detail Diner', $bySlug['name']);
        $this->assertSame('Daily 10-22', $bySlug['business_hours']);
        $this->assertSame(['takeaway', 'on-table'], $bySlug['order_types']);
        $this->assertArrayHasKey('track_url_pattern', $bySlug);

        $byId = $this->payload($this->callTool('get_store', ['id' => $s->id]));
        $this->assertSame($s->slug, $byId['slug']);
    }

    public function test_get_store_not_visible_is_not_found(): void
    {
        $hidden = $this->store('Hidden Diner', 'Tripoli', ownerStatus: 0);
        $res = $this->callTool('get_store', ['slug' => $hidden->slug]);
        $this->assertSame(-32004, $res->json('error.code'));
    }

    public function test_get_store_requires_slug_or_id(): void
    {
        $res = $this->callTool('get_store', []);
        $this->assertSame(-32602, $res->json('error.code'));
    }

    public function test_get_store_has_no_owner_pii(): void
    {
        $s = $this->store('Clean Diner', 'Tripoli');
        $detail = $this->payload($this->callTool('get_store', ['slug' => $s->slug]));
        foreach (['email', 'user_id', 'revenue', 'id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $detail);
        }
    }

    // --- P2-5 catalog_visible opt-out -------------------------------------

    public function test_opted_out_store_absent_from_all_tools(): void
    {
        $visible = $this->store('Opt In Diner', 'Tripoli', dish: 'Special Kebab');
        $hidden = $this->store('Opt Out Diner', 'Tripoli', dish: 'Special Kebab');
        $hidden->update(['catalog_visible' => 0]);

        // search_stores
        $stores = collect($this->payload($this->callTool('search_stores', ['query' => 'Diner', 'limit' => 20]))['stores'])->pluck('name');
        $this->assertTrue($stores->contains('Opt In Diner'));
        $this->assertFalse($stores->contains('Opt Out Diner'), 'opted-out store must not appear in search_stores');

        // search_items
        $itemStores = collect($this->payload($this->callTool('search_items', ['query' => 'Special Kebab']))['stores'])->pluck('store');
        $this->assertTrue($itemStores->contains('Opt In Diner'));
        $this->assertFalse($itemStores->contains('Opt Out Diner'), 'opted-out store must not appear in search_items');

        // get_store
        $this->assertSame(-32004, $this->callTool('get_store', ['slug' => $hidden->slug])->json('error.code'));
    }

    public function test_catalog_visible_defaults_on(): void
    {
        $s = $this->store('Default Visible', 'Tripoli');
        // DB default = 1
        $this->assertSame(1, (int) $s->fresh()->catalog_visible);
        $stores = collect($this->payload($this->callTool('search_stores', ['query' => 'Default Visible']))['stores'])->pluck('name');
        $this->assertTrue($stores->contains('Default Visible'));
    }

    // --- P2-6 discovery surfaces + throttle -------------------------------

    public function test_llms_txt_advertises_catalog_mcp(): void
    {
        $body = $this->get(route('llms'))->assertOk()->getContent();
        $this->assertStringContainsString('/mcp/catalog', $body);
        $this->assertStringContainsString('search_items', $body);
    }

    public function test_developers_page_has_catalog_section(): void
    {
        $html = $this->get(route('developers'))->assertOk()->getContent();
        $this->assertStringContainsString('Catalog MCP', $html);
        $this->assertStringContainsString('search_stores', $html);
        $this->assertStringContainsString(url('/mcp/catalog'), $html);
    }

    public function test_manifest_advertises_catalog_mcp(): void
    {
        $s = $this->store('Manifest Diner', 'Tripoli');
        $this->get(route('agent.manifest', $s->id))
            ->assertOk()
            ->assertJsonPath('catalog_mcp', url('/mcp/catalog'));
    }

    public function test_catalog_route_is_throttled(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'mcp.catalog');
        $this->assertNotNull($route);
        $this->assertTrue(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:60,1,')), 'a prefixed throttle:60,1 throttle');
    }
}
