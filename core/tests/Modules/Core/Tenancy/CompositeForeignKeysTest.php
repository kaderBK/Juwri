<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-04 slice 5d: composite (store_id, parent) foreign keys — a child row can never point at another store's parent,
 * and a parent moved to another store takes its children along (ON UPDATE CASCADE).
 * The list mirrors the composite foreign keys the tenancy migrations add.
 */
class CompositeForeignKeysTest extends TestCase
{
    use RefreshDatabase;

    private const COMPOSITE = [
        ['campaign_recipients', 'campaign_id', 'campaigns'],
        ['customer_addresses', 'customer_id', 'customers'],
        ['loyalty_ledger_entries', 'loyalty_account_id', 'loyalty_accounts'],
        ['menu_review_media', 'review_id', 'menu_reviews'],
        ['menu_variants', 'menu_id', 'items'],
        ['order_item_extras', 'order_item_id', 'order_items'],
        ['order_items', 'order_id', 'orders'],
        ['shipments', 'order_id', 'orders'],
    ];

    private function store(): Store
    {
        return Store::create([
            'user_id' => User::factory()->create()->id, 'title' => 'S', 'slug' => 'st-'.Str::lower(Str::random(8)),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    private function item(Store $store): int
    {
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'I', 'price' => 1, 'active' => '1'])->id;
    }

    public function test_every_composite_foreign_key_is_declared(): void
    {
        foreach (self::COMPOSITE as [$child, $fk, $parent]) {
            $found = collect(Schema::getForeignKeys($child))->first(fn (array $key) => $key['columns'] === ['store_id', $fk]
                && $key['foreign_columns'] === ['store_id', 'id']
                && str_ends_with((string) $key['foreign_table'], $parent));
            $this->assertNotNull($found, "{$child} ({$fk}) needs a composite FK to {$parent}(store_id, id)");
            $this->assertSame('cascade', strtolower((string) $found['on_update']), "{$child}: ON UPDATE CASCADE");
        }
    }

    public function test_a_child_cannot_point_at_another_stores_parent(): void
    {
        $a = $this->store();
        $b = $this->store();
        $order = Order::create(['store_id' => $a->id, 'status' => 'new', 'type' => 'takeaway']);

        $menuId = $this->item($a);
        $ok = OrderItem::create(['order_id' => $order->id, 'item_id' => $menuId, 'quantity' => 1]);
        $this->assertSame($a->id, (int) $ok->fresh()->store_id);

        $this->expectException(QueryException::class);
        OrderItem::create(['order_id' => $order->id, 'store_id' => $b->id, 'item_id' => $menuId, 'quantity' => 1]);
    }

    public function test_a_parent_moved_to_another_store_takes_its_children(): void
    {
        $a = $this->store();
        $b = $this->store();
        $order = Order::create(['store_id' => $a->id, 'status' => 'new', 'type' => 'takeaway']);
        $item = OrderItem::create(['order_id' => $order->id, 'item_id' => $this->item($a), 'quantity' => 1]);

        DB::table('orders')->where('id', $order->id)->update(['store_id' => $b->id]);

        $this->assertSame($b->id, (int) DB::table('order_items')->where('id', $item->id)->value('store_id'));
    }
}
