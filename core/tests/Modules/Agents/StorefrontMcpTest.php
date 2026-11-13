<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StorefrontToken;
use App\Services\CustomerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Agents P3: agent-facing MCP (JSON-RPC) over an agent-scoped storefront token. */
class StorefrontMcpTest extends TestCase
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

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'storefront_api' => '1', 'agent_api' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return [$r, $item];
    }

    private function token(Store $r, array $scopes = ['agent']): string
    {
        $raw = Str::random(48);
        StorefrontToken::create(['store_id' => $r->id, 'token' => hash('sha256', $raw), 'scopes' => $scopes, 'active' => true]);

        return $raw;
    }

    private function rpc(string $raw, array $body)
    {
        return $this->postJson(route('mcp.storefront'), $body, ['X-Storefront-Token' => $raw]);
    }

    public function test_tools_list(): void
    {
        [$r] = $this->storeFixture();
        $raw = $this->token($r);
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'search_menu'])
            ->assertJsonFragment(['name' => 'create_order']);
    }

    public function test_tools_call_search_menu(): void
    {
        [$r, $item] = $this->storeFixture();
        $raw = $this->token($r);
        $res = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'search_menu', 'arguments' => ['query' => 'Pizza']]])
            ->assertOk();
        $text = $res->json('result.content.0.text');
        $this->assertStringContainsString('Pizza', (string) $text);
    }

    public function test_tools_call_create_order(): void
    {
        [$r, $item] = $this->storeFixture();
        $raw = $this->token($r);
        $res = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'create_order', 'arguments' => ['items' => [['item_id' => $item->id, 'quantity' => 1]], 'type' => 'takeaway']]])
            ->assertOk();
        $this->assertNull($res->json('result.isError'));
        $this->assertStringContainsString('track_token', (string) $res->json('result.content.0.text'));
    }

    public function test_missing_agent_scope_is_403(): void
    {
        [$r] = $this->storeFixture();
        $raw = $this->token($r, ['menu:read']); // no agent scope
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'])->assertStatus(403);
    }

    public function test_missing_token_is_401(): void
    {
        $this->postJson(route('mcp.storefront'), ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'])->assertStatus(401);
    }

    // Coupons & Wallets P7-2c/P7-2b: coupon flows + store-credit read over MCP.
    public function test_tools_list_includes_store_credit_balance(): void
    {
        [$r] = $this->storeFixture();
        $raw = $this->token($r);
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'store_credit_balance']);
    }

    public function test_tools_call_validate_cart_previews_coupon(): void
    {
        [$r, $item] = $this->storeFixture();
        Coupon::create([
            'code' => 'MCP20', 'percentage' => 20, 'limit' => 5, 'used' => 0,
            'user_id' => $r->user_id, 'coupon_type' => 'percentage',
            'issuer_scope' => 'owner', 'applies_to' => 'food_order',
        ]);
        $raw = $this->token($r);
        $res = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => [
            'name' => 'validate_cart',
            'arguments' => ['items' => [['item_id' => $item->id, 'quantity' => 2]], 'coupon_code' => 'MCP20'],
        ]])->assertOk();
        $out = json_decode((string) $res->json('result.content.0.text'), true);
        $this->assertTrue($out['coupon_applied']);
        $this->assertEqualsWithDelta(4.8, $out['discount'], 0.001, '20% of 24');
    }

    public function test_tools_call_create_order_with_coupon_discounts(): void
    {
        [$r, $item] = $this->storeFixture();
        Coupon::create([
            'code' => 'MCP10', 'percentage' => 10, 'limit' => 5, 'used' => 0,
            'user_id' => $r->user_id, 'coupon_type' => 'percentage',
            'issuer_scope' => 'owner', 'applies_to' => 'food_order',
        ]);
        $raw = $this->token($r);
        $res = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => [
            'name' => 'create_order',
            'arguments' => ['items' => [['item_id' => $item->id, 'quantity' => 2]], 'type' => 'takeaway', 'coupon_code' => 'MCP10'],
        ]])->assertOk();
        $out = json_decode((string) $res->json('result.content.0.text'), true);
        $this->assertTrue($out['coupon_applied']);
        $this->assertEqualsWithDelta(2.4, $out['discount'], 0.001);
    }

    public function test_tools_call_store_credit_balance_no_leak_on_mcp(): void
    {
        [$r] = $this->storeFixture();
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => '777']);
        $acct = CustomerWalletService::accountFor($customer);
        $clr = LedgerService::accountFor('platform', 0, 'platform', $acct->currency);
        LedgerService::post([
            ['account' => $clr, 'amount' => -35, 'allow_negative' => true, 'ref' => "seed:{$customer->id}:clr"],
            ['account' => $acct, 'amount' => 35, 'ref' => "seed:{$customer->id}:usr"],
        ]);
        $raw = $this->token($r);
        // MCP has no verified sender identity — a model-supplied phone must NOT
        // read the balance (PII boundary). Returns found:false.
        $res = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => [
            'name' => 'store_credit_balance', 'arguments' => ['phone' => '777'],
        ]])->assertOk();
        $out = json_decode((string) $res->json('result.content.0.text'), true);
        $this->assertFalse($out['found'], 'MCP balance read must not leak another customer credit');
    }

    // P0-2: suggest_upsell wired into the storefront MCP surface.
    public function test_tools_list_includes_suggest_upsell(): void
    {
        [$r] = $this->storeFixture();
        $raw = $this->token($r);
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'suggest_upsell']);
    }

    public function test_tools_call_suggest_upsell_excludes_cart_items(): void
    {
        [$r, $item] = $this->storeFixture(); // Pizza @ 12
        $fries = Menu::create(['store_id' => $r->id, 'category_id' => $item->category_id, 'name' => 'Fries', 'price' => 4, 'active' => 1]);
        $raw = $this->token($r);
        $res = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => [
            'name' => 'suggest_upsell', 'arguments' => ['cart_item_ids' => [$item->id]],
        ]])->assertOk();
        $this->assertNull($res->json('result.isError'));
        $out = json_decode((string) $res->json('result.content.0.text'), true);
        $ids = array_column($out, 'item_id');
        $this->assertContains($fries->id, $ids, 'suggests an item not in the cart');
        $this->assertNotContains($item->id, $ids, 'never re-suggests an item already in the cart');
    }

    // P0-3: dietary_answer wired into the storefront MCP surface.
    public function test_tools_list_includes_dietary_answer(): void
    {
        [$r] = $this->storeFixture();
        $raw = $this->token($r);
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'dietary_answer']);
    }

    public function test_tools_call_dietary_answer_from_structured_tags(): void
    {
        [$r] = $this->storeFixture();
        $vegan = Menu::create(['store_id' => $r->id, 'name' => 'Salad', 'price' => 6, 'active' => 1, 'dietary_tags' => ['vegan' => true, 'halal' => false]]);
        $raw = $this->token($r);

        $yes = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call', 'params' => [
            'name' => 'dietary_answer', 'arguments' => ['item_id' => $vegan->id, 'tag' => 'vegan'],
        ]])->assertOk();
        $this->assertSame('yes', json_decode((string) $yes->json('result.content.0.text'), true));

        $no = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/call', 'params' => [
            'name' => 'dietary_answer', 'arguments' => ['item_id' => $vegan->id, 'tag' => 'halal'],
        ]])->assertOk();
        $this->assertSame('no', json_decode((string) $no->json('result.content.0.text'), true));

        // no data for the tag → refuse to guess.
        $unknown = $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 15, 'method' => 'tools/call', 'params' => [
            'name' => 'dietary_answer', 'arguments' => ['item_id' => $vegan->id, 'tag' => 'gluten_free'],
        ]])->assertOk();
        $this->assertSame('unknown', json_decode((string) $unknown->json('result.content.0.text'), true));
    }

    // P0-2: the storefront MCP POST endpoint must be CSRF-exempt (bearer-token
    // auth, no session) — otherwise every live JSON-RPC call 419s. The other
    // tests disable CSRF middleware, so pin the exemption directly.
    public function test_storefront_mcp_route_is_csrf_exempt(): void
    {
        $prop = new \ReflectionProperty(PreventRequestForgery::class, 'except');
        $prop->setAccessible(true);
        $except = $prop->getDefaultValue();
        $this->assertContains('mcp/storefront', $except);
    }
}
