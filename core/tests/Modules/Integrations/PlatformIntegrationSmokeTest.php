<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use Juwri\Core\Models\Store;
use App\Models\StorefrontToken;
use Juwri\Core\Models\User;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * INTEGRATION SMOKE — the suites compose end to end:
 * platform (storefront agent token) → agents (MCP create_order → OrderApiService)
 * → KDS (order becomes a visible ticket) → POS (settle it). Catches the seams the
 * per-suite unit tests miss (e.g. an API order that never reaches the kitchen).
 */
class PlatformIntegrationSmokeTest extends TestCase
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

    public function test_storefront_token_to_agent_order_to_kds_to_pos_settle(): void
    {
        // ── Platform: restaurant with the relevant plan flags + an agent token ──
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'storefront_api' => '1', 'agent_api' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R ' . Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        $raw = Str::random(48);
        StorefrontToken::create(['store_id' => $r->id, 'token' => hash('sha256', $raw), 'scopes' => ['agent'], 'active' => true]);

        // ── Agents: place the order through the MCP endpoint ──
        $res = $this->postJson(route('mcp.storefront'), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_order', 'arguments' => ['items' => [['item_id' => $item->id, 'quantity' => 2]], 'type' => 'takeaway']],
        ], ['X-Storefront-Token' => $raw])->assertOk();

        $payload = json_decode((string) $res->json('result.content.0.text'), true);
        $this->assertTrue($payload['ok'] ?? false, 'agent order placed');
        $order = Order::find($payload['order_id']);

        // Right source + server-side price (12 × 2).
        $this->assertSame('agent', $order->source);
        $this->assertEquals(24, $order->total);

        // ── KDS: the order is a live ticket (mirror the poll query) ──
        $visible = Order::whereIn('status', ['new', 'preparing', 'ready'])
            ->whereNull('held_at')->where('store_id', $r->id)->pluck('id');
        $this->assertTrue($visible->contains($order->id), 'agent order must reach the KDS');

        // ── POS: a cashier settles it in cash ──
        $settled = PosService::settle($order, 'cash', 30.0);
        $this->assertTrue((bool) $settled->is_paid);
        $this->assertEquals(6, $settled->change_due); // 30 − 24
    }
}
