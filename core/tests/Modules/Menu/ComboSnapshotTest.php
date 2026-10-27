<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * PARITY-RUN T6 — the snapshot reader, in isolation.
 *
 * `order_items.options` is the price authority for a combo line, so the code
 * that reads it back must agree with the code that sums it in
 * `Order::getTotalAttribute()` — to the last fils. These are pure array
 * assertions on an unsaved model: no database, no store, no currency.
 */
class ComboSnapshotTest extends TestCase
{
    private function line(array $options, int $qty = 1, ?int $comboId = 7): OrderItem
    {
        $item = new OrderItem();
        $item->combo_id = $comboId;
        $item->quantity = $qty;
        $item->options = $options;

        return $item;
    }

    private function snapshot(): array
    {
        return [
            ['combo' => true, 'combo_id' => 7, 'title' => 'Family Meal', 'selected' => [
                ['option' => 'Family Meal', 'price' => 15.0, 'base' => true],
            ]],
            ['slot_id' => 1, 'title' => 'Choose your main', 'selected' => [
                ['option' => 'Steak', 'price' => 3.5, 'option_id' => 11, 'menu_id' => 21, 'variant_id' => null],
            ]],
            ['slot_id' => 2, 'title' => 'Choose your drink', 'selected' => [
                ['option' => 'Cola (Large)', 'price' => 0.75, 'option_id' => 12, 'menu_id' => 22, 'variant_id' => 5],
            ]],
        ];
    }

    public function test_the_unit_price_is_the_sum_the_total_accessor_would_take(): void
    {
        $line = $this->line($this->snapshot());

        // Bundle 15.00 + steak 3.50 + cola 0.75. This is byte-for-byte the loop in
        // Order::getTotalAttribute(), which is the point of asserting it here.
        $this->assertSame(19.25, round($line->snapshotUnitPrice(), 2));

        $subtotal = 0.0;
        foreach ((array) $line->options as $grp) {
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                $subtotal += (float) ($opt['price'] ?? 0) * $line->quantity;
            }
        }
        $this->assertSame(round($line->snapshotUnitPrice() * $line->quantity, 2), round($subtotal, 2));
    }

    public function test_the_bundle_group_is_split_from_the_components(): void
    {
        $snap = $this->line($this->snapshot())->comboSnapshot();

        $this->assertSame('Family Meal', $snap['name']);
        $this->assertSame(15.0, $snap['base_price']);
        $this->assertCount(2, $snap['components']);
        $this->assertSame('Steak', $snap['components'][0]['name']);
        $this->assertSame('Choose your drink', $snap['components'][1]['slot']);
        $this->assertSame(22, $snap['components'][1]['menu_id']);
        $this->assertSame(5, $snap['components'][1]['variant_id']);
        $this->assertNull($snap['components'][0]['variant_id']);
    }

    public function test_a_combo_line_names_itself_from_the_snapshot_not_from_a_menu_row(): void
    {
        // The whole reason the name is snapshotted: the owner may rename or delete
        // the combo tomorrow, and the ticket must still say what was ordered.
        $this->assertSame('Family Meal', $this->line($this->snapshot())->displayName());
        $this->assertTrue($this->line($this->snapshot())->isCombo());
    }

    public function test_a_normal_line_is_not_a_combo_and_its_snapshot_is_empty(): void
    {
        $line = $this->line([
            ['group_id' => 3, 'title' => 'Sauce', 'selected' => [['option' => 'Garlic', 'price' => 0.5]]],
        ], 2, null);

        $this->assertFalse($line->isCombo());
        $this->assertSame(0.5, round($line->snapshotUnitPrice(), 2));
        // No `combo` group ⇒ nothing is treated as a bundle base.
        $this->assertSame('', $line->comboSnapshot()['name']);
        $this->assertSame(0.0, $line->comboSnapshot()['base_price']);
        $this->assertCount(1, $line->comboSnapshot()['components']);
    }

    public function test_a_line_with_no_options_contributes_nothing(): void
    {
        $line = $this->line([], 3, null);

        $this->assertSame(0.0, $line->snapshotUnitPrice());
        $this->assertSame([], $line->comboSnapshot()['components']);
    }

    public function test_three_decimal_option_prices_survive_the_sum(): void
    {
        $line = $this->line([
            ['combo' => true, 'title' => 'Fils Deal', 'selected' => [['option' => 'Fils Deal', 'price' => 0.275]]],
            ['slot_id' => 1, 'title' => 'Drink', 'selected' => [['option' => 'Tea', 'price' => 0.125]]],
        ], 4);

        // 0.400 × 4 = 1.600 — a 2-decimal round anywhere in this path would have
        // charged 1.60 from 0.28 + 0.13 = 0.41 → 1.64.
        $this->assertSame(0.4, round($line->snapshotUnitPrice(), 3));
        $this->assertSame(1.6, round($line->snapshotUnitPrice() * $line->quantity, 3));
    }
}
