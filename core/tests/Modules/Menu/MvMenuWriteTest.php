<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/mv-surface-api P1 — the menu-WRITE surface (REST + MCP) can build a
 * non-food catalog: brand/stock/weight/pricing_unit and pharmacy OTC
 * (age/expiry/batch), with brand ownership enforced. Food stores are untouched.
 */
class MvMenuWriteTest extends TestCase
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
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999],
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

    private function apiToken(User $user): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);

        return $raw;
    }

    private function mcpCall(User $user, string $name, array $args)
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);

        return $this->postJson('/mcp',
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]],
            ['Authorization' => 'Bearer '.$raw]
        );
    }

    // ── REST ─────────────────────────────────────────────────────────────────

    public function test_rest_creates_a_retail_item_with_goods_fields(): void
    {
        $store = $this->store('retail');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $brand = Brand::create(['store_id' => $store->id, 'name' => 'Acme']);

        $res = $this->withToken($this->apiToken($store->user))
            ->postJson(route('api.v1.menu.items.store', $store->id), [
                'name' => 'Bolt', 'price' => 4.5, 'category_id' => $cat->id,
                'brand_id' => $brand->id, 'weight' => 0.25, 'pricing_unit' => 'each',
                'track_inventory' => true, 'stock_qty' => 40,
            ])->assertCreated();

        $res->assertJsonPath('brand_id', $brand->id)
            ->assertJsonPath('stock_qty', 40)
            ->assertJsonPath('weight', 0.25);
        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Bolt', 'brand_id' => $brand->id, 'stock_qty' => 40]);
    }

    public function test_rest_rejects_a_brand_from_another_store(): void
    {
        $store = $this->store('retail');
        $other = $this->store('retail');
        $foreignBrand = Brand::create(['store_id' => $other->id, 'name' => 'NotYours']);

        $this->withToken($this->apiToken($store->user))
            ->postJson(route('api.v1.menu.items.store', $store->id), [
                'name' => 'X', 'price' => 1, 'brand_id' => $foreignBrand->id,
            ])->assertNotFound();
        $this->assertDatabaseMissing('items', ['store_id' => $store->id, 'name' => 'X']);
    }

    // ── MCP ──────────────────────────────────────────────────────────────────

    public function test_mcp_adds_a_pharmacy_item_with_otc_fields(): void
    {
        $store = $this->store('pharmacy');

        $res = $this->mcpCall($store->user, 'add_menu_item', [
            'store_id' => $store->id, 'name' => 'Ibuprofen', 'price' => 6,
            'age_restricted' => true, 'expiry_date' => '2027-01-31', 'sku' => 'IBU-200', 'track_inventory' => true, 'stock_qty' => 12,
        ])->assertOk();

        $this->assertNull($res->json('result.error'));
        $item = Menu::where('store_id', $store->id)->where('name', 'Ibuprofen')->first();
        $this->assertNotNull($item);
        $this->assertTrue((bool) $item->age_restricted);
        $this->assertSame('2027-01-31', $item->expiry_date->toDateString());
        $this->assertSame('IBU-200', $item->plu);
        $this->assertSame(12, (int) $item->stock_qty);
    }

    public function test_mcp_update_sets_stock(): void
    {
        $store = $this->store('retail');
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Widget', 'price' => 3, 'active' => 1]);

        $res = $this->mcpCall($store->user, 'update_menu_item', [
            'store_id' => $store->id, 'item_id' => $item->id, 'track_inventory' => true, 'stock_qty' => 99,
        ])->assertOk();

        $out = json_decode((string) $res->json('result.content.0.text'), true);
        $this->assertSame(99, $out['stock_qty']);
        $this->assertSame(99, (int) $item->fresh()->stock_qty);
    }

    public function test_food_store_ignores_goods_fields_via_mcp(): void
    {
        $store = $this->store('restaurant');

        $this->mcpCall($store->user, 'add_menu_item', [
            'store_id' => $store->id, 'name' => 'Burger', 'price' => 8, 'track_inventory' => true, 'stock_qty' => 5,
        ])->assertOk();

        $item = Menu::where('store_id', $store->id)->where('name', 'Burger')->first();
        // goodsWriteFields() returns nothing for a food store → inventory untouched.
        $this->assertFalse((bool) $item->track_inventory);
        $this->assertNull($item->stock_qty);
    }
}
