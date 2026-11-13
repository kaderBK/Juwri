<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/** MCP-expansion P3: Customer Account MCP (JSON-RPC) — a diner's own orders. */
class CustomerMcpTest extends TestCase
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

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return $store;
    }

    /** @return array{0:Customer,1:string} customer + raw token */
    private function customer(Store $store, string $name = 'Dana'): array
    {
        // Monotonic phone so two customers in one restaurant never collide on the
        // UNIQUE(store_id, phone) key. The old random 4-digit suffix flaked
        // under --parallel (a repeated draw within a test = a 23000 violation).
        // Each parallel worker has its own :memory: DB, so a per-process counter
        // is enough for uniqueness.
        static $phoneSeq = 0;
        $c = Customer::create(['store_id' => $store->id, 'name' => $name, 'phone' => '55'.str_pad((string) ++$phoneSeq, 7, '0', STR_PAD_LEFT)]);
        $raw = CustomerToken::issue($c);

        return [$c, $raw];
    }

    private function rpc(?string $raw, array $body, array $headers = [])
    {
        if ($raw) {
            $headers['Authorization'] = 'Bearer '.$raw;
        }

        return $this->postJson(route('mcp.customer'), $body, $headers);
    }

    public function test_requires_a_customer_token(): void
    {
        $this->postJson(route('mcp.customer'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertStatus(401);
    }

    public function test_initialize_returns_customer_server_info(): void
    {
        $store = $this->storeFixture();
        [, $raw] = $this->customer($store);
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'juwri-customer-mcp')
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_protocol_negotiation_and_bad_header(): void
    {
        $store = $this->storeFixture();
        [, $raw] = $this->customer($store);
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-03-26');
        $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], ['MCP-Protocol-Version' => 'bogus'])
            ->assertStatus(400);
    }

    public function test_route_is_csrf_exempt(): void
    {
        $prop = new \ReflectionProperty(PreventRequestForgery::class, 'except');
        $prop->setAccessible(true);
        $this->assertContains('mcp/customer', $prop->getDefaultValue());
    }

    private function callTool(string $raw, string $name, array $args = [])
    {
        return $this->rpc($raw, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
    }

    private function payload($res): array
    {
        return json_decode((string) $res->json('result.content.0.text'), true);
    }

    private function order(Store $store, Customer $c, string $status = 'new', ?string $track = null): Order
    {
        $item = Menu::where('store_id', $store->id)->first();
        $o = Order::create([
            'store_id' => $store->id, 'customer_id' => $c->id, 'customer_name' => $c->name,
            'phone_number' => $c->phone, 'status' => $status, 'type' => 'takeaway',
            'track_token' => $track ?? Str::random(24),
        ]);
        OrderItem::create(['order_id' => $o->id, 'item_id' => $item->id, 'quantity' => 1, 'status' => 'queued']);

        return $o;
    }

    // --- P3-2 read tools --------------------------------------------------

    public function test_my_orders_lists_only_own_orders(): void
    {
        $store = $this->storeFixture();
        [$me, $raw] = $this->customer($store, 'Me');
        [$other] = $this->customer($store, 'Other');
        $mine = $this->order($store, $me);
        $theirs = $this->order($store, $other);

        $ids = collect($this->payload($this->callTool($raw, 'my_orders')))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), 'must never list another customer order');
    }

    public function test_order_detail_own_and_foreign(): void
    {
        $store = $this->storeFixture();
        [$me, $raw] = $this->customer($store, 'Me');
        [$other] = $this->customer($store, 'Other');
        $mine = $this->order($store, $me);
        $theirs = $this->order($store, $other);

        $detail = $this->payload($this->callTool($raw, 'order_detail', ['order_id' => $mine->id]));
        $this->assertSame($mine->id, $detail['id']);
        $this->assertArrayHasKey('items', $detail);

        // another customer's order id → not found (no existence leak)
        $this->assertSame(-32004, $this->callTool($raw, 'order_detail', ['order_id' => $theirs->id])->json('error.code'));
    }

    public function test_track_order_by_id_and_token_scoped(): void
    {
        $store = $this->storeFixture();
        [$me, $raw] = $this->customer($store, 'Me');
        [$other] = $this->customer($store, 'Other');
        $mine = $this->order($store, $me, 'preparing', 'MYTOKEN123');
        $theirs = $this->order($store, $other, 'new', 'THEIRTOKEN9');

        $byId = $this->payload($this->callTool($raw, 'track_order', ['order_id' => $mine->id]));
        $this->assertSame('preparing', $byId['status']);

        $byToken = $this->payload($this->callTool($raw, 'track_order', ['track_token' => 'MYTOKEN123']));
        $this->assertSame($mine->id, $byToken['id']);

        // another customer's track token → not found (isolation)
        $this->assertSame(-32004, $this->callTool($raw, 'track_order', ['track_token' => 'THEIRTOKEN9'])->json('error.code'));
    }

    // --- P3-3 action tools ------------------------------------------------

    public function test_reorder_builds_draft_and_skips_sold_out(): void
    {
        $store = $this->storeFixture();
        [$me, $raw] = $this->customer($store, 'Me');
        $keep = Menu::where('store_id', $store->id)->first();
        $goneStock = Menu::create(['store_id' => $store->id, 'name' => 'Gone', 'price' => 4, 'active' => 1, 'is_sold_out' => 1]);

        $order = Order::create(['store_id' => $store->id, 'customer_id' => $me->id, 'status' => 'completed', 'type' => 'takeaway', 'track_token' => Str::random(20)]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $keep->id, 'quantity' => 2, 'status' => 'queued']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $goneStock->id, 'quantity' => 1, 'status' => 'queued']);

        $draft = $this->payload($this->callTool($raw, 'reorder', ['order_id' => $order->id]));
        $this->assertSame($order->id, $draft['from_order']);
        $ids = collect($draft['items'])->pluck('item_id');
        $this->assertTrue($ids->contains($keep->id));
        $this->assertFalse($ids->contains($goneStock->id), 'sold-out item must be skipped');
    }

    public function test_reorder_foreign_order_is_not_found(): void
    {
        $store = $this->storeFixture();
        [, $raw] = $this->customer($store, 'Me');
        [$other] = $this->customer($store, 'Other');
        $theirs = $this->order($store, $other);
        $this->assertSame(-32004, $this->callTool($raw, 'reorder', ['order_id' => $theirs->id])->json('error.code'));
    }

    public function test_my_profile_returns_own_details(): void
    {
        $store = $this->storeFixture();
        [$me, $raw] = $this->customer($store, 'Dana');
        $profile = $this->payload($this->callTool($raw, 'my_profile'));
        $this->assertSame('Dana', $profile['name']);
        $this->assertSame($me->phone, $profile['phone']);
        $this->assertArrayHasKey('orders_count', $profile);
    }

    // --- P3-5 isolation (the #1 review axis) ------------------------------

    /**
     * A customer can NEVER read another customer's order — and a non-existent id
     * and another customer's id return the IDENTICAL error (-32004), so the
     * response never leaks whether an order exists.
     */
    public function test_no_existence_leak_same_error_for_foreign_and_missing(): void
    {
        $store = $this->storeFixture();
        [, $rawA] = $this->customer($store, 'A');
        [$b] = $this->customer($store, 'B');
        $bOrder = $this->order($store, $b);
        $missingId = $bOrder->id + 999999;

        foreach (['order_detail', 'reorder'] as $tool) {
            $foreign = $this->callTool($rawA, $tool, ['order_id' => $bOrder->id])->json('error.code');
            $missing = $this->callTool($rawA, $tool, ['order_id' => $missingId])->json('error.code');
            $this->assertSame(-32004, $foreign, "$tool: foreign order must be -32004");
            $this->assertSame($missing, $foreign, "$tool: foreign and missing must be indistinguishable");
        }
    }

    public function test_customer_cannot_reach_another_restaurants_order(): void
    {
        $postA = $this->storeFixture();
        $postB = $this->storeFixture();
        [, $rawA] = $this->customer($postA, 'A');
        [$bCust] = $this->customer($postB, 'B');
        $bOrder = $this->order($postB, $bCust);

        // A (restaurant A) probes an order id that lives in restaurant B → -32004
        $this->assertSame(-32004, $this->callTool($rawA, 'order_detail', ['order_id' => $bOrder->id])->json('error.code'));
        $this->assertSame(-32004, $this->callTool($rawA, 'track_order', ['order_id' => $bOrder->id])->json('error.code'));
        $this->assertSame(-32004, $this->callTool($rawA, 'track_order', ['track_token' => $bOrder->track_token])->json('error.code'));
    }

    public function test_tool_args_never_override_token_identity(): void
    {
        $store = $this->storeFixture();
        [, $rawA] = $this->customer($store, 'A');
        [$b] = $this->customer($store, 'B');
        $bOrder = $this->order($store, $b);

        // Even if a client injects customer_id/phone into the args, identity
        // still comes from the token — B's order stays invisible to A.
        $res = $this->callTool($rawA, 'order_detail', ['order_id' => $bOrder->id, 'customer_id' => $b->id, 'phone' => $b->phone]);
        $this->assertSame(-32004, $res->json('error.code'));
    }

    // --- P3-4 docs --------------------------------------------------------

    public function test_developers_page_documents_customer_mcp(): void
    {
        app()->setLocale('en'); // page copy is ___()-translated; assert the English labels
        $html = $this->get(route('developers'))->assertOk()->getContent();
        $this->assertStringContainsString('Customer Account MCP', $html);
        $this->assertStringContainsString(url('/mcp/customer'), $html);
        $this->assertStringContainsString('customer/otp/verify', $html);
        $this->assertStringContainsString('reorder', $html);
    }
}
