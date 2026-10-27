<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    private function owner(): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Resto', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function token(User $user, array $abilities): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => $abilities]);

        return $raw;
    }

    private function rpc(string $raw, string $method, array $params = [], int $id = 1)
    {
        return $this->postJson('/mcp',
            ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params],
            ['Authorization' => 'Bearer '.$raw]
        );
    }

    /**
     * FIX-6 F6-1: `list_customers` / `get_customer` now declare
     * `feature => crm_suite`, so they are hidden from tools/list and refused by
     * tools/call unless the tenant holds it. `crm_suite` defaults to OFF in the
     * registry, so the tests that exercise those two tools have to say so — the
     * same flip a superadmin makes in Feature Controls before selling the SKU.
     * The key is left NOT plan_gated here, so only the kill lane applies:
     * FeaturePlatformFix6CrmGateTest owns the entitlement lane.
     */
    private function enableCrmSuite(): void
    {
        config(['settings.admin_allow_crm_suite' => 1]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    public function test_requires_a_token(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401);
    }

    public function test_initialize_returns_server_info(): void
    {
        [$user] = $this->owner();
        $this->rpc($this->token($user, ['read']), 'initialize')
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'juwri-mcp');
    }

    public function test_read_token_lists_only_read_tools(): void
    {
        [$user] = $this->owner();
        $res = $this->rpc($this->token($user, ['read']), 'tools/list');
        $names = collect($res->json('result.tools'))->pluck('name');

        $this->assertTrue($names->contains('list_restaurants'));
        $this->assertFalse($names->contains('update_order_status'));
    }

    public function test_write_token_exposes_write_tools(): void
    {
        [$user] = $this->owner();
        $res = $this->rpc($this->token($user, ['read', 'write']), 'tools/list');
        $names = collect($res->json('result.tools'))->pluck('name');

        $this->assertTrue($names->contains('update_order_status'));
        $this->assertTrue($names->contains('add_menu_item'));
    }

    public function test_list_restaurants_is_owner_scoped(): void
    {
        [$user, $store] = $this->owner();
        [$other, $otherPost] = $this->owner();

        $res = $this->rpc($this->token($user, ['read']), 'tools/call', ['name' => 'list_restaurants', 'arguments' => []]);
        $text = $res->json('result.content.0.text');
        $ids = collect(json_decode($text, true))->pluck('id');

        $this->assertTrue($ids->contains($store->id));
        $this->assertFalse($ids->contains($otherPost->id));
    }

    public function test_cannot_read_another_owners_restaurant(): void
    {
        [$user] = $this->owner();
        [, $victimPost] = $this->owner();

        $res = $this->rpc($this->token($user, ['read']), 'tools/call',
            ['name' => 'get_menu', 'arguments' => ['store_id' => $victimPost->id]]);

        $this->assertSame(-32004, $res->json('error.code'));
    }

    public function test_mutating_tool_blocked_without_write_ability(): void
    {
        [$user, $store] = $this->owner();
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        $res = $this->rpc($this->token($user, ['read']), 'tools/call',
            ['name' => 'update_order_status', 'arguments' => ['store_id' => $store->id, 'order_id' => $order->id, 'status' => 'ready']]);

        $this->assertSame(-32001, $res->json('error.code'));
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_write_token_can_update_order_status(): void
    {
        [$user, $store] = $this->owner();
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        $res = $this->rpc($this->token($user, ['read', 'write']), 'tools/call',
            ['name' => 'update_order_status', 'arguments' => ['store_id' => $store->id, 'order_id' => $order->id, 'status' => 'ready']]);

        $res->assertOk();
        $this->assertStringContainsString('ready', $res->json('result.content.0.text'));
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_add_menu_item_creates_and_is_scoped(): void
    {
        [$user, $store] = $this->owner();

        $res = $this->rpc($this->token($user, ['read', 'write']), 'tools/call',
            ['name' => 'add_menu_item', 'arguments' => ['store_id' => $store->id, 'name' => 'Latte', 'price' => 4.5]]);

        $res->assertOk();
        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Latte', 'price_minor' => 450]);
    }

    public function test_unknown_method_returns_error(): void
    {
        [$user] = $this->owner();
        $this->rpc($this->token($user, ['read']), 'bogus/method')
            ->assertOk()->assertJsonPath('error.code', -32601);
    }

    // --- P1-2 category tools ----------------------------------------------

    private function callMcp(string $raw, string $name, array $args)
    {
        return $this->rpc($raw, 'tools/call', ['name' => $name, 'arguments' => $args]);
    }

    private function payload($res): array
    {
        return json_decode((string) $res->json('result.content.0.text'), true);
    }

    public function test_category_crud_over_mcp(): void
    {
        [$user, $store] = $this->owner();
        $write = $this->token($user, ['read', 'write']);

        // add
        $add = $this->callMcp($write, 'add_category', ['store_id' => $store->id, 'name' => 'Drinks']);
        $add->assertOk();
        $catId = $this->payload($add)['id'];
        $this->assertDatabaseHas('categories', ['id' => $catId, 'store_id' => $store->id, 'name' => 'Drinks']);

        // list (menu:read granular token can see it)
        $list = $this->callMcp($this->token($user, ['read']), 'list_categories', ['store_id' => $store->id]);
        $names = collect($this->payload($list))->pluck('name');
        $this->assertTrue($names->contains('Drinks'));

        // update
        $this->callMcp($write, 'update_category', ['store_id' => $store->id, 'category_id' => $catId, 'name' => 'Beverages'])->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $catId, 'name' => 'Beverages']);

        // delete (empty → clean)
        $del = $this->callMcp($write, 'delete_category', ['store_id' => $store->id, 'category_id' => $catId]);
        $del->assertOk();
        $this->assertTrue($this->payload($del)['deleted']);
        $this->assertDatabaseMissing('categories', ['id' => $catId]);
    }

    public function test_delete_category_refuses_when_items_exist(): void
    {
        [$user, $store] = $this->owner();
        $write = $this->token($user, ['read', 'write']);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 9, 'active' => 1]);

        $res = $this->callMcp($write, 'delete_category', ['store_id' => $store->id, 'category_id' => $cat->id]);
        $this->assertSame(-32006, $res->json('error.code'));
        $this->assertDatabaseHas('categories', ['id' => $cat->id]); // nothing deleted
    }

    public function test_category_write_blocked_without_menu_write(): void
    {
        [$user, $store] = $this->owner();
        // a menu:read-only token cannot add a category
        $res = $this->callMcp($this->token($user, ['read']), 'add_category', ['store_id' => $store->id, 'name' => 'X']);
        $this->assertSame(-32001, $res->json('error.code'));
    }

    public function test_category_scoped_to_owner(): void
    {
        [$user, $store] = $this->owner();
        [$other, $otherPost] = $this->owner();
        $victimCat = MenuCategory::create(['store_id' => $otherPost->id, 'name' => 'Secret', 'active' => 1]);

        // user tries to update another owner's category via their own store_id → not found
        $res = $this->callMcp($this->token($user, ['read', 'write']), 'update_category', ['store_id' => $store->id, 'category_id' => $victimCat->id, 'name' => 'Hacked']);
        $this->assertSame(-32004, $res->json('error.code'));
        $this->assertDatabaseHas('categories', ['id' => $victimCat->id, 'name' => 'Secret']);
    }

    // --- P1-3 item tools --------------------------------------------------

    public function test_set_item_availability_toggles_sold_out(): void
    {
        [$user, $store] = $this->owner();
        $write = $this->token($user, ['read', 'write']);
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Taco', 'price' => 3, 'active' => 1, 'is_sold_out' => 0]);

        $out = $this->callMcp($write, 'set_item_availability', ['store_id' => $store->id, 'item_id' => $item->id, 'available' => false]);
        $out->assertOk();
        $this->assertFalse($this->payload($out)['available']);
        $this->assertSame(1, (int) $item->fresh()->is_sold_out);

        $in = $this->callMcp($write, 'set_item_availability', ['store_id' => $store->id, 'item_id' => $item->id, 'available' => true]);
        $this->assertTrue($this->payload($in)['available']);
        $this->assertSame(0, (int) $item->fresh()->is_sold_out);
    }

    public function test_delete_menu_item_removes_and_is_scoped(): void
    {
        [$user, $store] = $this->owner();
        [, $otherPost] = $this->owner();
        $write = $this->token($user, ['read', 'write']);
        $mine = Menu::create(['store_id' => $store->id, 'name' => 'Mine', 'price' => 5, 'active' => 1]);
        $theirs = Menu::create(['store_id' => $otherPost->id, 'name' => 'Theirs', 'price' => 5, 'active' => 1]);

        $del = $this->callMcp($write, 'delete_menu_item', ['store_id' => $store->id, 'item_id' => $mine->id]);
        $del->assertOk();
        $this->assertTrue($this->payload($del)['deleted']);
        // D4: menu delete is now a soft delete (reversible via Trash).
        $this->assertSoftDeleted('items', ['id' => $mine->id]);

        // cannot delete another owner's item via own store_id → not found, item survives
        $bad = $this->callMcp($write, 'delete_menu_item', ['store_id' => $store->id, 'item_id' => $theirs->id]);
        $this->assertSame(-32004, $bad->json('error.code'));
        $this->assertDatabaseHas('items', ['id' => $theirs->id]);
    }

    public function test_item_tools_blocked_without_menu_write(): void
    {
        [$user, $store] = $this->owner();
        $item = Menu::create(['store_id' => $store->id, 'name' => 'X', 'price' => 1, 'active' => 1]);
        $res = $this->callMcp($this->token($user, ['read']), 'delete_menu_item', ['store_id' => $store->id, 'item_id' => $item->id]);
        $this->assertSame(-32001, $res->json('error.code'));
        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    // --- P1-4 order tools -------------------------------------------------

    public function test_get_order_returns_lines_and_is_scoped(): void
    {
        [$user, $store] = $this->owner();
        [, $otherPost] = $this->owner();
        $read = $this->token($user, ['read']);
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Pasta', 'price' => 8, 'active' => 1]);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'customer_name' => 'Ann']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2, 'status' => 'queued']);

        $res = $this->callMcp($read, 'get_order', ['store_id' => $store->id, 'order_id' => $order->id]);
        $res->assertOk();
        $data = $this->payload($res);
        $this->assertSame('Ann', $data['customer_name']);
        $this->assertCount(1, $data['items']);
        $this->assertSame('Pasta', $data['items'][0]['name']);
        $this->assertSame(2, $data['items'][0]['quantity']);

        // another owner's order id via own restaurant → not found
        $victim = Order::create(['store_id' => $otherPost->id, 'status' => 'new', 'type' => 'takeaway']);
        $bad = $this->callMcp($read, 'get_order', ['store_id' => $store->id, 'order_id' => $victim->id]);
        $this->assertSame(-32004, $bad->json('error.code'));
    }

    public function test_list_orders_filters_by_search_and_date(): void
    {
        [$user, $store] = $this->owner();
        $read = $this->token($user, ['read']);
        $alice = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'customer_name' => 'Alice', 'phone_number' => '111']);
        $bob = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'customer_name' => 'Bob', 'phone_number' => '222']);

        // search by name
        $byName = $this->payload($this->callMcp($read, 'list_orders', ['store_id' => $store->id, 'search' => 'Alice']));
        $ids = collect($byName)->pluck('id');
        $this->assertTrue($ids->contains($alice->id));
        $this->assertFalse($ids->contains($bob->id));

        // search by phone
        $byPhone = $this->payload($this->callMcp($read, 'list_orders', ['store_id' => $store->id, 'search' => '222']));
        $this->assertTrue(collect($byPhone)->pluck('id')->contains($bob->id));

        // date_from in the future → empty
        $future = $this->payload($this->callMcp($read, 'list_orders', ['store_id' => $store->id, 'date_from' => now()->addDay()->toDateString()]));
        $this->assertCount(0, $future);
    }

    // --- P1-5 customer + analytics tools ----------------------------------

    public function test_customer_tools_list_get_and_scope(): void
    {
        $this->enableCrmSuite(); // F6-1: the CRM tools are gated on crm_suite
        [$user, $store] = $this->owner();
        [, $otherPost] = $this->owner();
        $read = $this->token($user, ['read']);
        $mine = Customer::create(['store_id' => $store->id, 'name' => 'Zoe', 'phone' => '555', 'email' => 'zoe@x.co']);
        $theirs = Customer::create(['store_id' => $otherPost->id, 'name' => 'Rob', 'phone' => '666']);

        // list + search
        $list = $this->payload($this->callMcp($read, 'list_customers', ['store_id' => $store->id, 'search' => 'Zoe']));
        $ids = collect($list)->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));

        // get
        $get = $this->payload($this->callMcp($read, 'get_customer', ['store_id' => $store->id, 'customer_id' => $mine->id]));
        $this->assertSame('Zoe', $get['name']);

        // cannot read another owner's customer via own restaurant → not found
        $bad = $this->callMcp($read, 'get_customer', ['store_id' => $store->id, 'customer_id' => $theirs->id]);
        $this->assertSame(-32004, $bad->json('error.code'));
    }

    public function test_sales_report_reuses_analytics_service(): void
    {
        [$user, $store] = $this->owner();
        $read = $this->token($user, ['read']);

        $res = $this->callMcp($read, 'sales_report', ['store_id' => $store->id]);
        $res->assertOk();
        $data = $this->payload($res);
        // shape from SalesAnalyticsService::summary
        $this->assertArrayHasKey('revenue', $data);
        $this->assertArrayHasKey('orders', $data);
        $this->assertArrayHasKey('top_items', $data);
        $this->assertArrayHasKey('gross', $data['revenue']);
    }

    // --- P1-6 settings tools ----------------------------------------------

    public function test_get_and_update_business_hours(): void
    {
        [$user, $store] = $this->owner();
        $write = $this->token($user, ['read', 'write']);

        // read snapshot
        $snap = $this->payload($this->callMcp($write, 'get_restaurant_settings', ['store_id' => $store->id]));
        $this->assertSame($store->id, $snap['id']);
        $this->assertArrayHasKey('business_hours', $snap);
        $this->assertArrayHasKey('ordering', $snap);

        // write hours
        $upd = $this->callMcp($write, 'update_business_hours', ['store_id' => $store->id, 'business_hours' => 'Mon-Sat 9-23, Sun closed']);
        $upd->assertOk();
        $this->assertSame('Mon-Sat 9-23, Sun closed', $this->payload($upd)['business_hours']);
        $this->assertSame('Mon-Sat 9-23, Sun closed', $store->fresh()->timing);
    }

    public function test_update_business_hours_needs_restaurants_write(): void
    {
        [$user, $store] = $this->owner();
        // read-only token → -32001 (write gated)
        $res = $this->callMcp($this->token($user, ['read']), 'update_business_hours', ['store_id' => $store->id, 'business_hours' => 'x']);
        $this->assertSame(-32001, $res->json('error.code'));
    }

    // --- P1-8 tools/list pin + docs ---------------------------------------

    /**
     * Pins the full owner MCP tool set (write token sees all). An accidental
     * removal or rename fails CI here. Update deliberately when adding a tool.
     *
     * F6-1: "sees all" now also means "is entitled to all" — crm_suite is on
     * for this pin so the list stays the complete catalogue.
     * test_tools_list_hides_feature_gated_tools below pins the other side.
     */
    public function test_tools_list_name_set_is_pinned(): void
    {
        $this->enableCrmSuite();
        [$user] = $this->owner();
        $res = $this->rpc($this->token($user, ['read', 'write']), 'tools/list');
        $names = collect($res->json('result.tools'))->pluck('name')->sort()->values()->all();

        $expected = collect([
            'list_restaurants', 'get_menu', 'get_analytics',
            'list_categories', 'add_category', 'update_category', 'delete_category',
            'add_menu_item', 'update_menu_item', 'delete_menu_item', 'set_item_availability',
            'list_orders', 'get_order', 'update_order_status',
            'list_customers', 'get_customer', 'sales_report',
            'get_restaurant_settings', 'update_business_hours',
            'list_coupons', 'create_coupon', 'update_coupon', 'delete_coupon',
            // rev/mv-surface: vertical read tools (gated multi_vertical; visible in
            // this env because no features row makes the key plan-gate here).
            'list_brands', 'list_variants', 'list_shipments', 'list_fitments', 'list_expiring_items',
            // rev/mv-surface: vertical WRITE tools (gated multi_vertical, write ability).
            'add_variant', 'update_variant', 'delete_variant',
        ])->sort()->values()->all();

        $this->assertSame($expected, $names);
    }

    /**
     * F6-1, the other side of the pin: with crm_suite OFF (the registry
     * default) a write token sees the SAME catalogue minus exactly the two
     * feature-gated tools — nothing else moves.
     */
    public function test_tools_list_hides_feature_gated_tools(): void
    {
        [$user] = $this->owner();
        $names = collect($this->rpc($this->token($user, ['read', 'write']), 'tools/list')->json('result.tools'))
            ->pluck('name');

        $this->assertFalse($names->contains('list_customers'));
        $this->assertFalse($names->contains('get_customer'));
        $this->assertTrue($names->contains('sales_report'), 'only the CRM tools are gated');
        // The two crm_suite tools drop out (config-gated); the multi_vertical read
        // tools stay visible here (no features row → not plan-gated in this env).
        $this->assertCount(29, $names, 'exactly the two CRM tools should drop out');
    }

    public function test_developers_page_lists_admin_mcp_tools(): void
    {
        // /developers is now translated (781342f8); the Locale middleware resolves
        // from session('locale'), so pin English to assert the English labels rather
        // than couple the test to a locale's translation text.
        $html = $this->withSession(['locale' => 'en'])->get(route('developers'))->assertOk()->getContent();
        $this->assertStringContainsString('MCP server (Admin)', $html);
        $this->assertStringContainsString('set_item_availability', $html);
        $this->assertStringContainsString('sales_report', $html);
        $this->assertStringContainsString('create_coupon', $html);
    }

    public function test_developers_page_has_five_server_overview(): void
    {
        $html = $this->withSession(['locale' => 'en'])->get(route('developers'))->assertOk()->getContent();
        // P4-2: one overview table listing all five MCP servers by endpoint.
        foreach (['/mcp', '/mcp/storefront', '/mcp/customer', '/mcp/catalog', '/mcp/dev'] as $ep) {
            $this->assertStringContainsString(url($ep), $html);
        }
        $this->assertStringContainsString('MCP servers', $html);
    }

    // --- P1-9 coupon tools (decision A) -----------------------------------

    public function test_coupon_crud_over_mcp(): void
    {
        [$user] = $this->owner();
        $write = $this->token($user, ['read', 'write']);

        // create
        $add = $this->callMcp($write, 'create_coupon', ['code' => 'save10', 'coupon_type' => 'percentage', 'value' => 10, 'limit' => 50]);
        $add->assertOk();
        $coupon = $this->payload($add);
        $this->assertSame('SAVE10', $coupon['code'], 'code is uppercased');
        $this->assertEquals(10, $coupon['value']);
        $this->assertDatabaseHas('coupons', ['id' => $coupon['id'], 'user_id' => $user->id, 'coupon_type' => 'percentage']);

        // list
        $list = collect($this->payload($this->callMcp($write, 'list_coupons', [])))->pluck('code');
        $this->assertTrue($list->contains('SAVE10'));

        // update
        $this->callMcp($write, 'update_coupon', ['coupon_id' => $coupon['id'], 'value' => 25])->assertOk();
        $this->assertEquals(25, (float) Coupon::find($coupon['id'])->percentage);

        // delete
        $del = $this->callMcp($write, 'delete_coupon', ['coupon_id' => $coupon['id']]);
        $del->assertOk();
        $this->assertTrue($this->payload($del)['deleted']);
        $this->assertDatabaseMissing('coupons', ['id' => $coupon['id']]);
    }

    /** BL-036 C7: the agent tools refuse an impossible or past expiry like the owner form; unchanged stays accepted. */
    public function test_coupon_tools_reject_impossible_or_past_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        try {
            [$user] = $this->owner();
            $write = $this->token($user, ['read', 'write']);

            foreach (['12026-03-12', '2031-02-30', '2026-10-26'] as $bad) {
                $res = $this->callMcp($write, 'create_coupon', ['code' => 'bad'.Str::random(4), 'coupon_type' => 'percentage', 'value' => 5, 'expiry_at' => $bad]);
                $this->assertSame(-32602, $res->json('error.code'), $bad);
            }
            $this->assertSame(0, Coupon::where('user_id', $user->id)->count());

            $ok = $this->payload($this->callMcp($write, 'create_coupon', ['code' => 'today', 'coupon_type' => 'percentage', 'value' => 5, 'expiry_at' => '2026-10-27']));
            $this->assertSame('2026-10-27', $ok['expiry_at']);
            $this->assertSame('2026-10-27 23:59:59', Coupon::find($ok['id'])->expiry_at->format('Y-m-d H:i:s'), 'valid to the end of the day');

            $old = Coupon::create(['code' => 'OLD', 'coupon_type' => 'percentage', 'percentage' => 5, 'limit' => 10, 'used' => 0, 'user_id' => $user->id, 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => Carbon::parse('2026-09-01 23:59:59', 'UTC')]);
            $this->callMcp($write, 'update_coupon', ['coupon_id' => $old->id, 'value' => 7, 'expiry_at' => '2026-09-01'])->assertOk()->assertJsonMissingPath('error');
            $this->assertEquals(7, (float) $old->fresh()->percentage);
            $this->assertSame(-32602, $this->callMcp($write, 'update_coupon', ['coupon_id' => $old->id, 'expiry_at' => '2026-09-02'])->json('error.code'));

            // BL-036 re-review 5: the unchanged date keeps the stored moment (no re-stamp to end of day).
            $odd = Coupon::create(['code' => 'ODD', 'coupon_type' => 'percentage', 'percentage' => 5, 'limit' => 10, 'used' => 0, 'user_id' => $user->id, 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => Carbon::parse('2026-11-30 10:15:00', 'UTC')]);
            $this->callMcp($write, 'update_coupon', ['coupon_id' => $odd->id, 'value' => 6, 'expiry_at' => '2026-11-30'])->assertOk()->assertJsonMissingPath('error');
            $this->assertSame('2026-11-30 10:15:00', $odd->fresh()->expiry_at->format('Y-m-d H:i:s'));
            $this->callMcp($write, 'update_coupon', ['coupon_id' => $odd->id, 'expiry_at' => '2026-12-01'])->assertOk();
            $this->assertSame('2026-12-01 23:59:59', $odd->fresh()->expiry_at->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_create_coupon_rejects_duplicate_code(): void
    {
        [$user] = $this->owner();
        $write = $this->token($user, ['read', 'write']);
        Coupon::create(['code' => 'DUP', 'coupon_type' => 'percentage', 'percentage' => 5, 'limit' => 10, 'used' => 0, 'user_id' => $user->id, 'issuer_scope' => 'owner', 'applies_to' => 'food_order']);

        $res = $this->callMcp($write, 'create_coupon', ['code' => 'dup', 'coupon_type' => 'percentage', 'value' => 5]);
        $this->assertSame(-32602, $res->json('error.code'));
    }

    public function test_coupon_tools_owner_scoped_and_write_gated(): void
    {
        [$user] = $this->owner();
        [$other] = $this->owner();
        $victim = Coupon::create(['code' => 'THEIRS', 'coupon_type' => 'percentage', 'percentage' => 5, 'limit' => 10, 'used' => 0, 'user_id' => $other->id, 'issuer_scope' => 'owner', 'applies_to' => 'food_order']);

        // can't edit another owner's coupon
        $bad = $this->callMcp($this->token($user, ['read', 'write']), 'update_coupon', ['coupon_id' => $victim->id, 'value' => 99]);
        $this->assertSame(-32004, $bad->json('error.code'));

        // read-only token can't create
        $gated = $this->callMcp($this->token($user, ['read']), 'create_coupon', ['code' => 'X1', 'coupon_type' => 'fixed', 'value' => 2]);
        $this->assertSame(-32001, $gated->json('error.code'));
    }
}
