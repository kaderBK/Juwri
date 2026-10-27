<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Models\MenuCategory;
use App\Services\Agents\AgentToolsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * P1-17 (J27-B) characterisation: pins what the agent `suggest_upsell` tool returns TODAY on fixed data,
 * before the call site moves onto the Menu recommendation engine. Must stay green unchanged afterwards.
 */
class UpsellCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $o = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create(['user_id' => $o->id, 'slug' => 'up-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'Up', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function item(Store $s, MenuCategory $c, string $name, float $price, array $extra = []): Menu
    {
        return Menu::create(array_merge(['store_id' => $s->id, 'category_id' => $c->id, 'name' => $name, 'price' => $price, 'active' => '1', 'is_sold_out' => 0], $extra));
    }

    public function test_fixed_menu_output_is_cheapest_three_not_in_cart_active_and_in_stock(): void
    {
        $s = $this->store();
        $c = MenuCategory::create(['store_id' => $s->id, 'name' => 'C']);
        $pizza = $this->item($s, $c, 'Pizza', 30);
        $cola = $this->item($s, $c, 'Cola', 5);
        $water = $this->item($s, $c, 'Water', 2);
        $fries = $this->item($s, $c, 'Fries', 8);
        $this->item($s, $c, 'Gum', 1, ['is_sold_out' => 1]);       // sold out: never suggested
        $this->item($s, $c, 'Hidden', 1, ['active' => '0']);       // inactive: never suggested
        $this->item($s, $c, 'Steak', 60);
        // another store's cheap item never appears
        $s2 = $this->store();
        $c2 = MenuCategory::create(['store_id' => $s2->id, 'name' => 'C']);
        $this->item($s2, $c2, 'Foreign', 0.5);

        $out = AgentToolsService::suggestUpsell($s, [$pizza->id]);
        $this->assertSame([
            ['item_id' => $water->id, 'name' => 'Water', 'price' => 2.0],
            ['item_id' => $cola->id, 'name' => 'Cola', 'price' => 5.0],
            ['item_id' => $fries->id, 'name' => 'Fries', 'price' => 8.0],
        ], $out);
    }

    public function test_cart_items_are_excluded_and_next_cheapest_fill_the_list(): void
    {
        $s = $this->store();
        $c = MenuCategory::create(['store_id' => $s->id, 'name' => 'C']);
        $a = $this->item($s, $c, 'A', 1);
        $b = $this->item($s, $c, 'B', 2);
        $d = $this->item($s, $c, 'D', 3);
        $e = $this->item($s, $c, 'E', 4);

        $this->assertSame([$b->id, $d->id, $e->id], array_column(AgentToolsService::suggestUpsell($s, [$a->id]), 'item_id'));
        $this->assertSame([$a->id, $b->id, $d->id], array_column(AgentToolsService::suggestUpsell($s, []), 'item_id'));
        $this->assertSame([$d->id, $e->id], array_column(AgentToolsService::suggestUpsell($s, [$a->id, $b->id]), 'item_id'));
    }

    public function test_empty_menu_gives_empty_list(): void
    {
        $this->assertSame([], AgentToolsService::suggestUpsell($this->store(), []));
    }
}
