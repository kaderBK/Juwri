<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Data\LineOptionsSnapshot;
use Tests\TestCase;

/**
 * P1-06: the order-line `options` snapshot stores its prices as `price_minor` (the line's currency) and reads with
 * the legacy major `price` added; snapshots written in the old shape (price only) still read and total the same.
 */
class LineOptionsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: Menu} */
    private function order(string $currency): array
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'title' => 'R',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'C', 'phone_number' => '1']);

        return [$order, $item];
    }

    public function test_snapshot_prices_are_stored_in_minor_units_and_read_back_in_major(): void
    {
        [$order, $item] = $this->order('OMR');
        $line = OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2,
            'options' => [['group_id' => 7, 'title' => 'Sauce', 'selected' => [['option' => 'Truffle', 'price' => 0.275], ['option' => 'Plain', 'price' => 0]]]],
        ]);

        $raw = json_decode((string) DB::table('order_items')->where('id', $line->id)->value('options'), true);
        $this->assertSame([['option' => 'Truffle', 'price_minor' => 275], ['option' => 'Plain', 'price_minor' => 0]], $raw[0]['selected']);

        $fresh = OrderItem::find($line->id);
        $this->assertSame(0.275, $fresh->options[0]['selected'][0]['price']);
        $this->assertSame(275, $fresh->options[0]['selected'][0]['price_minor']);
        $this->assertSame('Truffle', $fresh->options[0]['selected'][0]['option']);
        $this->assertSame(0.275, $fresh->snapshotUnitPrice());
        $this->assertSame(0.275, $fresh->toArray()['options'][0]['selected'][0]['price']);

        // a read-modify-write keeps one minor amount (the edited major price wins)
        $options = $fresh->options;
        $options[0]['selected'][0]['price'] = 0.3;
        $fresh->update(['options' => $options]);
        $raw = json_decode((string) DB::table('order_items')->where('id', $line->id)->value('options'), true);
        $this->assertSame(['option' => 'Truffle', 'price_minor' => 300], $raw[0]['selected'][0]);
    }

    public function test_a_legacy_snapshot_reads_and_totals_unchanged(): void
    {
        [$order, $item] = $this->order('USD');
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        DB::table('order_items')->where('id', $line->id)->update([
            'options' => json_encode([['group_id' => 1, 'title' => 'Size', 'selected' => [['option' => 'Large', 'price' => 2.5]]]]),
        ]);

        $fresh = OrderItem::find($line->id);
        $this->assertEquals([['option' => 'Large', 'price' => 2.5]], $fresh->options[0]['selected']); // key order: MySQL JSON / PG jsonb reorder keys
        $this->assertSame(2.5, $fresh->snapshotUnitPrice());
    }

    public function test_combo_components_and_order_totals_use_the_minor_snapshot(): void
    {
        [$order, $item] = $this->order('USD');
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 3,
            'options' => [['group_id' => 1, 'title' => 'Size', 'selected' => [['option' => 'Large', 'price' => 2.5]]]],
        ]);
        $combo = OrderItem::create([
            'order_id' => $order->id, 'item_id' => null, 'quantity' => 1,
            'options' => [
                ['combo' => true, 'title' => 'Meal', 'selected' => [['option' => 'Meal', 'price' => 12]]],
                ['slot_id' => 1, 'title' => 'Drink', 'selected' => [['option' => 'Cola', 'price' => 0.75, 'menu_id' => 5, 'variant_id' => null]]],
            ],
        ]);

        $snap = OrderItem::find($combo->id)->comboSnapshot();
        $this->assertSame('Meal', $snap['name']);
        $this->assertSame(12.0, $snap['base_price']);
        $this->assertSame(0.75, $snap['components'][0]['price']);
        $this->assertSame(5, $snap['components'][0]['menu_id']);

        $this->assertSame(['price_minor' => 250], array_intersect_key(
            json_decode((string) DB::table('order_items')->where('item_id', $item->id)->value('options'), true)[0]['selected'][0],
            ['price_minor' => 1, 'price' => 1],
        ));
    }

    public function test_to_storage_keeps_minor_entries_and_ignores_malformed_groups(): void
    {
        $out = LineOptionsSnapshot::toStorage([
            ['title' => 'A', 'selected' => [['option' => 'x', 'price_minor' => 5], ['option' => 'y', 'price' => '1.005'], ['option' => 'z', 'price' => null]]],
            'not-a-group',
            ['title' => 'B'],
        ], 'USD');

        $this->assertSame([['option' => 'x', 'price_minor' => 5], ['option' => 'y', 'price_minor' => 101], ['option' => 'z', 'price_minor' => 0]], $out[0]['selected']);
        $this->assertSame('not-a-group', $out[1]);
        $this->assertSame(['title' => 'B'], $out[2]);
    }
}
