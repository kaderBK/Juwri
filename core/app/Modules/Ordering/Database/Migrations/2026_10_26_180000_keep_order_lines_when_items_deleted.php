<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order history outlives the menu: `order_items.item_id` cascaded on item delete (and `order_item_extras.extra_id` on
 * modifier delete), so purging a trashed item (`menu:purge-trashed`) or deleting an extra erased the order lines that
 * named it, and with them the order's money history. Both keys now set NULL: the line stays, with no item / extra
 * (its recorded price, quantity and label are kept). Guarded: only a key that is not already SET NULL is replaced.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string}> table, column, foreign table, key name */
    private const KEYS = [
        ['order_items', 'item_id', 'items', 'order_items_item_id_foreign'],
        ['order_item_extras', 'extra_id', 'modifiers', 'order_item_extras_extra_id_foreign'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $column, $on, $name]) {
            $this->replace($table, $column, $on, $name, 'set null');
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as [$table, $column, $on, $name]) {
            $this->replace($table, $column, $on, $name, 'cascade');
        }
    }

    private function replace(string $table, string $column, string $on, string $name, string $rule): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $existing = null;
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column] && $key['foreign_table'] === $on) {
                $existing = $key;

                break;
            }
        }
        if ($existing !== null && strtolower((string) $existing['on_delete']) === $rule) {
            return;
        }
        if ($existing !== null) {
            Schema::table($table, function (Blueprint $t) use ($existing, $column): void {
                // sqlite reports no key names: drop by column there.
                $t->dropForeign(($existing['name'] ?? '') !== '' ? (string) $existing['name'] : [$column]);
            });
        }
        Schema::table($table, function (Blueprint $t) use ($column, $on, $name, $rule): void {
            $key = $t->foreign([$column], $name)->references(['id'])->on($on);
            $rule === 'set null' ? $key->nullOnDelete() : $key->cascadeOnDelete();
        });
    }
};
