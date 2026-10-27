<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/mv-surface-api — variant WRITE over REST + MCP (the piece the menu-write
 * PR deferred). An owner/agent can build a goods item's size×color matrix with
 * per-variant sku/stock/barcode; every action scopes the variant to the owner's
 * item; gated feature:multi_vertical.
 */
class MvVariantWriteTest extends TestCase
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

    private function store(string $type = 'retail'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'store_type' => $type,
            'slug' => Str::slug('s'.Str::random(6)).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'S', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Tee', 'price' => 10, 'active' => 1]);
    }

    private function apiToken(User $user): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);

        return $raw;
    }

    private function mcp(User $user, string $name, array $args)
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);

        return $this->postJson('/mcp',
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]],
            ['Authorization' => 'Bearer '.$raw]
        );
    }

    // ── REST ─────────────────────────────────────────────────────────────────

    public function test_rest_creates_updates_and_deletes_a_variant(): void
    {
        $store = $this->store('retail');
        $item = $this->item($store);
        $tok = $this->apiToken($store->user);

        $created = $this->withToken($tok)->postJson(
            route('api.v1.variants.store', [$store->id, $item->id]),
            ['options' => ['Size' => 'M', 'Color' => 'Red'], 'price' => 14.5, 'sku' => 'TEE-M-RED', 'stock_qty' => 20]
        )->assertCreated();
        $vid = $created->json('id');
        $created->assertJsonPath('sku', 'TEE-M-RED')->assertJsonPath('stock_qty', 20);
        $this->assertDatabaseHas('menu_variants', ['id' => $vid, 'menu_id' => $item->id, 'sku' => 'TEE-M-RED', 'stock_qty' => 20]);

        $this->withToken($tok)->putJson(
            route('api.v1.variants.update', [$store->id, $item->id, $vid]),
            ['price' => 12, 'stock_qty' => 5]
        )->assertOk()->assertJsonPath('price', 12)->assertJsonPath('stock_qty', 5);

        $this->withToken($tok)->deleteJson(route('api.v1.variants.destroy', [$store->id, $item->id, $vid]))
            ->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('menu_variants', ['id' => $vid]);
    }

    public function test_rest_cannot_add_a_variant_to_a_foreign_item(): void
    {
        $store = $this->store('retail');
        $other = $this->store('retail');
        $foreignItem = $this->item($other);

        $this->withToken($this->apiToken($store->user))->postJson(
            route('api.v1.variants.store', [$store->id, $foreignItem->id]),
            ['options' => ['Size' => 'L'], 'price' => 9]
        )->assertNotFound();
        $this->assertDatabaseMissing('menu_variants', ['menu_id' => $foreignItem->id]);
    }

    // ── MCP ──────────────────────────────────────────────────────────────────

    public function test_mcp_add_and_update_variant(): void
    {
        $store = $this->store('retail');
        $item = $this->item($store);

        $add = $this->mcp($store->user, 'add_variant', [
            'store_id' => $store->id, 'item_id' => $item->id,
            'options' => ['Size' => 'S'], 'price' => 8, 'sku' => 'TEE-S', 'stock_qty' => 3,
        ])->assertOk();
        $out = json_decode((string) $add->json('result.content.0.text'), true);
        $this->assertSame('TEE-S', $out['sku']);
        $vid = $out['id'];
        $this->assertDatabaseHas('menu_variants', ['id' => $vid, 'menu_id' => $item->id]);

        $this->mcp($store->user, 'update_variant', ['store_id' => $store->id, 'variant_id' => $vid, 'stock_qty' => 40])
            ->assertOk();
        $this->assertSame(40, (int) MenuVariant::find($vid)->stock_qty);
    }
}
