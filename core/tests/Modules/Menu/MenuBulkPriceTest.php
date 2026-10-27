<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * D5 — bulk price set / percent-adjust / tag toggles in menuBulkAction, rounded
 * to the store's currency precision and ownership-scoped.
 */
class MenuBulkPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    private function storeWithItems(User $owner, int $n = 2, float $price = 10.0): array
    {
        $t = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        $items = [];
        for ($i = 0; $i < $n; $i++) {
            $items[] = Menu::create(['store_id' => $store->id, 'name' => "I$i", 'price' => $price, 'active' => 1]);
        }

        return [$store, $items];
    }

    public function test_price_set_writes_price_to_all_selected(): void
    {
        $owner = User::factory()->create();
        [$store, $items] = $this->storeWithItems($owner);

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'price_set', 'price' => 5.5, 'ids' => collect($items)->pluck('id')->all(),
        ])->assertOk()->assertJson(['success' => true]);

        foreach ($items as $it) {
            $this->assertEqualsWithDelta(5.5, (float) $it->fresh()->price, 0.001);
        }
    }

    public function test_price_set_rounds_to_three_decimals_for_gulf_currency(): void
    {
        $owner = User::factory()->create();
        [$store, $items] = $this->storeWithItems($owner);
        StoreSettings::set($store->id, 'currency_code', 'OMR');

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'price_set', 'price' => 1.2375, 'ids' => [$items[0]->id],
        ])->assertOk();

        // 3-decimal currency keeps fils: 1.238 (not rounded to 1.24)
        $this->assertEqualsWithDelta(1.238, (float) $items[0]->fresh()->price, 0.0001);
    }

    public function test_percent_adjust_multiplies_each_price(): void
    {
        $owner = User::factory()->create();
        [$store, $items] = $this->storeWithItems($owner, 2, 20.0);

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'price_adjust_percent', 'percent' => 10, 'ids' => collect($items)->pluck('id')->all(),
        ])->assertOk();

        foreach ($items as $it) {
            $this->assertEqualsWithDelta(22.0, (float) $it->fresh()->price, 0.01);
        }
    }

    public function test_tag_set_and_clear_toggle_the_flag(): void
    {
        $owner = User::factory()->create();
        [$store, $items] = $this->storeWithItems($owner);
        $ids = collect($items)->pluck('id')->all();

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'tag_set', 'tag' => 'is_popular', 'ids' => $ids,
        ])->assertOk();
        $this->assertSame(1, (int) $items[0]->fresh()->is_popular);

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'tag_clear', 'tag' => 'is_popular', 'ids' => $ids,
        ])->assertOk();
        $this->assertSame(0, (int) $items[0]->fresh()->is_popular);
    }

    public function test_foreign_items_are_not_touched(): void
    {
        $owner = User::factory()->create();
        [$store, $items] = $this->storeWithItems($owner);

        $attacker = User::factory()->create();
        [$aPost, $aItems] = $this->storeWithItems($attacker, 1, 10.0);

        // Attacker posts our item id at their own store → ownership scope drops it.
        $this->actingAs($attacker)->post(route('stores.menuBulk', $aPost->id), [
            'action' => 'price_set', 'price' => 1, 'ids' => [$items[0]->id],
        ]);

        $this->assertEqualsWithDelta(10.0, (float) $items[0]->fresh()->price, 0.001);
    }
}
